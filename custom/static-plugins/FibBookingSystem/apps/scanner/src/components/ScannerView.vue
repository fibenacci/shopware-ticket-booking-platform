<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import QrScanner from 'qr-scanner';
import { extractScanToken, scanTicket } from '../api.js';

const emit = defineEmits(['session-expired']);

const video = ref(null);
const cameraError = ref('');
const manualInput = ref('');
const busy = ref(false);
const lastResult = ref(null);
const history = ref([]);

let scanner = null;
let cooldownUntil = 0;
let lastToken = '';

onMounted(async () => {
    try {
        scanner = new QrScanner(video.value, onDecoded, {
            preferredCamera: 'environment',
            highlightScanRegion: true,
            maxScansPerSecond: 4,
        });
        await scanner.start();
    } catch (e) {
        cameraError.value = 'Camera not available — use manual input below. (' + (e?.message ?? e) + ')';
    }
});

onBeforeUnmount(() => {
    scanner?.destroy();
    scanner = null;
});

async function onDecoded(result) {
    // Debounce: ignore repeats of the same code and rapid-fire frames.
    const now = Date.now();
    const token = extractScanToken(result.data);

    if (!token || busy.value || now < cooldownUntil || token === lastToken) {
        return;
    }

    cooldownUntil = now + 1500;
    lastToken = token;
    await performScan(token);
}

async function submitManual() {
    const token = extractScanToken(manualInput.value);

    if (!token) {
        lastResult.value = { verdict: 'malformed', ticket: null };
        return;
    }

    manualInput.value = '';
    await performScan(token);
}

async function performScan(token) {
    busy.value = true;

    try {
        const result = await scanTicket(token);
        lastResult.value = result;
        history.value.unshift({
            verdict: result.verdict,
            ticketNumber: result.ticket?.ticketNumber ?? null,
            at: new Date().toLocaleTimeString(),
        });
        history.value = history.value.slice(0, 25);
    } catch (e) {
        if (e.requiresLogin) {
            emit('session-expired');
            return;
        }
        lastResult.value = { verdict: 'error', error: e.message, ticket: null };
    } finally {
        busy.value = false;
    }
}

const VERDICT_META = {
    valid: { label: 'VALID — let them in', cls: 'verdict-valid' },
    already_scanned: { label: 'ALREADY SCANNED', cls: 'verdict-warn' },
    expired: { label: 'EXPIRED', cls: 'verdict-bad' },
    revoked: { label: 'REVOKED', cls: 'verdict-bad' },
    not_found: { label: 'UNKNOWN TICKET', cls: 'verdict-bad' },
    malformed: { label: 'NOT A TICKET CODE', cls: 'verdict-bad' },
    error: { label: 'SCAN ERROR', cls: 'verdict-bad' },
};

function verdictMeta(verdict) {
    return VERDICT_META[verdict] ?? VERDICT_META.error;
}
</script>

<template>
    <div class="scanner">
        <div class="card camera-card">
            <video ref="video" class="camera" muted playsinline></video>
            <p v-if="cameraError" class="muted">{{ cameraError }}</p>
        </div>

        <div v-if="lastResult" class="card result" :class="verdictMeta(lastResult.verdict).cls" role="status" aria-live="assertive">
            <strong class="verdict-label">{{ verdictMeta(lastResult.verdict).label }}</strong>
            <template v-if="lastResult.ticket">
                <div>Ticket: {{ lastResult.ticket.ticketNumber }}</div>
                <div v-if="lastResult.ticket.bookingNumber">Booking: {{ lastResult.ticket.bookingNumber }}</div>
                <div v-if="lastResult.verdict === 'already_scanned' && lastResult.ticket.scannedAt">
                    First scanned: {{ lastResult.ticket.scannedAt }}
                </div>
            </template>
            <div v-if="lastResult.error" class="muted">{{ lastResult.error }}</div>
        </div>

        <form class="card manual" @submit.prevent="submitManual">
            <label>
                Manual entry (QR content or token)
                <input v-model="manualInput" type="text" inputmode="text" autocomplete="off" spellcheck="false" />
            </label>
            <button type="submit" :disabled="busy">Check</button>
        </form>

        <div v-if="history.length" class="card history">
            <h2>This session</h2>
            <ul>
                <li v-for="(entry, index) in history" :key="index" :class="verdictMeta(entry.verdict).cls">
                    <span>{{ entry.at }}</span>
                    <span>{{ entry.ticketNumber ?? '—' }}</span>
                    <span>{{ verdictMeta(entry.verdict).label }}</span>
                </li>
            </ul>
        </div>
    </div>
</template>
