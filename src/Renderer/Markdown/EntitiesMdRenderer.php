<?php

declare(strict_types=1);

namespace CodeContext\Renderer\Markdown;

use CodeContext\Model\AttributeInfo;
use CodeContext\Model\Context;

final class EntitiesMdRenderer
{
    public function render(Context $context): string
    {
        $entities = \is_array($context->symfony['entities'] ?? null) ? $context->symfony['entities'] : [];
        $enums = \is_array($context->symfony['entity_enums'] ?? null) ? $context->symfony['entity_enums'] : [];

        $lines = ['# entities.md', ''];

        if ([] === $entities && [] === $enums) {
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
                $type = (string) (($property['type'] ?? null) ?: 'mixed');
                $name = (string) ($property['name'] ?? '?');
                $attrs = $this->filterDoctrineAttributes((array) ($property['attributes'] ?? []));
                if ([] !== $attrs) {
                    $attrStr = implode(', ', array_map(
                        fn (string $a): string => '`' . $this->shortAttrName($a) . '`',
                        $attrs,
                    ));
                    $lines[] = sprintf('- `%s $%s` — %s', $type, $name, $attrStr);
                } else {
                    $lines[] = sprintf('- `%s $%s`', $type, $name);
                }
            }
            $lines[] = '';
        }

        if ([] !== $enums) {
            $lines[] = '## Enums';
            $lines[] = '';
            foreach ($enums as $enum) {
                if (!\is_array($enum)) {
                    continue;
                }
                $fqcn = (string) ($enum['class'] ?? '?');
                $cases = array_map('strval', (array) ($enum['cases'] ?? []));
                $casesStr = [] !== $cases
                    ? ' — ' . implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $cases))
                    : '';
                $lines[] = sprintf('- `%s`%s', $fqcn, $casesStr);
            }
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Renders Doctrine mapping attributes (with their arguments) from the structured `attributes` list.
     *
     * @param array<mixed> $attributes
     * @return list<string>
     */
    private function filterDoctrineAttributes(array $attributes): array
    {
        $rendered = [];
        foreach ($attributes as $attribute) {
            if (!\is_array($attribute)) {
                continue;
            }
            $info = AttributeInfo::fromArray($attribute);
            if (str_contains($info->name, 'Doctrine\\ORM\\Mapping\\')) {
                $rendered[] = $info->render();
            }
        }

        return $rendered;
    }

    private function shortAttrName(string $attr): string
    {
        return preg_replace('/^Doctrine\\\\ORM\\\\Mapping\\\\/', '', $attr) ?? $attr;
    }
}
