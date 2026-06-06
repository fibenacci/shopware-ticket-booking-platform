/**
 * Minimal Admin API client with a strict security posture:
 *
 * - Tokens live ONLY in module memory. Nothing is written to localStorage,
 *   sessionStorage or cookies — XSS cannot exfiltrate persisted credentials
 *   and a stolen device holds no session after the tab closes.
 * - The scan token is validated client-side (64 lowercase hex chars) before
 *   it is ever sent.
 * - 401 responses clear the session and surface a re-login requirement.
 */

const TOKEN_PATTERN = /^[0-9a-f]{64}$/;

let accessToken = null;
let refreshToken = null;
let expiresAt = 0;

export function isAuthenticated() {
    return accessToken !== null && Date.now() < expiresAt;
}

export function logout() {
    accessToken = null;
    refreshToken = null;
    expiresAt = 0;
}

export async function login(username, password) {
    const response = await fetch('/api/oauth/token', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            grant_type: 'password',
            client_id: 'administration',
            scopes: 'write',
            username,
            password,
        }),
    });

    if (!response.ok) {
        logout();
        throw new Error(response.status === 400 || response.status === 401
            ? 'Invalid credentials.'
            : `Login failed (${response.status}).`);
    }

    storeTokens(await response.json());
}

async function refreshSession() {
    if (!refreshToken) {
        return false;
    }

    const response = await fetch('/api/oauth/token', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            grant_type: 'refresh_token',
            client_id: 'administration',
            refresh_token: refreshToken,
        }),
    });

    if (!response.ok) {
        logout();
        return false;
    }

    storeTokens(await response.json());
    return true;
}

function storeTokens(payload) {
    accessToken = payload.access_token ?? null;
    refreshToken = payload.refresh_token ?? refreshToken;
    expiresAt = Date.now() + Math.max(0, (payload.expires_in ?? 0) - 30) * 1000;
}

export function isLikelyScanToken(value) {
    return typeof value === 'string' && TOKEN_PATTERN.test(value);
}

/**
 * Extracts the scan token from raw QR content. Accepts the canonical JSON
 * payload ({type, ticketNumber, scanToken}) or a bare token.
 */
export function extractScanToken(rawContent) {
    if (typeof rawContent !== 'string' || rawContent.length > 4096) {
        return null;
    }

    const trimmed = rawContent.trim();

    if (isLikelyScanToken(trimmed)) {
        return trimmed;
    }

    try {
        const parsed = JSON.parse(trimmed);
        if (parsed && parsed.type === 'fib_booking_ticket' && isLikelyScanToken(parsed.scanToken)) {
            return parsed.scanToken;
        }
    } catch {
        // not JSON — fall through
    }

    return null;
}

export async function scanTicket(scanToken) {
    if (!isLikelyScanToken(scanToken)) {
        throw new Error('Malformed scan token.');
    }

    let response = await authorizedScanRequest(scanToken);

    if (response.status === 401 && (await refreshSession())) {
        response = await authorizedScanRequest(scanToken);
    }

    if (response.status === 401) {
        logout();
        const error = new Error('Session expired — please log in again.');
        error.requiresLogin = true;
        throw error;
    }

    if (response.status === 403) {
        throw new Error('Missing permission to scan tickets (fib_booking.ticket_scan).');
    }

    if (!response.ok) {
        throw new Error(`Scan failed (${response.status}).`);
    }

    return response.json();
}

function authorizedScanRequest(scanToken) {
    return fetch('/api/_action/fib-booking/ticket/scan', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${accessToken}`,
        },
        body: JSON.stringify({ scanToken }),
    });
}
