# Calendar invite (.ics) in the confirmation email

When a booking is confirmed, the ticket email carries an **iCalendar (.ics)
invite** for the booked time slot. The customer can add it to their calendar
and — when the shop has an email address configured — **accept or decline** it
right from the mail (Outlook / Apple Mail / Gmail show the RSVP buttons).

![Confirmation email with the .ics calendar invite attached](screenshots/07-email-calendar-invite.jpeg)

*The confirmation mail (Mailpit) for ticket T10813 — alongside the ticket PDF
it now carries `booking-B10030.ics`, the calendar invite.*

## Configurable, optional, per product — default ON

The invite is an **opt-out** feature, **enabled by default**, configured **per
product** under *Product → Booking* (right below the rotating-QR switch):

> ☑ **Calendar invite (.ics) by email** — *Attaches an event for the booked
> time slot to the confirmation email. The customer can add it to their
> calendar — and, when a shop email is configured, accept/decline. Enabled by
> default.*

Like the validity and rotating-QR settings, the flag is **snapshotted onto the
ticket at issue**, so changing the product config never alters already-sold
tickets.

## How it works

1. **`ProductBookingConfig.calendarInviteEnabled`** (`BoolField`, DB default
   `1`) — the operator switch.
2. At issue, `BookingTicketService` reads it (LEFT JOIN; a missing config row
   means default-on) and stamps `calendar_invite_enabled` onto the ticket.
3. `BookingTicketMailSubscriber` reads the snapshot; if enabled it builds the
   `.ics` via `IcsCalendarGenerator` and attaches it next to the ticket PDF
   with mime type `text/calendar; charset=utf-8; method=REQUEST`.

### The .ics (RFC 5545)

`IcsCalendarGenerator` is a pure builder:

- **`METHOD:REQUEST`** with an `ORGANIZER` (the shop's
  `core.basicInformation.email`) and an `ATTENDEE` (the customer,
  `PARTSTAT=NEEDS-ACTION;RSVP=TRUE`) → the mail client shows accept/decline.
  Without a shop email it falls back to `METHOD:PUBLISH` (still "add to
  calendar", no RSVP).
- **Stable `UID`** per booking (`<bookingNumber>@<host>`) so a re-sent mail
  updates the same event instead of duplicating it.
- `DTSTART`/`DTEND` in **UTC**, summary/location from the resource name,
  description with the booking number and quantity.
- RFC-correct CRLF line endings, TEXT escaping, and 75-octet line folding.

A real example (verified end-to-end, fetched from the sent mail):

```text
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//FIB Booking System//Booking Invite//EN
METHOD:REQUEST
BEGIN:VEVENT
UID:B10030@localhost.com
DTSTART:20260616T203000Z
DTEND:20260616T223000Z
SUMMARY:Demo Event Hall
ORGANIZER;CN=Demostore:mailto:doNotReply@localhost.com
ATTENDEE;CN=…;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:…
STATUS:CONFIRMED
END:VEVENT
END:VCALENDAR
```

## Tested by

- `IcsCalendarGeneratorTest` (unit): REQUEST invite with organizer + attendee,
  PUBLISH fallback without an organizer, UTC conversion, RFC 5545 TEXT
  escaping, 75-octet line folding.
- The per-product snapshot rides the existing issuance integration tests
  (`TicketSlotWindowTest`, `TicketScanServiceTest`) — issuing with the new
  column stays green.
- Verified live: issuing ticket T10813 attached `booking-B10030.ics` to the
  confirmation mail (screenshot above).
