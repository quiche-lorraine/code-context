<?php

declare(strict_types=1);

namespace CodeContext\Mcp;

final class MergeResult
{
    public function __construct(
        private readonly string $prettyJson,
        private readonly bool $noOp,
    ) {
    }

    public function prettyJson(): string
    {
        return $this->prettyJson;
    }

    public function isNoOp(): bool
    {
        return $this->noOp;
    }
}
