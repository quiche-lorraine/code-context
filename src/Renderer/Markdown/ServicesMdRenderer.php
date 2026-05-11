<?php

declare(strict_types=1);

namespace CodeContext\Renderer\Markdown;

use CodeContext\Model\Context;

final class ServicesMdRenderer
{
    public function render(Context $context): string
    {
        $servicesData = $context->symfony['services'] ?? [];
        $lines = ['# services.md', ''];

        if (\is_array($servicesData) && array_key_exists('configured', $servicesData)) {
            $configured = \is_array($servicesData['configured']) ? $servicesData['configured'] : [];
            $autowired = \is_array($servicesData['autowired']) ? $servicesData['autowired'] : [];
        } else {
            $configured = \is_array($servicesData) ? $servicesData : [];
            $autowired = [];
        }

        if ([] === $configured && [] === $autowired) {
            $lines[] = '> No Symfony services detected.';

            return implode("\n", $lines) . "\n";
        }

        if ([] !== $configured) {
            $lines[] = '## Configurés';
            $lines[] = '';
            foreach ($configured as $service) {
                if (!\is_array($service)) {
                    continue;
                }
                $lines[] = sprintf('- `%s`', (string) ($service['id'] ?? '?'));
            }
            $lines[] = '';
        }

        if ([] !== $autowired) {
            $lines[] = '## Autowirés';
            $lines[] = '';
            $grouped = [];
            foreach ($autowired as $service) {
                if (!\is_array($service)) {
                    continue;
                }
                $ns = (string) ($service['namespace'] ?? '');
                $grouped[$ns][] = (string) ($service['fqcn'] ?? '?');
            }
            ksort($grouped);
            foreach ($grouped as $ns => $fqcns) {
                $lines[] = sprintf('### `%s`', '' !== $ns ? $ns : '(root)');
                $lines[] = '';
                foreach ($fqcns as $fqcn) {
                    $lines[] = sprintf('- `%s`', $fqcn);
                }
                $lines[] = '';
            }
        }

        return implode("\n", $lines) . "\n";
    }
}
