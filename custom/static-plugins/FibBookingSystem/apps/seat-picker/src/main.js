import { defineCustomElement } from 'vue';
import SeatPicker from './SeatPicker.ce.vue';

/**
 * Registers <fib-seat-picker slot-id="…" max-seats="…"> once. The element
 * emits a `seats-change` CustomEvent ({ seatIds, labels }) — the calendar
 * widget (vanilla) listens and feeds the hold request.
 */
if (!customElements.get('fib-seat-picker')) {
    customElements.define('fib-seat-picker', defineCustomElement(SeatPicker));
}
