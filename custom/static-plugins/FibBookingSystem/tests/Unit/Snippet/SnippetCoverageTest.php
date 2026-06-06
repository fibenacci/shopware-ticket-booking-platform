<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Snippet;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every plugin-owned snippet key used in a Twig template must exist in EVERY
 * snippet file — `|trans` returns the raw key when no translation exists
 * (and a trailing `|default` never kicks in, because the key string is
 * truthy), so a missing key silently ships raw keys into PDFs and mails.
 * The second guarantee: de-DE and en-GB stay structurally identical.
 */
class SnippetCoverageTest extends TestCase
{
    private const PLUGIN_KEY_PREFIXES = ['fibBooking.', 'fib-booking.'];

    private const SNIPPET_FILES = [
        'de-DE' => __DIR__ . '/../../../src/Resources/snippet/de_DE/storefront.de-DE.json',
        'en-GB' => __DIR__ . '/../../../src/Resources/snippet/en_GB/storefront.en-GB.json',
    ];

    public function testEveryPluginTransKeyExistsInEverySnippetFile(): void
    {
        $usedKeys = $this->collectPluginTransKeysFromTemplates();
        static::assertNotSame([], $usedKeys, 'sanity: template scan must find plugin snippet keys');

        foreach (self::SNIPPET_FILES as $locale => $file) {
            $defined = $this->flattenedKeys($file);

            foreach ($usedKeys as $key => $templates) {
                static::assertContains(
                    $key,
                    $defined,
                    sprintf(
                        'Snippet key "%s" (used in %s) is missing in %s — the PDF/storefront would show the raw key.',
                        $key,
                        implode(', ', $templates),
                        $locale,
                    ),
                );
            }
        }
    }

    public function testSnippetFilesAreStructurallyIdentical(): void
    {
        $keysByLocale = [];

        foreach (self::SNIPPET_FILES as $locale => $file) {
            $keysByLocale[$locale] = $this->flattenedKeys($file);
        }

        static::assertSame(
            $keysByLocale['de-DE'],
            $keysByLocale['en-GB'],
            'de-DE and en-GB snippet files must define the same keys — a one-sided key falls back to the raw key in the other language',
        );
    }

    /**
     * @return array<string, list<string>> key => templates using it
     */
    private function collectPluginTransKeysFromTemplates(): array
    {
        $viewsDir = __DIR__ . '/../../../src/Resources/views';
        static::assertDirectoryExists($viewsDir);

        $keys = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewsDir)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());

            // 'snippet.key'|trans as well as "snippet.key"|trans
            preg_match_all('/[\'"]([a-zA-Z0-9._-]+)[\'"]\s*\|\s*trans/', $content, $matches);

            foreach ($matches[1] as $key) {
                foreach (self::PLUGIN_KEY_PREFIXES as $prefix) {
                    if (str_starts_with($key, $prefix)) {
                        $keys[$key][] = $file->getFilename();
                    }
                }
            }
        }

        ksort($keys);

        return array_map(static fn (array $templates): array => array_values(array_unique($templates)), $keys);
    }

    /**
     * @return list<string> dot-separated leaf keys
     */
    private function flattenedKeys(string $file): array
    {
        static::assertFileExists($file);

        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);

        $keys = $this->flatten($data);
        sort($keys);

        return $keys;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function flatten(
        array $data,
        string $prefix = '',
    ): array {
        $keys = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $keys = [...$keys, ...$this->flatten($value, $path)];
            } else {
                $keys[] = $path;
            }
        }

        return $keys;
    }
}
