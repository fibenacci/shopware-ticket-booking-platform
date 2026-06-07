<!--
    Thin SFC shell — the reference pattern for our Vue islands:
    - logic:    composables/useSeatmap.ts (typed, file-separated, testable)
    - template: seat-picker.html (external src block, full Vite HMR)
    - style:    seat-picker.css  (external src block, shadow-DOM injected)
    `<script setup src>` is unsupported by the SFC compiler — the composable
    IS the externalized script.
-->
<script setup lang="ts">
import { useSeatmap } from './composables/useSeatmap';
import type { SelectionPayload } from './composables/useSeatmap';

const props = withDefaults(defineProps<{
    /** Slot (showing) to load the seat map for. */
    slotId: string;
    /** Maximum selectable seats (0 = unlimited). */
    maxSeats?: number | string;
    /** Endpoint template; {slotId} is substituted. */
    endpoint?: string;
    /** Poll interval for live seat states (ms, 0 = off). */
    pollMs?: number | string;
    /** Mercure SSE endpoint (same-origin). Empty = polling only. */
    sseEndpoint?: string;
}>(), {
    maxSeats: 0,
    endpoint: '/fib-booking/seatmap/{slotId}',
    pollMs: 10000,
    sseEndpoint: '/.well-known/mercure',
});

const emit = defineEmits<{
    'seats-change': [payload: SelectionPayload];
}>();

const { seats, selection, loading, error, notice, rows, layout, viewBox, viewBoxAspect, capReached, load, onSeatClick, seatClass, seatFill } = useSeatmap({
    slotId: props.slotId,
    maxSeats: Number(props.maxSeats) || 0,
    endpoint: props.endpoint,
    pollMs: Number(props.pollMs) || 0,
    sseEndpoint: props.sseEndpoint,
    onSelectionChange: (payload) => emit('seats-change', payload),
});

// Re-fetch on demand (e.g. after a lost booking race) — exposed on the element.
defineExpose({ refresh: load });
</script>

<template src="./seat-picker.html"></template>

<style src="./seat-picker.css"></style>
