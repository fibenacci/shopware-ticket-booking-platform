/**
 * Validity presets for the product booking tab. Day/month/3-month/year
 * tickets are NOT distinct features — they are the same configuration with a
 * different ISO-8601 duration. Presets only pre-fill that value; "custom"
 * accepts any ISO-8601 duration (PT4H, P2W, P18M, …).
 *
 * Pure module — unit-tested without the admin runtime.
 */

export const DURATION_PRESETS = [
    { value: 'day', duration: 'P1D' },
    { value: 'week', duration: 'P1W' },
    { value: 'month', duration: 'P1M' },
    { value: 'threeMonths', duration: 'P3M' },
    { value: 'sixMonths', duration: 'P6M' },
    { value: 'year', duration: 'P1Y' },
];

export const CUSTOM_PRESET = 'custom';

/** ISO-8601 duration, e.g. P1D, P3M, PT4H, P1DT12H — at least one component. */
const ISO_DURATION_PATTERN = /^P(?!$)(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?$/;

export function isValidIsoDuration(value) {
    return typeof value === 'string' && ISO_DURATION_PATTERN.test(value);
}

/** Maps a stored duration back to its preset key (or "custom"). */
export function presetForDuration(duration) {
    const preset = DURATION_PRESETS.find((entry) => entry.duration === duration);

    return preset ? preset.value : CUSTOM_PRESET;
}

/** Returns the ISO duration for a preset key, null for "custom"/unknown. */
export function durationForPreset(presetValue) {
    const preset = DURATION_PRESETS.find((entry) => entry.value === presetValue);

    return preset ? preset.duration : null;
}
