<?php

declare(strict_types=1);

namespace CodeContext\Index;

use CodeContext\Model\ClassInfo;

/**
 * Persists a per-file hash+ClassInfo cache (manifest.json) so that unchanged
 * files can be skipped during re-indexing.
 */
final class ManifestManager
{
    private const int VERSION = 1;

    /** @var array<string, array{hash: string, classes: list<array<string, mixed>>}> */
    private array $entries = [];

    private bool $dirty = false;

    public function __construct(private readonly string $manifestPath)
    {
        $this->load();
    }

    /**
     * Returns cached ClassInfo objects for the file if the hash matches, or null on a miss.
     *
     * @return list<ClassInfo>|null
     */
    public function get(string $relativeFile, string $hash): ?array
    {
        $entry = $this->entries[$relativeFile] ?? null;
        if (null === $entry || $entry['hash'] !== $hash) {
            return null;
        }

        return array_map(
            static fn (array $d): ClassInfo => ClassInfo::fromArray($d),
            $entry['classes'],
        );
    }

    /**
     * Stores the analysis result for a file alongside its content hash.
     *
     * @param list<ClassInfo> $classes
     */
    public function put(string $relativeFile, string $hash, array $classes): void
    {
        $this->entries[$relativeFile] = [
            'hash' => $hash,
            'classes' => array_map(static fn (ClassInfo $c): array => $c->toArray(), $classes),
        ];
        $this->dirty = true;
    }

    /**
     * Removes stale entries for files that are no longer present in the scan.
     *
     * @param list<string> $currentFiles
     */
    public function prune(array $currentFiles): void
    {
        $current = array_flip($currentFiles);
        foreach (array_keys($this->entries) as $key) {
            if (!isset($current[$key])) {
                unset($this->entries[$key]);
                $this->dirty = true;
            }
        }
    }

    /**
     * Writes the manifest to disk if any entry has changed.
     */
    public function save(): void
    {
        if (!$this->dirty) {
            return;
        }

        $dir = \dirname($this->manifestPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $data = json_encode(
            ['version' => self::VERSION, 'files' => $this->entries],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );

        if (false !== $data) {
            file_put_contents($this->manifestPath, $data . "\n");
        }

        $this->dirty = false;
    }

    private function load(): void
    {
        if (!is_file($this->manifestPath)) {
            return;
        }

        $raw = @file_get_contents($this->manifestPath);
        if (false === $raw) {
            return;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        if (!\is_array($decoded) || ($decoded['version'] ?? null) !== self::VERSION) {
            return;
        }

        /** @var array<string, array{hash: string, classes: list<array<string, mixed>>}> $files */
        $files = \is_array($decoded['files'] ?? null) ? $decoded['files'] : [];
        $this->entries = $files;
    }
}
