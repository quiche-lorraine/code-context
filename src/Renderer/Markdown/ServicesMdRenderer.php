<?php

declare(strict_types=1);

namespace CodeContext\Renderer\Markdown;

use CodeContext\Model\Context;

final class ServicesMdRenderer
{
    public function render(Context $context): string
    {
        $services = \is_array($context->symfony['services'] ?? null) ? $context->symfony['services'] : [];
        $lines = ['# services.md', ''];
        if ([] === $services) {
            $lines[] = '> No Symfony services detected in `config/services.yaml`.';

            return implode("\n", $lines) . "\n";
        }

        foreach ($services as $service) {
            if (!\is_array($service)) {
                continue;
            }
            $lines[] = sprintf('- `%s`', (string) ($service['id'] ?? '?'));
        }

        return implode("\n", $lines) . "\n";
    }
}
