<?php

declare(strict_types=1);

namespace FibBookingDemoData\Service;

use RuntimeException;

/**
 * Typed, validating accessor over a section of the seed JSON
 * (Resources/seeds/booking-demo.json).
 *
 * Every getter either returns the declared type or throws with the exact
 * JSON path — malformed seeds fail loudly at the offending key instead of
 * surfacing later as a DAL write error.
 */
final class SeedSection
{
    /**
     * @param array<mixed, mixed> $data raw decoded JSON — getters validate per key
     */
    public function __construct(
        private readonly array $data,
        private readonly string $path,
    ) {
    }

    public static function fromJson(string $json, string $sourceName): self
    {
        $decoded = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('%s must contain a JSON object.', $sourceName));
        }

        return new self($decoded, $sourceName);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function string(string $key, ?string $default = null): string
    {
        $value = $this->data[$key] ?? $default;

        if (!is_string($value) || $value === '') {
            throw $this->invalid($key, 'a non-empty string');
        }

        return $value;
    }

    public function int(string $key, ?int $default = null): int
    {
        $value = $this->data[$key] ?? $default;

        if (!is_int($value)) {
            throw $this->invalid($key, 'an integer');
        }

        return $value;
    }

    public function float(string $key): float
    {
        $value = $this->data[$key] ?? null;

        if (!is_int($value) && !is_float($value)) {
            throw $this->invalid($key, 'a number');
        }

        return (float) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->data[$key] ?? $default;

        if (!is_bool($value)) {
            throw $this->invalid($key, 'a boolean');
        }

        return $value;
    }

    public function section(string $key): self
    {
        $section = $this->sectionOrNull($key);

        if ($section === null) {
            throw $this->invalid($key, 'an object');
        }

        return $section;
    }

    public function sectionOrNull(string $key): ?self
    {
        $value = $this->data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            throw $this->invalid($key, 'an object');
        }

        return new self($value, $this->path . '.' . $key);
    }

    /**
     * @return list<self>
     */
    public function sections(string $key): array
    {
        $value = $this->data[$key] ?? null;

        if (!is_array($value)) {
            throw $this->invalid($key, 'a list of objects');
        }

        $sections = [];
        foreach (array_values($value) as $index => $entry) {
            if (!is_array($entry)) {
                throw $this->invalid($key . '[' . $index . ']', 'an object');
            }

            $sections[] = new self($entry, sprintf('%s.%s[%d]', $this->path, $key, $index));
        }

        return $sections;
    }

    /**
     * @param list<string> $default
     *
     * @return list<string>
     */
    public function stringList(string $key, array $default = []): array
    {
        $value = $this->data[$key] ?? $default;

        if (!is_array($value)) {
            throw $this->invalid($key, 'a list of strings');
        }

        $strings = [];
        foreach (array_values($value) as $index => $entry) {
            if (!is_string($entry) || $entry === '') {
                throw $this->invalid($key . '[' . $index . ']', 'a non-empty string');
            }

            $strings[] = $entry;
        }

        return $strings;
    }

    private function invalid(string $key, string $expected): RuntimeException
    {
        return new RuntimeException(sprintf('Seed value "%s.%s" must be %s.', $this->path, $key, $expected));
    }
}
