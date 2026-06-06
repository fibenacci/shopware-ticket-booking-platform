# Seat Picker — `<fib-seat-picker>`

Vue 3 island as a framework-agnostic custom element: rendered inside a
shadow root, lazy-loaded by the storefront calendar widget only on seatmap
resources, portable to any future (headless) frontend.

## Reference structure for our Vue islands (TypeScript, separated files)

```
src/
├── SeatPicker.ce.vue          ← thin shell (~40 lines): typed props/emits wiring
├── seat-picker.html           ← template  (<template src>, full Vite HMR)
├── seat-picker.css            ← style     (<style src>, shadow-DOM injected)
├── composables/useSeatmap.ts  ← ALL behavior: typed, file-separated, testable
├── selection.ts               ← pure logic (vitest-covered)
└── main.ts                    ← custom element registration
```

Why this exact split:

- **`<template src>` / `<style src>`** are first-class SFC features — Vite
  watches the external files and hot-reloads them like inline blocks.
- **`<script setup src>` does not exist** (SFC compiler limitation). The
  Vue-idiomatic externalized script is a **composable**: `useSeatmap.ts`
  holds every ref, effect and handler; the SFC only wires props/emits.
  Bonus over a hypothetical external script: composables are directly
  importable in tests.
- **TypeScript without killing HMR:** the dev server (`npm run dev`)
  transpiles TS with esbuild — types are stripped, never checked, HMR stays
  instant. The real type check (`vue-tsc --noEmit`) is a separate gate:
  `npm run check`, and `npm run build` runs it before bundling (CI does the
  same via the lint job).

## Scripts

```bash
npm run dev     # vite dev server (fast, no type-check)
npm run check   # vue-tsc --noEmit
npm run build   # type-check + bundle → ../../src/Resources/public/seat-picker/
npm run test    # vitest (pure logic)
```

The built IIFE is committed (like the other plugin dist artifacts) and
served via `/bundles/fibbookingsystem/seat-picker/fib-seat-picker.js`.

## Behavior

- States `free / held / sold / selected` from
  `GET /fib-booking/seatmap/{slotId}`; selection capped at `max-seats`.
- Live updates: Mercure SSE poke → re-fetch (never trusts the push payload);
  polling stays as safety net (60s with SSE open, `poll-ms` otherwise).
- Poll reconciliation drops seats lost to other buyers and tells the user
  which ones (`No longer available: F7`).
- Emits `seats-change` (`{ seatIds, labels }`) — consumed by the vanilla
  calendar widget, which derives the booking quantity from it.
