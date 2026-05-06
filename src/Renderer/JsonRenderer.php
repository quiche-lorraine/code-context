<?php

declare(strict_types=1);

namespace CodeContext\Renderer;

use CodeContext\Config\Config;
use CodeContext\Model\Context;

/**
 * Serializes a Context into a JSON string.
 */
final class JsonRenderer
{
    public function __construct(private readonly Config $config)
    {
    }

    public function render(Context $context): string
    {
        $payload = $context->toArray();

        if ($this->config->jsonStripNulls()) {
            $payload = self::stripNulls($payload);
        }

        $flags = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;
        if ($this->config->jsonPretty()) {
            $flags |= \JSON_PRETTY_PRINT;
        }

        return json_encode($payload, $flags) . "\n";
    }

    /**
     * Recursively strips null values from associative arrays. Sequential lists keep all entries
     * (including nulls) to preserve positional meaning.
     */
    private static function stripNulls(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::stripNulls($item), $value);
        }

        $clean = [];
        foreach ($value as $key => $item) {
            if (null === $item) {
                continue;
            }
            $clean[$key] = self::stripNulls($item);
        }

        return $clean;
    }
}
