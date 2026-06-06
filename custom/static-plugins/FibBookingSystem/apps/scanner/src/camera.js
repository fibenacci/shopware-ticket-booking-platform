/**
 * Camera selection helpers for the scanner view.
 *
 * The preferred camera id is the ONLY thing this app persists (localStorage):
 * it is a non-sensitive device preference — auth tokens stay strictly
 * in-memory (see api.js). Re-selecting the webcam after every reload would be
 * unusable at a busy entrance.
 */

const STORAGE_KEY = 'fib-scanner-camera';

export function loadPreferredCameraId() {
    try {
        return globalThis.localStorage?.getItem(STORAGE_KEY) ?? null;
    } catch {
        return null;
    }
}

export function storePreferredCameraId(id) {
    try {
        if (typeof id === 'string' && id !== '') {
            globalThis.localStorage?.setItem(STORAGE_KEY, id);
        } else {
            globalThis.localStorage?.removeItem(STORAGE_KEY);
        }
    } catch {
        // storage unavailable (private mode etc.) — selection just won't persist
    }
}

/**
 * Picks the camera matching the preferred id, or null when it is unknown /
 * unavailable (caller keeps the scanner's default then).
 */
export function pickCamera(cameras, preferredId) {
    if (!Array.isArray(cameras) || cameras.length === 0 || !preferredId) {
        return null;
    }

    return cameras.find((camera) => camera && camera.id === preferredId) ?? null;
}

/**
 * Display label for a camera entry — some browsers return empty labels until
 * permissions are granted.
 */
export function cameraLabel(camera, index) {
    const label = typeof camera?.label === 'string' ? camera.label.trim() : '';

    return label !== '' ? label : `Camera ${index + 1}`;
}
