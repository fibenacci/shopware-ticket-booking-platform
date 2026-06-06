<script setup>
import { onMounted, ref } from 'vue';
import { fetchStatistics } from '../api.js';

const emit = defineEmits(['session-expired']);

const stats = ref(null);
const loading = ref(false);
const error = ref('');
const forbidden = ref(false);

onMounted(load);

async function load() {
    loading.value = true;
    error.value = '';

    try {
        stats.value = await fetchStatistics();
    } catch (e) {
        if (e.requiresLogin) {
            emit('session-expired');
            return;
        }
        if (e.forbidden) {
            forbidden.value = true;
            return;
        }
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

function formatMinutes(minutes) {
    if (minutes === null || minutes === undefined) {
        return '—';
    }
    if (minutes < 90) {
        return `${Math.round(minutes)} min`;
    }
    return `${(minutes / 60).toFixed(1)} h`;
}

function formatRevenue(revenue) {
    return revenue.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<template>
    <div class="stats">
        <div v-if="forbidden" class="card">
            <p class="muted">
                Statistics need the <code>fib_booking.statistics</code> privilege —
                ask an administrator to extend your role.
            </p>
        </div>

        <template v-else>
            <div class="card stats-header">
                <h2>Last 30 days</h2>
                <button type="button" :disabled="loading" @click="load">Refresh</button>
            </div>

            <p v-if="error" class="card verdict-bad">{{ error }}</p>
            <p v-else-if="loading && !stats" class="card muted">Loading…</p>

            <template v-if="stats">
                <div class="card stats-grid">
                    <div class="stat">
                        <span class="stat-value">{{ stats.purchases.total }}</span>
                        <span class="stat-label">Bookings</span>
                    </div>
                    <div class="stat">
                        <span class="stat-value">{{ stats.purchases.quantity }}</span>
                        <span class="stat-label">Tickets</span>
                    </div>
                    <div class="stat">
                        <span class="stat-value">{{ formatRevenue(stats.purchases.revenue) }}</span>
                        <span class="stat-label">Revenue</span>
                    </div>
                    <div class="stat">
                        <span class="stat-value">{{ stats.attendance.currentlyInside }}</span>
                        <span class="stat-label">Currently inside</span>
                    </div>
                    <div class="stat">
                        <span class="stat-value">{{ stats.attendance.checkIns }}</span>
                        <span class="stat-label">Check-ins</span>
                    </div>
                    <div class="stat">
                        <span class="stat-value">{{ formatMinutes(stats.attendance.dwell.averageMinutes) }}</span>
                        <span class="stat-label">Ø dwell time ({{ stats.attendance.dwell.sessions }} sessions)</span>
                    </div>
                </div>

                <div v-if="stats.purchases.byProduct.length" class="card">
                    <h2>By product</h2>
                    <table class="stats-table">
                        <thead>
                            <tr><th>Product</th><th>Bookings</th><th>Tickets</th><th>Revenue</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in stats.purchases.byProduct" :key="row.label">
                                <td>{{ row.label }}</td>
                                <td>{{ row.count }}</td>
                                <td>{{ row.quantity }}</td>
                                <td>{{ formatRevenue(row.revenue) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="stats.purchases.byDay.length" class="card">
                    <h2>By day</h2>
                    <table class="stats-table">
                        <thead>
                            <tr><th>Date</th><th>Bookings</th><th>Tickets</th><th>Revenue</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in stats.purchases.byDay" :key="row.date">
                                <td>{{ row.date }}</td>
                                <td>{{ row.count }}</td>
                                <td>{{ row.quantity }}</td>
                                <td>{{ formatRevenue(row.revenue) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </template>
    </div>
</template>
