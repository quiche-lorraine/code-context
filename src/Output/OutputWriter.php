<?php

declare(strict_types=1);

namespace CodeContext\Output;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Writes rendered files to disk under the configured output directory.
 *
 * Each call ensures the directory exists and writes atomically (write-then-rename).
 */
final class OutputWriter
{
    private readonly Filesystem $fs;

    public function __construct(private readonly string $outputDir)
    {
        $this->fs = new Filesystem();
    }

    public function ensureDirectory(): void
    {
        if (!is_dir($this->outputDir)) {
            $this->fs->mkdir($this->outputDir);
        }
    }

    public function write(string $relativePath, string $contents): string
    {
        $this->ensureDirectory();
        $absolute = rtrim($this->outputDir, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . $relativePath;
        $this->fs->dumpFile($absolute, $contents);

        return $absolute;
    }
}
