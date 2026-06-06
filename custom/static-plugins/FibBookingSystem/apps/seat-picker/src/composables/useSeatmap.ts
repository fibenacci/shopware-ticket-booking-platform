import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { groupRows, reconcileSelection, toggleSeat } from '../selection';
import type { Seat } from '../selection';

export interface SelectionPayload {
    seatIds: string[];
    labels: string[];
}

export interface UseSeatmapOptions {
    slotId: string;
    /** 0 = unlimited. */
    maxSeats: number;
    /** Endpoint template; {slotId} is substituted. */
    endpoint: string;
    /** Poll interval (ms, 0 = off). Slows to a safety net while SSE is open. */
    pollMs: number;
    /** Mercure SSE endpoint (same-origin). Empty = polling only. */
    sseEndpoint: string;
    onSelectionChange: (payload: SelectionPayload) => void;
}

export interface UseSeatmap {
    seats: Ref<Seat[]>;
    selection: Ref<string[]>;
    loading: Ref<boolean>;
    error: Ref<string>;
    notice: Ref<string>;
    rows: ComputedRef<Seat[][]>;
    capReached: ComputedRef<boolean>;
    load: () => Promise<void>;
    onSeatClick: (seat: Seat) => void;
    seatClass: (seat: Seat) => string;
}

const SSE_SAFETY_NET_MS = 60000;

/**
 * The seat picker's whole behavior, framework-idiomatically separated from
 * the SFC (script-setup blocks cannot live in external files — composables
 * are the Vue-blessed way to get typed, testable, file-separated logic).
 *
 * Live updates via Mercure SSE (docs/SEATING_PLAN.md phase 5): every push is
 * only a poke — the composable re-fetches the seatmap read model, so SSE can
 * never show state the database doesn't have. No hub → polling stays primary.
 */
export function useSeatmap(options: UseSeatmapOptions): UseSeatmap {
    const seats = ref<Seat[]>([]);
    const selection = ref<string[]>([]);
    const loading = ref(true);
    const error = ref('');
    const notice = ref('');

    let pollTimer: ReturnType<typeof setInterval> | null = null;
    let eventSource: EventSource | null = null;

    const rows = computed(() => groupRows(seats.value));
    const capReached = computed(
        () => options.maxSeats > 0 && selection.value.length >= options.maxSeats,
    );

    onMounted(async () => {
        await load();
        subscribeLive();
        startPolling(options.pollMs);
    });

    onBeforeUnmount(() => {
        stopPolling();
        eventSource?.close();
        eventSource = null;
    });

    async function load(): Promise<void> {
        try {
            const response = await fetch(options.endpoint.replace('{slotId}', options.slotId), {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error(`seatmap request failed (${response.status})`);
            }

            const data: { seats?: Seat[] } = await response.json();
            seats.value = Array.isArray(data.seats) ? data.seats : [];
            error.value = '';

            const result = reconcileSelection(seats.value, selection.value);
            if (result.lost.length > 0) {
                notice.value = `No longer available: ${result.lost.join(', ')}`;
                selection.value = result.selection;
                emitChange();
            }
        } catch (e) {
            error.value = e instanceof Error ? e.message : String(e);
        } finally {
            loading.value = false;
        }
    }

    function onSeatClick(seat: Seat): void {
        if (seat.state !== 'free') {
            return;
        }

        notice.value = '';
        const next = toggleSeat(selection.value, seat.id, options.maxSeats);

        if (next === selection.value) {
            notice.value = `Maximum of ${options.maxSeats} seats reached.`;
            return;
        }

        selection.value = next;
        emitChange();
    }

    function seatClass(seat: Seat): string {
        if (selection.value.includes(seat.id)) {
            return 'is-selected';
        }

        return `is-${seat.state}`;
    }

    function emitChange(): void {
        const labels = seats.value
            .filter((seat) => selection.value.includes(seat.id))
            .map((seat) => `${seat.row}${seat.label}`);

        options.onSelectionChange({ seatIds: [...selection.value], labels });
    }

    function startPolling(interval: number): void {
        stopPolling();
        if (interval > 0) {
            pollTimer = setInterval(load, interval);
        }
    }

    function stopPolling(): void {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function subscribeLive(): void {
        if (!options.sseEndpoint || typeof EventSource === 'undefined') {
            return;
        }

        try {
            const url = `${options.sseEndpoint}?topic=${encodeURIComponent(`fib-booking/seatmap/${options.slotId}`)}`;
            eventSource = new EventSource(url);

            eventSource.onopen = () => startPolling(SSE_SAFETY_NET_MS);
            eventSource.onmessage = () => {
                void load();
            };
            eventSource.onerror = () => {
                eventSource?.close();
                eventSource = null;
                startPolling(options.pollMs);
            };
        } catch {
            // EventSource construction failed — polling stays primary.
        }
    }

    return { seats, selection, loading, error, notice, rows, capReached, load, onSeatClick, seatClass };
}
