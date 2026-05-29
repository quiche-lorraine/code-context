<?php

declare(strict_types=1);

namespace CodeContext\Tests;

use PHPUnit\Framework\TestCase;

final class InitCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/code-context-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function testMcpInitCreatesFileWhenMissing(): void
    {
        $result = $this->runInit('--agent=mcp', '--cwd=' . $this->tmpDir);

        self::assertSame(0, $result['exitCode'], implode("\n", $result['output']));
        self::assertFileExists($this->tmpDir . '/.mcp.json');

        $json = json_decode((string) file_get_contents($this->tmpDir . '/.mcp.json'), true);
        self::assertIsArray($json);
        self::assertArrayHasKey('code-context', $json['mcpServers']);
        self::assertSame('php', $json['mcpServers']['code-context']['command']);
        self::assertContains('vendor/bin/code-context', $json['mcpServers']['code-context']['args']);
    }

    public function testMcpInitPreservesExistingServers(): void
    {
        copy(__DIR__ . '/Fixtures/mcp-existing.json', $this->tmpDir . '/.mcp.json');

        $result = $this->runInit('--agent=mcp', '--cwd=' . $this->tmpDir);

        self::assertSame(0, $result['exitCode'], implode("\n", $result['output']));

        $json = json_decode((string) file_get_contents($this->tmpDir . '/.mcp.json'), true);
        self::assertIsArray($json);
        self::assertArrayHasKey('code-context', $json['mcpServers']);
        self::assertArrayHasKey('symfony-ai-mate', $json['mcpServers']);
    }

    public function testMcpInitIsIdempotent(): void
    {
        $this->runInit('--agent=mcp', '--cwd=' . $this->tmpDir);
        $afterFirst = file_get_contents($this->tmpDir . '/.mcp.json');

        $this->runInit('--agent=mcp', '--cwd=' . $this->tmpDir);
        $afterSecond = file_get_contents($this->tmpDir . '/.mcp.json');

        self::assertSame($afterFirst, $afterSecond);
    }

    public function testDryRunDoesNotWriteFile(): void
    {
        $result = $this->runInit('--agent=mcp', '--dry-run', '--cwd=' . $this->tmpDir);

        self::assertSame(0, $result['exitCode']);
        self::assertFileDoesNotExist($this->tmpDir . '/.mcp.json');
    }

    public function testInvalidAgentReturnsError(): void
    {
        $result = $this->runInit('--agent=invalid', '--cwd=' . $this->tmpDir);

        self::assertSame(2, $result['exitCode']);
    }

    /** @return array{exitCode: int, output: list<string>} */
    private function runInit(string ...$args): array
    {
        $bin = realpath(__DIR__ . '/../bin/code-context');
        self::assertNotFalse($bin);
        $argStr = implode(' ', array_map('escapeshellarg', $args));
        exec(sprintf('php %s init %s 2>&1', escapeshellarg((string) $bin), $argStr), $lines, $exitCode);

        return ['exitCode' => $exitCode, 'output' => $lines];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if (false === $items) {
            return;
        }
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $target = $path . '/' . $item;
            is_dir($target) ? $this->removeDirectory($target) : unlink($target);
        }
        rmdir($path);
    }
}
