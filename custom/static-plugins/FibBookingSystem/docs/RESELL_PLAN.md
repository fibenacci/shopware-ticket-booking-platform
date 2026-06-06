# Resell & Auctions (Design)

> Status: **designed, not yet implemented.** This is the agreed blueprint —
> implementation starts at phase 1 without re-opening the architecture
> discussion.

Secondary market for issued tickets: owners list their ticket for resale at
a fixed price **or** as an auction; the platform handles the transfer so the
buyer gets a working ticket and the seller's copy is provably dead.

## The core decision: the TICKET is the tradable good — not stock

The earlier "couldn't we just use product stock?" question resurfaces here,
and resale is exactly where the answer becomes obvious:

- A resale unit is **one specific, identified ticket** — possibly seat F7 of
  one specific showing, possibly a half-used multi-entry pass. Stock counts
  *fungible* units; resale trades *identity*.
- An auction is **price discovery for one identified item**. There is no
  meaningful "stock = 3" for an auction lot.
- The primary market keeps its own capacity truth (slots/claims). Resale
  never changes capacity — the seat stays sold, only its OWNER changes.

So: listings reference `fib_booking_ticket` rows. Product stock stays
neutralized (as in the primary market).

## Domain model

```
fib_booking_listing
    id, ticket_id (FK)                 -- the lot
    seller_customer_id (FK)
    mode            'fixed_price' | 'auction'
    status          'active' | 'sold' | 'cancelled' | 'expired'
    ask_price       (fixed_price: the price; auction: starting bid)
    reserve_price   nullable           -- auction: below this, no sale
    min_increment   (auction)
    ends_at         nullable           -- auction window end (anti-sniping moves it)
    sold_to_customer_id / sold_price / sold_at (settlement snapshot)
    UNIQUE KEY uniq.listing.active_ticket (ticket_id) WHERE-style guard:
        enforced in the service — one LIVE listing per ticket
        (insert-wins via unique key on (ticket_id, status='active') pattern:
        active listings get ticket_id, settled ones keep it — therefore the
        unique key lives on a generated `active_ticket_id` column that is
        NULL unless status='active'; same insert-wins philosophy as seat claims)

fib_booking_bid                        -- append-only audit, like the scan log
    id, listing_id (FK), customer_id (FK), amount, created_at
    KEY (listing_id, amount)
```

## Transfer = ticket re-issue with token rotation (the security core)

A transferred ticket MUST kill the seller's copy — QR screenshot, wallet
pass, PDF, all of it. The mechanism already exists in spirit (revocation +
issuing):

1. Settlement revokes the seller's ticket (`status: revoked`, reusing the
   refund/revocation path — scan-dead immediately).
2. A NEW ticket row is issued for the buyer via the existing
   `BookingTicketService` machinery: fresh scan token, fresh QR, fresh
   wallet ciphers — **carrying over the snapshots** (seat_label, validity
   fields, expiry) and a `replaced_ticket_id` back-reference for the audit
   chain.
3. The buyer's account area / ticket mail works unchanged — it IS just a
   ticket.

Invariant: **no two live tickets for the same claim/snapshot lineage.**
Revoke-then-issue runs in one transaction on the ticket row lock.

## Money flow (the honest part)

This is a marketplace problem, and Shopware is a shop, not a payout engine:

- **Buyer side — solved by Shopware:** settlement creates a standard order
  with a custom line item (type `fib-resale`, dynamic price = sold_price,
  referencing the listing). Payment, invoice, refunds: all stock machinery.
  The transfer (revoke + re-issue) hangs on the payment transition exactly
  like primary tickets do.
- **Seller side — V1 deliberately operator-mediated:** the platform records
  a payout liability (listing settlement snapshot); operators export/settle
  manually or via store credit. Automated split payments (Stripe Connect,
  Adyen for Platforms, PayPal Payouts) are a later phase with real
  compliance weight (KYC!) — explicitly out of V1.
- Operator fee: percentage configurable (plugin config), deducted in the
  payout snapshot.

## Auction mechanics

- English auction: bids must be ≥ current highest + `min_increment`.
- **Concurrency:** one `FOR UPDATE` on the listing row per bid — price
  discovery is inherently serial *per lot*, and lots are independent
  (same per-row philosophy as everything else in this plugin).
- **Anti-sniping:** a bid in the last N minutes (config, default 2) moves
  `ends_at` to now+N — standard soft-close.
- **Settlement task:** the scheduled-task family gets a sibling that closes
  ended auctions: winner ≥ reserve → checkout invitation (mail with TTL'd
  claim, hold-like); no/low bids → `expired`, optional auto-relist. Winner
  doesn't pay within TTL → offer second bidder or relist (config).
- Bids via Store API, rate-limited (existing limiter family), customers only.
- Live bid updates reuse the Mercure path: topic `fib-booking/listing/{id}`
  published after bid commit (same defer/flush publisher pattern).

## Guardrails

- Listable only: status issued/sent, not expired, not revoked, and (slot
  tickets) before a cutoff relative to slot start (`resaleCutoffMinutes`).
- Seller must own the ticket (account-area listing flow only).
- Price bounds: optional operator cap (`resaleMaxFactor`, e.g. 1.2× face
  value — anti-scalping is a regulatory topic in several markets).
- Every state change appends to the existing audit philosophy (bid log is
  append-only; listing settlement is a snapshot).

## Storefront

- Account area "My tickets": list/cancel listing per ticket.
- Listing pages (fixed price: buy button → cart; auction: bid form +
  live current price via SSE).
- V1 keeps this in Twig + the established vanilla/island split; a bid
  widget is a natural second Vue island once needed.

## Phases

1. **Core**: migration (listing, bid), listing service (create/cancel with
   guardrails, unique-active-per-ticket), transfer primitive
   (revoke + re-issue with token rotation) + tests.
2. **Fixed price end-to-end**: custom line item type, settlement on payment,
   account-area listing UI, buyer flow.
3. **Auctions**: bid route (lock + increment + anti-sniping), settlement
   task, winner checkout flow + tests.
4. **Live updates**: Mercure topics for listings, bid widget island.
5. **Payout automation** (Stripe Connect et al.) — separate decision, KYC.
