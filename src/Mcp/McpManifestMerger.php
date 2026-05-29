<?php

declare(strict_types=1);

namespace CodeContext\Mcp;

final class McpManifestMerger
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * @param array<string, mixed> $serverConfig
     */
    public function merge(string $serverKey, array $serverConfig): MergeResult
    {
        $existing = [];

        if (is_file($this->path)) {
            $json = file_get_contents($this->path);
            if (false === $json) {
                throw new \RuntimeException(sprintf('Cannot read %s', $this->path));
            }
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException(sprintf('Invalid JSON in %s', $this->path));
            }
            $existing = $decoded;
        }

        $before = $existing['mcpServers'][$serverKey] ?? null;
        $existing['mcpServers'][$serverKey] = $serverConfig;

        $pretty = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $noOp = $before !== null && $before === $serverConfig;

        return new MergeResult($pretty, $noOp);
    }
}
