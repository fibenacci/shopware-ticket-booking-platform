<script setup>
import { ref } from 'vue';
import LoginView from './components/LoginView.vue';
import ScannerView from './components/ScannerView.vue';
import { isAuthenticated, logout } from './api.js';

const authenticated = ref(isAuthenticated());

function onLoggedIn() {
    authenticated.value = true;
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

        <main>
            <LoginView v-if="!authenticated" @logged-in="onLoggedIn" />
            <ScannerView v-else @session-expired="onLogout" />
        </main>
    </div>
</template>
