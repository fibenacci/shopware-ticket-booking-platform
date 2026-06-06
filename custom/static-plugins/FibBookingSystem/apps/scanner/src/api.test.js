import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    DIRECTION_CHECK_OUT,
    extractScanToken,
    fetchScannerConfig,
    fetchStatistics,
    isLikelyScanToken,
    isAuthenticated,
    login,
    logout,
    scanTicket,
} from './api.js';

const VALID_TOKEN = 'a'.repeat(64);

describe('isLikelyScanToken', () => {
    it('accepts 64 lowercase hex chars', () => {
        expect(isLikelyScanToken(VALID_TOKEN)).toBe(true);
    });

    it.each([
        ['too short', 'a'.repeat(63)],
        ['too long', 'a'.repeat(65)],
        ['uppercase', 'A'.repeat(64)],
        ['non-hex', 'g'.repeat(64)],
        ['empty', ''],
        ['null', null],
        ['number', 1234],
    ])('rejects %s', (_label, value) => {
        expect(isLikelyScanToken(value)).toBe(false);
    });
});

describe('extractScanToken', () => {
    it('accepts a bare token (trimmed)', () => {
        expect(extractScanToken(`  ${VALID_TOKEN}\n`)).toBe(VALID_TOKEN);
    });

    it('accepts the canonical QR JSON payload', () => {
        const payload = JSON.stringify({
            type: 'fib_booking_ticket',
            ticketNumber: 'T1001',
            scanToken: VALID_TOKEN,
        });

        expect(extractScanToken(payload)).toBe(VALID_TOKEN);
    });

    it('rejects JSON with a foreign type', () => {
        const payload = JSON.stringify({ type: 'something_else', scanToken: VALID_TOKEN });

        expect(extractScanToken(payload)).toBeNull();
    });

    it('rejects JSON with a malformed token', () => {
        const payload = JSON.stringify({ type: 'fib_booking_ticket', scanToken: 'nope' });

        expect(extractScanToken(payload)).toBeNull();
    });

    it('rejects oversized input (anti-DoS)', () => {
        expect(extractScanToken('x'.repeat(5000))).toBeNull();
    });

    it('rejects arbitrary strings and URLs', () => {
        expect(extractScanToken('https://evil.example/?q=1')).toBeNull();
        expect(extractScanToken('<script>alert(1)</script>')).toBeNull();
    });
});

describe('session handling', () => {
    afterEach(() => {
        logout();
        vi.unstubAllGlobals();
    });

    it('is unauthenticated by default and after logout', () => {
        expect(isAuthenticated()).toBe(false);
        logout();
        expect(isAuthenticated()).toBe(false);
    });

    it('authenticates on successful login and keeps tokens in memory only', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ access_token: 't', refresh_token: 'r', expires_in: 600 }),
        }));

        await login('operator', 'secret');

        expect(isAuthenticated()).toBe(true);
        // Nothing may be persisted — XSS must not be able to steal a session at rest.
        expect(Object.keys(globalThis.localStorage ?? {})).toHaveLength(0);
    });

    it('clears the session on failed login', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 401 }));

        await expect(login('operator', 'wrong')).rejects.toThrow('Invalid credentials.');
        expect(isAuthenticated()).toBe(false);
    });
});

describe('scanTicket directions', () => {
    afterEach(() => {
        logout();
        vi.unstubAllGlobals();
    });

    async function loginWithStubbedFetch(fetchMock) {
        fetchMock.mockResolvedValueOnce({
            ok: true,
            json: async () => ({ access_token: 't', refresh_token: 'r', expires_in: 600 }),
        });
        vi.stubGlobal('fetch', fetchMock);
        await login('operator', 'secret');
    }

    it('defaults to check_in and sends the direction in the body', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ verdict: 'valid' }),
        });
        await loginWithStubbedFetch(fetchMock);

        await scanTicket(VALID_TOKEN);

        const [, options] = fetchMock.mock.calls.at(-1);
        expect(JSON.parse(options.body)).toEqual({ scanToken: VALID_TOKEN, direction: 'check_in' });
    });

    it('sends check_out when requested', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ verdict: 'checked_out' }),
        });
        await loginWithStubbedFetch(fetchMock);

        await scanTicket(VALID_TOKEN, DIRECTION_CHECK_OUT);

        const [, options] = fetchMock.mock.calls.at(-1);
        expect(JSON.parse(options.body).direction).toBe('check_out');
    });

    it('rejects an unknown direction before any request', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        await expect(scanTicket(VALID_TOKEN, 'sideways')).rejects.toThrow('Malformed scan direction.');
        expect(fetchMock).not.toHaveBeenCalled();
    });
});

describe('fetchScannerConfig', () => {
    afterEach(() => {
        logout();
        vi.unstubAllGlobals();
    });

    it('returns the server config', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({ access_token: 't', refresh_token: 'r', expires_in: 600 }),
            })
            .mockResolvedValue({ ok: true, status: 200, json: async () => ({ checkOutEnabled: true }) });
        vi.stubGlobal('fetch', fetchMock);
        await login('operator', 'secret');

        expect(await fetchScannerConfig()).toEqual({ checkOutEnabled: true });
    });

    it('falls back to check-in only when the endpoint errors', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({ access_token: 't', refresh_token: 'r', expires_in: 600 }),
            })
            .mockResolvedValue({ ok: false, status: 500 });
        vi.stubGlobal('fetch', fetchMock);
        await login('operator', 'secret');

        expect(await fetchScannerConfig()).toEqual({ checkOutEnabled: false });
    });
});

describe('fetchStatistics', () => {
    afterEach(() => {
        logout();
        vi.unstubAllGlobals();
    });

    it('marks a 403 as forbidden so the dashboard can hide itself', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({ access_token: 't', refresh_token: 'r', expires_in: 600 }),
            })
            .mockResolvedValue({ ok: false, status: 403 });
        vi.stubGlobal('fetch', fetchMock);
        await login('operator', 'secret');

        await expect(fetchStatistics()).rejects.toMatchObject({ forbidden: true });
    });
});
