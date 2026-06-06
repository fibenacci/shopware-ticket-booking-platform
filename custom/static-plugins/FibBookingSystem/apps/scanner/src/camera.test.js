import { afterEach, describe, expect, it } from 'vitest';
import { cameraLabel, loadPreferredCameraId, pickCamera, storePreferredCameraId } from './camera.js';

const CAMERAS = [
    { id: 'cam-front', label: 'FaceTime HD Camera' },
    { id: 'cam-usb', label: 'Logitech C920' },
];

describe('pickCamera', () => {
    it('returns the camera matching the preferred id', () => {
        expect(pickCamera(CAMERAS, 'cam-usb')).toEqual(CAMERAS[1]);
    });

    it('returns null for an unknown preferred id (camera unplugged)', () => {
        expect(pickCamera(CAMERAS, 'cam-gone')).toBeNull();
    });

    it('returns null without a preference or without cameras', () => {
        expect(pickCamera(CAMERAS, null)).toBeNull();
        expect(pickCamera(CAMERAS, '')).toBeNull();
        expect(pickCamera([], 'cam-usb')).toBeNull();
        expect(pickCamera(undefined, 'cam-usb')).toBeNull();
    });
});

describe('cameraLabel', () => {
    it('uses the device label when present', () => {
        expect(cameraLabel(CAMERAS[1], 1)).toBe('Logitech C920');
    });

    it('falls back to a numbered label when the browser hides it', () => {
        expect(cameraLabel({ id: 'x', label: '' }, 0)).toBe('Camera 1');
        expect(cameraLabel(undefined, 2)).toBe('Camera 3');
    });
});

describe('preferred camera persistence', () => {
    const store = new Map();

    globalThis.localStorage = {
        getItem: (key) => (store.has(key) ? store.get(key) : null),
        setItem: (key, value) => store.set(key, String(value)),
        removeItem: (key) => store.delete(key),
    };

    afterEach(() => store.clear());

    it('round-trips the preferred camera id', () => {
        storePreferredCameraId('cam-usb');
        expect(loadPreferredCameraId()).toBe('cam-usb');
    });

    it('clears the preference for empty values', () => {
        storePreferredCameraId('cam-usb');
        storePreferredCameraId('');
        expect(loadPreferredCameraId()).toBeNull();
    });

    it('never stores anything else (tokens stay in memory)', () => {
        storePreferredCameraId('cam-usb');
        expect([...store.keys()]).toEqual(['fib-scanner-camera']);
    });
});
