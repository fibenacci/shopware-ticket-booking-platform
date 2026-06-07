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
// Rotating QR (TOTP) wire format: FIBR1:<ticketNumber>:<code>. The scanner
// forwards it verbatim; the server routes static vs rotating by this shape.
const ROTATING_PATTERN = /^FIBR1:[A-Za-z0-9-]{1,40}:[0-9a-f]{16}$/;

export const DIRECTION_CHECK_IN = 'check_in';
export const DIRECTION_CHECK_OUT = 'check_out';

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
    return typeof value === 'string' && (TOKEN_PATTERN.test(value) || ROTATING_PATTERN.test(value));
}

/**
 * Extracts the scan value from raw QR content. Accepts:
 * - the canonical static JSON payload ({type, ticketNumber, scanToken}),
 * - a bare static token (64 hex),
 * - a rotating wire string (FIBR1:<ticketNumber>:<code>).
 * The value is forwarded to the server unchanged; the server decides static
 * vs rotating.
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

export async function scanTicket(scanToken, direction = DIRECTION_CHECK_IN) {
    if (!isLikelyScanToken(scanToken)) {
        throw new Error('Malformed scan token.');
    }

    if (direction !== DIRECTION_CHECK_IN && direction !== DIRECTION_CHECK_OUT) {
        throw new Error('Malformed scan direction.');
    }

    const response = await authorizedRequest('/api/_action/fib-booking/ticket/scan', {
        method: 'POST',
        body: JSON.stringify({ scanToken, direction }),
    });

    if (response.status === 403) {
        throw new Error('Missing permission to scan tickets (fib_booking.ticket_scan).');
    }

    if (!response.ok) {
        throw new Error(`Scan failed (${response.status}).`);
    }

    return response.json();
}

/**
 * Feature flags for the scanner UI (e.g. whether check-out mode is enabled).
 * Falls back to safe defaults when the endpoint is unavailable.
 */
export async function fetchScannerConfig() {
    const response = await authorizedRequest('/api/_action/fib-booking/scanner/config', { method: 'GET' });

    if (!response.ok) {
        return { checkOutEnabled: false };
    }

    return response.json();
}

/**
 * Operator statistics (purchases, attendance, dwell time). Requires the
 * fib_booking.statistics ACL privilege — a 403 surfaces as `forbidden` so
 * the dashboard can hide itself instead of erroring.
 */
export async function fetchStatistics() {
    const response = await authorizedRequest('/api/_action/fib-booking/statistics', { method: 'GET' });

    if (response.status === 403) {
        const error = new Error('Missing permission to view statistics (fib_booking.statistics).');
        error.forbidden = true;
        throw error;
    }

    if (!response.ok) {
        throw new Error(`Loading statistics failed (${response.status}).`);
    }

    return response.json();
}

/**
 * Sends an authenticated request; on 401 it tries ONE token refresh and
 * replays the request. A second 401 ends the session.
 */
async function authorizedRequest(path, options) {
    let response = await bearerFetch(path, options);

    if (response.status === 401 && (await refreshSession())) {
        response = await bearerFetch(path, options);
    }

    if (response.status === 401) {
        logout();
        const error = new Error('Session expired — please log in again.');
        error.requiresLogin = true;
        throw error;
    }

    return response;
}

function bearerFetch(path, options) {
    return fetch(path, {
        ...options,
        headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${accessToken}`,
        },
    });
}
