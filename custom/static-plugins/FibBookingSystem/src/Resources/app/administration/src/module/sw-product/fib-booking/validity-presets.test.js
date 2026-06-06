import { describe, expect, it } from 'vitest';
import {
    CUSTOM_PRESET,
    DURATION_PRESETS,
    durationForPreset,
    isValidIsoDuration,
    presetForDuration,
} from './validity-presets.js';

describe('isValidIsoDuration', () => {
    it.each(['P1D', 'P1W', 'P1M', 'P3M', 'P1Y', 'PT4H', 'P1DT12H', 'P18M'])('accepts %s', (value) => {
        expect(isValidIsoDuration(value)).toBe(true);
    });

    it.each([
        ['empty', ''],
        ['bare P', 'P'],
        ['bare PT', 'PT'],
        ['words', 'one month'],
        ['missing P', '1M'],
        ['lowercase', 'p1m'],
        ['null', null],
        ['number', 30],
    ])('rejects %s', (_label, value) => {
        expect(isValidIsoDuration(value)).toBe(false);
    });
});

describe('preset mapping', () => {
    it('round-trips every preset', () => {
        for (const preset of DURATION_PRESETS) {
            expect(presetForDuration(durationForPreset(preset.value))).toBe(preset.value);
        }
    });

    it('maps unknown durations to custom', () => {
        expect(presetForDuration('P42D')).toBe(CUSTOM_PRESET);
        expect(presetForDuration(null)).toBe(CUSTOM_PRESET);
    });

    it('returns no duration for the custom preset', () => {
        expect(durationForPreset(CUSTOM_PRESET)).toBeNull();
        expect(durationForPreset('nope')).toBeNull();
    });
});
