<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import QrScanner from 'qr-scanner';
import { DIRECTION_CHECK_IN, DIRECTION_CHECK_OUT, extractScanToken, fetchScannerConfig, scanTicket } from '../api.js';
import { cameraLabel, loadPreferredCameraId, pickCamera, storePreferredCameraId } from '../camera.js';

const emit = defineEmits(['session-expired']);

const video = ref(null);
const cameraError = ref('');
const cameras = ref([]);
const selectedCameraId = ref('');
const manualInput = ref('');
const busy = ref(false);
const lastResult = ref(null);
const history = ref([]);
const checkOutEnabled = ref(false);
const direction = ref(DIRECTION_CHECK_IN);

let scanner = null;
let cooldownUntil = 0;
let lastToken = '';

onMounted(async () => {
    try {
        const config = await fetchScannerConfig();
        checkOutEnabled.value = config.checkOutEnabled === true;
    } catch (e) {
        if (e.requiresLogin) {
            emit('session-expired');
            return;
        }
        // Config endpoint unreachable — keep the safe default (check-in only).
    }

    try {
        scanner = new QrScanner(video.value, onDecoded, {
            preferredCamera: 'environment',
            highlightScanRegion: true,
            maxScansPerSecond: 4,
        });
        await scanner.start();
        await initCameraSelection();
    } catch (e) {
        cameraError.value = 'Camera not available — use manual input below. (' + (e?.message ?? e) + ')';
    }
});

async function initCameraSelection() {
    // requestLabels=true: the camera permission was just granted by start(),
    // so the browser now exposes proper device labels.
    cameras.value = await QrScanner.listCameras(true);

    const preferred = pickCamera(cameras.value, loadPreferredCameraId());
    if (preferred) {
        selectedCameraId.value = preferred.id;
        await scanner.setCamera(preferred.id);
    }
}

async function onCameraChange() {
    if (!scanner) {
        return;
    }

    try {
        await scanner.setCamera(selectedCameraId.value || 'environment');
        storePreferredCameraId(selectedCameraId.value);
        cameraError.value = '';
    } catch (e) {
        cameraError.value = 'Could not switch camera. (' + (e?.message ?? e) + ')';
    }
}

onBeforeUnmount(() => {
    scanner?.destroy();
    scanner = null;
});

function setDirection(value) {
    direction.value = value;
    // A direction switch is a deliberate act — allow re-scanning the code
    // that was just processed (check-in followed by check-out of the same
    // ticket is the normal flow at a single-lane entrance).
    lastToken = '';
}

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
        const result = await scanTicket(token, direction.value);
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
    checked_out: { label: 'CHECKED OUT — goodbye', cls: 'verdict-valid' },
    not_checked_in: { label: 'NOT CHECKED IN', cls: 'verdict-warn' },
    not_yet_valid: { label: 'NOT YET VALID', cls: 'verdict-warn' },
    entry_limit_reached: { label: 'DAILY LIMIT REACHED', cls: 'verdict-warn' },
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
        <div v-if="checkOutEnabled" class="card mode-toggle" role="radiogroup" aria-label="Scan direction">
            <button
                type="button"
                class="mode-btn"
                :class="{ active: direction === DIRECTION_CHECK_IN }"
                :aria-pressed="direction === DIRECTION_CHECK_IN"
                @click="setDirection(DIRECTION_CHECK_IN)"
            >
                Check-in
            </button>
            <button
                type="button"
                class="mode-btn"
                :class="{ active: direction === DIRECTION_CHECK_OUT }"
                :aria-pressed="direction === DIRECTION_CHECK_OUT"
                @click="setDirection(DIRECTION_CHECK_OUT)"
            >
                Check-out
            </button>
        </div>

        <div class="card camera-card">
            <video ref="video" class="camera" muted playsinline></video>
            <label v-if="cameras.length > 0" class="camera-select">
                Camera
                <select v-model="selectedCameraId" @change="onCameraChange">
                    <option value="">Auto (rear camera)</option>
                    <option v-for="(camera, index) in cameras" :key="camera.id" :value="camera.id">
                        {{ cameraLabel(camera, index) }}
                    </option>
                </select>
            </label>
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
