# Booking Calendar (CMS Element)

Customer-facing calendar for booking tickets on operator-defined dates
("Termine"). Built as a **Shopware CMS element**, so operators decide where it
appears: drag the "Booking calendar" block onto any shopping-experience
layout (category *Commerce*) and pick the booking resource in the element
config.

## Concept

```
Resource (e.g. "Demo Event Hall")
  ├─ Slots ("Termine"): concrete date+time windows, EACH with its own capacity
  │    2026-07-01 18:00–20:00  capacity 20
  │    2026-07-01 20:30–22:30  capacity 20
  │    …
  └─ Packages: products sharing this resource via fib_booking_product_config
       Starter Package   (FIB-PKG-STARTER)
       Premium Package   (FIB-PKG-PREMIUM)
       Luxury Package    (FIB-PKG-LUXURY)
```

- **Slots** are the bookable dates. When a resource has slots, bookings are
  accepted **only on a slot** and the slot's capacity wins (resources without
  slots keep the free-form window behaviour).
- **Day colors**: green = free, amber = partially booked, **red = sold out**
  (`status: full` — every slot of the day has 0 remaining), muted = no slots.
- **Packages** are normal Shopware products configured to the same resource —
  they share the slot capacity, differ in price/content, and go through the
  regular checkout (hold → cart → order → reservation → ticket).

## Operator workflow

1. Create a resource + slots:

   ```bash
   bin/console fib-booking:slots:generate \
       --resource=fib_demo_event_hall \
       --from=2026-07-01 --to=2026-07-31 \
       --times=18:00,20:30 --duration=120 \
       --capacity=20 --weekdays=Fri,Sat
   ```

   Slots are upserted on (resource, start) — re-running adjusts capacity.
   Fine-grained management: Admin API CRUD under `/api/fib-booking-slot`.

2. Configure package products: link each product to the resource via
   `fib_booking_product_config` (Admin API `/api/fib-booking-product-config`).

3. Place the CMS element: Shopping Experiences → layout → add the
   **Booking calendar** block → select the resource → assign the layout.

## Technical pieces

| Piece | Location |
|---|---|
| Slot entity (DAL) | `Core/Content/BookingSlot/` + migration `1719000000` |
| Slot-aware availability | `Core/Domain/Availability/AvailabilityService` |
| Month/calendar service | `Core/Domain/Availability/BookingCalendarService` |
| Store API route | `POST /store-api/fib-booking/calendar` (`Core/Content/Booking/SalesChannel/BookingCalendarRoute`) |
| Storefront JSON wrapper | `POST /fib-booking/calendar` |
| CMS element resolver | `Core/Content/Cms/FibBookingCalendarCmsElementResolver` |
| Admin element/block | `Resources/app/administration/src/module/sw-cms/` |
| Storefront element template | `Resources/views/storefront/element/cms-element-fib-booking-calendar.html.twig` |
| Calendar JS plugin | `Resources/app/storefront/src/plugins/fib-booking-calendar/` |
| Slot generator command | `bin/console fib-booking:slots:generate` |

The calendar endpoint is rate-limited (read bucket) and `no-store`; booking
itself reuses the hardened hold → cart flow (see SECURITY.md).
