<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { groupRows, reconcileSelection, toggleSeat } from './selection.js';

const props = defineProps({
    /** Slot (showing) to load the seat map for. */
    slotId: { type: String, required: true },
    /** Maximum selectable seats (0 = unlimited). */
    maxSeats: { type: [Number, String], default: 0 },
    /** Endpoint template; {slotId} is substituted. */
    endpoint: { type: String, default: '/fib-booking/seatmap/{slotId}' },
    /** Poll interval for live seat states (ms, 0 = off). */
    pollMs: { type: [Number, String], default: 10000 },
});

const emit = defineEmits(['seats-change']);

const seats = ref([]);
const selection = ref([]);
const loading = ref(true);
const error = ref('');
const notice = ref('');

let pollTimer = null;

const max = computed(() => Number(props.maxSeats) || 0);
const rows = computed(() => groupRows(seats.value));
const capReached = computed(() => max.value > 0 && selection.value.length >= max.value);

onMounted(async () => {
    await load();

    const interval = Number(props.pollMs) || 0;
    if (interval > 0) {
        pollTimer = setInterval(load, interval);
    }
});

onBeforeUnmount(() => {
    if (pollTimer) {
        clearInterval(pollTimer);
    }
});

async function load() {
    try {
        const response = await fetch(props.endpoint.replace('{slotId}', props.slotId), {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error(`seatmap request failed (${response.status})`);
        }

        const data = await response.json();
        seats.value = Array.isArray(data.seats) ? data.seats : [];
        error.value = '';

        const result = reconcileSelection(seats.value, selection.value);
        if (result.lost.length > 0) {
            notice.value = `No longer available: ${result.lost.join(', ')}`;
            selection.value = result.selection;
            emitChange();
        }
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

function onSeatClick(seat) {
    if (seat.state !== 'free') {
        return;
    }

    notice.value = '';
    const next = toggleSeat(selection.value, seat.id, max.value);

    if (next === selection.value) {
        notice.value = `Maximum of ${max.value} seats reached.`;
        return;
    }

    selection.value = next;
    emitChange();
}

function emitChange() {
    const labels = seats.value
        .filter((seat) => selection.value.includes(seat.id))
        .map((seat) => `${seat.row}${seat.label}`);

    emit('seats-change', { seatIds: [...selection.value], labels });
}

function seatClass(seat) {
    if (selection.value.includes(seat.id)) {
        return 'is-selected';
    }

    return `is-${seat.state}`;
}

// Re-fetch on demand (e.g. after a lost booking race) — exposed on the element.
defineExpose({ refresh: load });
</script>

<template>
    <div class="picker" role="group" aria-label="Seat selection">
        <p v-if="loading" class="muted">Loading seats…</p>
        <p v-else-if="error" class="error" role="alert">{{ error }}</p>

        <template v-else>
            <div class="screen" aria-hidden="true">SCREEN</div>

            <div class="grid">
                <div v-for="(row, index) in rows" :key="index" class="row">
                    <span class="row-label">{{ row[0]?.row }}</span>
                    <button
                        v-for="seat in row"
                        :key="seat.id"
                        type="button"
                        class="seat"
                        :class="seatClass(seat)"
                        :disabled="seat.state !== 'free' && !selection.includes(seat.id)"
                        :title="`${seat.row}${seat.label}`"
                        :aria-pressed="selection.includes(seat.id)"
                        @click="onSeatClick(seat)"
                    >
                        {{ seat.label }}
                    </button>
                </div>
            </div>

            <div class="legend">
                <span><i class="dot is-free"></i> free</span>
                <span><i class="dot is-held"></i> held</span>
                <span><i class="dot is-sold"></i> sold</span>
                <span><i class="dot is-selected"></i> yours</span>
            </div>

            <p v-if="notice" class="notice" role="status">{{ notice }}</p>
            <p v-if="capReached" class="muted">Seat limit reached — deselect to change.</p>
        </template>
    </div>
</template>

<style>
/* Shadow-DOM scoped. Theme tokens pierce via CSS custom properties — every
   var() carries a standalone fallback so the element works on any host. */
.picker {
    font-family: var(--fib-font, system-ui, sans-serif);
    color: var(--fib-text, #e8ecf4);
}

.screen {
    margin: 0 auto 1rem;
    width: 70%;
    padding: 0.25rem 0;
    text-align: center;
    font-size: 0.7rem;
    letter-spacing: 0.35em;
    color: var(--fib-text-dim, rgba(232, 236, 244, 0.6));
    border-bottom: 3px solid var(--fib-aurora-two, #22d3ee);
    border-radius: 0 0 50% 50% / 0 0 12px 12px;
    opacity: 0.8;
}

.grid {
    display: grid;
    gap: 0.4rem;
    justify-content: center;
}

.row {
    display: flex;
    gap: 0.4rem;
    align-items: center;
}

.row-label {
    width: 1.4rem;
    font-size: 0.75rem;
    color: var(--fib-text-dim, rgba(232, 236, 244, 0.6));
    text-align: center;
}

.seat {
    width: 2.1rem;
    height: 2.1rem;
    border-radius: 0.5rem 0.5rem 0.7rem 0.7rem;
    border: 1px solid rgba(255, 255, 255, 0.16);
    background: rgba(255, 255, 255, 0.06);
    color: var(--fib-text, #e8ecf4);
    font-size: 0.7rem;
    cursor: pointer;
    transition: transform 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
}

.seat.is-free:hover {
    transform: translateY(-2px);
    background: rgba(255, 255, 255, 0.14);
}

.seat.is-held {
    background: rgba(251, 191, 36, 0.18);
    border-color: rgba(251, 191, 36, 0.5);
    color: rgba(253, 230, 138, 0.9);
    cursor: not-allowed;
}

.seat.is-sold {
    background: rgba(220, 38, 38, 0.16);
    border-color: rgba(248, 113, 113, 0.45);
    color: rgba(253, 164, 175, 0.8);
    cursor: not-allowed;
}

.seat.is-selected {
    background: linear-gradient(135deg, var(--fib-aurora-one, #6366f1), var(--fib-aurora-two, #22d3ee));
    border-color: transparent;
    color: #fff;
    box-shadow: 0 4px 14px rgba(99, 102, 241, 0.45);
}

.legend {
    display: flex;
    gap: 1rem;
    justify-content: center;
    margin-top: 1rem;
    font-size: 0.75rem;
    color: var(--fib-text-dim, rgba(232, 236, 244, 0.6));
}

.dot {
    display: inline-block;
    width: 0.7rem;
    height: 0.7rem;
    border-radius: 0.2rem;
    vertical-align: -1px;
    border: 1px solid rgba(255, 255, 255, 0.16);
    background: rgba(255, 255, 255, 0.06);
}

.dot.is-held {
    background: rgba(251, 191, 36, 0.4);
}

.dot.is-sold {
    background: rgba(220, 38, 38, 0.4);
}

.dot.is-selected {
    background: linear-gradient(135deg, var(--fib-aurora-one, #6366f1), var(--fib-aurora-two, #22d3ee));
}

.notice {
    margin-top: 0.75rem;
    color: #fbbf24;
    font-size: 0.85rem;
}

.error {
    color: #f87171;
}

.muted {
    color: var(--fib-text-dim, rgba(232, 236, 244, 0.6));
    font-size: 0.85rem;
}
</style>
