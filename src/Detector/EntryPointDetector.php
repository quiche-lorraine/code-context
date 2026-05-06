<?php

declare(strict_types=1);

namespace CodeContext\Detector;

/**
 * Heuristically detects application entry points within a project root.
 *
 * Returns paths relative to the project root, sorted alphabetically.
 */
final class EntryPointDetector
{
    /** Files whose mere existence signals an entry point. */
    private const array KNOWN_FILES = [
        'public/index.php',
        'web/index.php',
        'index.php',
        'bin/console',
        'bin/app',
        'artisan',
        'bootstrap/app.php',
        'server.php',
    ];

    /**
     * @return list<string> Relative paths of detected entry points.
     */
    public function detect(string $rootDir): array
    {
        $found = [];

        foreach (self::KNOWN_FILES as $relative) {
            $absolute = $rootDir . \DIRECTORY_SEPARATOR . str_replace('/', \DIRECTORY_SEPARATOR, $relative);
            if (is_file($absolute)) {
                $found[] = $relative;
            }
        }

        // Detect any executable PHP files directly in bin/
        $binDir = $rootDir . \DIRECTORY_SEPARATOR . 'bin';
        if (is_dir($binDir)) {
            foreach (new \DirectoryIterator($binDir) as $item) {
                if ($item->isDot() || $item->isDir()) {
                    continue;
                }
                $relative = 'bin/' . $item->getFilename();
                if (!\in_array($relative, $found, true) && $this->looksLikePhpScript($item->getPathname())) {
                    $found[] = $relative;
                }
            }
        }

        // Composer scripts from composer.json
        $composerPath = $rootDir . \DIRECTORY_SEPARATOR . 'composer.json';
        if (is_file($composerPath)) {
            $raw = @file_get_contents($composerPath);
            if (false !== $raw) {
                try {
                    /** @var mixed $data */
                    $data = json_decode($raw, true, 32, \JSON_THROW_ON_ERROR);
                    if (\is_array($data)) {
                        foreach ((array) ($data['scripts'] ?? []) as $script) {
                            if (\is_string($script) && str_starts_with($script, 'php ')) {
                                $scriptFile = trim(substr($script, 4));
                                if (is_file($rootDir . \DIRECTORY_SEPARATOR . $scriptFile)
                                    && !\in_array($scriptFile, $found, true)) {
                                    $found[] = $scriptFile;
                                }
                            }
                        }
                    }
                } catch (\JsonException) {
                }
            }
        }

        sort($found);

        return array_values(array_unique($found));
    }

    private function looksLikePhpScript(string $path): bool
    {
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            return false;
        }

        $first = fread($handle, 64);
        fclose($handle);

        if (false === $first) {
            return false;
        }

        return str_starts_with($first, '<?php') || str_contains($first, '/usr/bin/env php');
    }
}
