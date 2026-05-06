<?php

declare(strict_types=1);

namespace CodeContext\Tests;

use PHPUnit\Framework\TestCase;

final class GenerateCommandTest extends TestCase
{
    public function testGenerateProducesJsonAndMarkdown(): void
    {
        $fixture = __DIR__ . '/Fixtures/plain-php';
        $outputDir = $fixture . '/.code-context';
        if (is_dir($outputDir)) {
            $this->removeDirectory($outputDir);
        }

        $bin = realpath(__DIR__ . '/../bin/code-context');
        self::assertNotFalse($bin);
        $command = sprintf(
            'php %s generate --cwd=%s',
            escapeshellarg((string) $bin),
            escapeshellarg($fixture),
        );

        exec($command . ' 2>&1', $lines, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $lines));
        self::assertFileExists($outputDir . '/context.json');
        self::assertFileExists($outputDir . '/AGENTS.md');
        self::assertFileExists($outputDir . '/architecture.md');
    }

    private function removeDirectory(string $path): void
    {
        $items = scandir($path);
        if (false === $items) {
            return;
        }
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $target = $path . '/' . $item;
            if (is_dir($target)) {
                $this->removeDirectory($target);
            } else {
                unlink($target);
            }
        }
        rmdir($path);
    }
}
