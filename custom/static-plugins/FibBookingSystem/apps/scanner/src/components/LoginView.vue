<script setup>
import { ref } from 'vue';
import { login } from '../api.js';

const emit = defineEmits(['logged-in']);

const username = ref('');
const password = ref('');
const error = ref('');
const busy = ref(false);

async function submit() {
    if (busy.value) {
        return;
    }

    error.value = '';
    busy.value = true;

    try {
        await login(username.value, password.value);
        password.value = '';
        emit('logged-in');
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <form class="card login" @submit.prevent="submit">
        <h2>Operator login</h2>
        <p class="muted">Sign in with an admin account that has the <code>fib_booking.ticket_scan</code> privilege.</p>

        <label>
            Username
            <input v-model="username" type="text" autocomplete="username" required autofocus />
        </label>

        <label>
            Password
            <input v-model="password" type="password" autocomplete="current-password" required />
        </label>

        <p v-if="error" class="error" role="alert">{{ error }}</p>

        <button type="submit" :disabled="busy">{{ busy ? 'Signing in…' : 'Sign in' }}</button>
    </form>
</template>
