<?php

declare(strict_types=1);

namespace CodeContext\Renderer\Markdown;

use CodeContext\Model\Context;

final class CommandsMdRenderer
{
    public function render(Context $context): string
    {
        $commands = \is_array($context->symfony['commands'] ?? null) ? $context->symfony['commands'] : [];
        $lines = ['# commands.md', ''];
        if ([] === $commands) {
            $lines[] = '> No Symfony console commands detected.';

            return implode("\n", $lines) . "\n";
        }
        foreach ($commands as $command) {
            if (!\is_array($command)) {
                continue;
            }
            $lines[] = sprintf(
                '- `%s` — `%s`',
                (string) ($command['class'] ?? '?'),
                (string) ($command['attribute'] ?? ''),
            );
        }

        return implode("\n", $lines) . "\n";
    }
}
