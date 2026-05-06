<?php

declare(strict_types=1);

namespace CodeContext\Renderer\Markdown;

use CodeContext\Model\Context;

final class EntitiesMdRenderer
{
    public function render(Context $context): string
    {
        $entities = \is_array($context->symfony['entities'] ?? null) ? $context->symfony['entities'] : [];
        $lines = ['# entities.md', ''];
        if ([] === $entities) {
            $lines[] = '> No Doctrine entities detected.';

            return implode("\n", $lines) . "\n";
        }
        foreach ($entities as $entity) {
            if (!\is_array($entity)) {
                continue;
            }
            $lines[] = sprintf('## `%s`', (string) ($entity['class'] ?? '?'));
            $lines[] = '';
            $properties = \is_array($entity['properties'] ?? null) ? $entity['properties'] : [];
            foreach ($properties as $property) {
                if (!\is_array($property)) {
                    continue;
                }
                $lines[] = sprintf(
                    '- `%s $%s` (%s)',
                    (string) (($property['type'] ?? null) ?: 'mixed'),
                    (string) ($property['name'] ?? '?'),
                    (string) ($property['visibility'] ?? 'public'),
                );
            }
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }
}
