# FIB Booking Theme

Glassmorphism storefront theme for the ticketing platform — dark premium
look, CSS only:

- **Aurora background**: three drifting radial gradients (`body::before`,
  blurred, 26s keyframe loop), colors operator-configurable via theme
  manager (`fib-aurora-one/two/three`).
- **Frosted glass surfaces**: one `fib-glass` mixin (translucent background,
  `backdrop-filter: blur + saturate`, hairline border, inner top highlight)
  applied to header, cards, product boxes, modals, offcanvas, dropdowns,
  footer, alerts, cookie bar and the booking calendar.
- **Gradient buttons** with a CSS-only shine sweep on hover; lift/hover
  motion on product boxes and calendar days.
- **Booking calendar**: glass day grid — free days glow cyan, partially
  booked amber, sold out red (`fib-booking-day--*` states from the
  FibBookingSystem CMS element).

## Graceful degradation

- `@supports not (backdrop-filter…)` → solid dark surfaces (readability
  never depends on blur support).
- `prefers-reduced-motion: reduce` → all animation/transition disabled.

## Structure

```
src/Resources/
├── theme.json                       # style order, aurora config fields
└── app/storefront/src/scss/
    ├── overrides.scss               # pre-@Storefront variable overrides (dark palette)
    └── base.scss                    # post-@Storefront glass layer (tokens, mixin, components)
```

`theme.json` loads `overrides.scss` BEFORE `@Storefront` (Bootstrap compiles
against the dark palette) and `base.scss` after it (glass components win the
cascade).

## Activation

Dev (`make up`) and the CI image assign it automatically
(`theme:change --all FibBookingTheme`); production installs it through the
deployment helper like every other extension. Manual:

```bash
bin/console plugin:install --activate FibBookingTheme
bin/console theme:change --all FibBookingTheme
bin/console theme:compile
```
