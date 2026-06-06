<script setup>
import { ref } from 'vue';
import LoginView from './components/LoginView.vue';
import ScannerView from './components/ScannerView.vue';
import StatsView from './components/StatsView.vue';
import { isAuthenticated, logout } from './api.js';

const authenticated = ref(isAuthenticated());
const tab = ref('scan');

function onLoggedIn() {
    authenticated.value = true;
    tab.value = 'scan';
}

function onLogout() {
    logout();
    authenticated.value = false;
}
</script>

<template>
    <div class="app">
        <header class="app-header">
            <h1>FIB Ticket Scanner</h1>
            <button v-if="authenticated" class="btn-link" type="button" @click="onLogout">Logout</button>
        </header>

        <nav v-if="authenticated" class="tabs" aria-label="Views">
            <button type="button" class="tab-btn" :class="{ active: tab === 'scan' }" @click="tab = 'scan'">Scan</button>
            <button type="button" class="tab-btn" :class="{ active: tab === 'stats' }" @click="tab = 'stats'">Statistics</button>
        </nav>

        <main>
            <LoginView v-if="!authenticated" @logged-in="onLoggedIn" />
            <ScannerView v-else-if="tab === 'scan'" @session-expired="onLogout" />
            <StatsView v-else @session-expired="onLogout" />
        </main>
    </div>
</template>
