<?php

declare(strict_types=1);

namespace CodeContext\Extractor\Symfony;

use CodeContext\Analyzer\PhpAstAnalyzer;
use CodeContext\Config\Config;
use CodeContext\Kernel\ProjectContext;
use CodeContext\Model\AttributeInfo;
use CodeContext\Model\ClassInfo;
use CodeContext\Model\Context;

final class EntityExtractor
{
    /** Doctrine association attributes whose target we resolve into a typed relation. */
    private const RELATION_KINDS = ['OneToOne', 'OneToMany', 'ManyToOne', 'ManyToMany'];

    public function extract(ProjectContext $project, Config $config, Context $context): void
    {
        if (!$config->symfonyEntitiesEnabled()) {
            return;
        }

        $privateConfig = $config->withIncludePrivate(true);
        $analyzer = new PhpAstAnalyzer($privateConfig);

        $entities = [];
        $enums = [];

        foreach ($context->classes as $class) {
            if ($class->kind === 'enum') {
                $enums[] = [
                    'class' => $class->fqcn,
                    'cases' => $class->cases,
                ];
                continue;
            }

            $isEntity = false;
            foreach ($class->attributes as $attribute) {
                if (str_starts_with($attribute->name, 'Doctrine\\ORM\\Mapping\\Entity')
                    || str_contains($attribute->name, '\\ORM\\Entity')) {
                    $isEntity = true;
                    break;
                }
            }
            if (!$isEntity) {
                continue;
            }

            // Re-analyze the file with include_private=true to capture Doctrine private properties
            $absoluteFile = $project->rootDir . '/' . $class->file;
            $reanalyzed = $analyzer->analyze($absoluteFile, $class->file);
            $entityClass = $class;
            foreach ($reanalyzed as $reanalyzedClass) {
                if ($reanalyzedClass->fqcn === $class->fqcn) {
                    $entityClass = $reanalyzedClass;
                    break;
                }
            }

            $entities[] = [
                'class' => $entityClass->fqcn,
                'file' => $entityClass->file,
                'properties' => array_map(
                    static fn ($p): array => [
                        'name' => $p->name,
                        'type' => $p->type,
                        'visibility' => $p->visibility,
                        'attributes' => array_map(
                            static fn (AttributeInfo $a): array => $a->toArray(),
                            $p->attributes,
                        ),
                    ],
                    $entityClass->properties,
                ),
                'relations' => $this->extractRelations($entityClass),
            ];
        }

        $context->symfony['entities'] = $entities;
        $context->symfony['entity_enums'] = $enums;
    }

    /**
     * Resolves the typed Doctrine associations declared on an entity's properties
     * (`#[ORM\ManyToOne]`, `#[ORM\OneToMany]`, …) into structured relation records.
     *
     * @return list<array<string, mixed>>
     */
    private function extractRelations(ClassInfo $class): array
    {
        $relations = [];
        foreach ($class->properties as $property) {
            foreach ($property->attributes as $attribute) {
                if (!\in_array($attribute->shortName(), self::RELATION_KINDS, true)
                    || !str_contains($attribute->name, 'ORM')) {
                    continue;
                }

                $relation = [
                    'property' => $property->name,
                    'kind' => $attribute->shortName(),
                    'target' => $this->relationTarget($attribute, $property->type),
                ];
                $mappedBy = $this->argumentValue($attribute, 'mappedBy');
                if (null !== $mappedBy) {
                    $relation['mappedBy'] = $mappedBy;
                }
                $inversedBy = $this->argumentValue($attribute, 'inversedBy');
                if (null !== $inversedBy) {
                    $relation['inversedBy'] = $inversedBy;
                }

                $relations[] = $relation;
                break; // at most one association attribute per property
            }
        }

        return $relations;
    }

    /**
     * Determines the related entity: the explicit `targetEntity` argument when present,
     * otherwise the property's declared type (the modern typed-property form). Collection
     * types carry no usable target, so the owning side must rely on `targetEntity`.
     */
    private function relationTarget(AttributeInfo $attribute, ?string $propertyType): ?string
    {
        $explicit = $this->argumentValue($attribute, 'targetEntity');
        if (null !== $explicit && '' !== $explicit) {
            return $this->normalizeClassRef($explicit);
        }

        if (null === $propertyType) {
            return null;
        }

        $type = $this->normalizeClassRef($propertyType);
        $lastSlash = strrpos($type, '\\');
        $short = false === $lastSlash ? $type : substr($type, $lastSlash + 1);
        if (\in_array($short, ['Collection', 'ArrayCollection'], true)) {
            return null;
        }

        return $type;
    }

    /**
     * Returns the unquoted value of a named attribute argument, or null when absent.
     */
    private function argumentValue(AttributeInfo $attribute, string $name): ?string
    {
        foreach ($attribute->arguments as $argument) {
            if ($argument->name === $name) {
                return $this->unquote($argument->value);
            }
        }

        return null;
    }

    /**
     * Normalizes a class reference expression (`User::class`, `'App\\Entity\\User'`,
     * `?\\App\\Entity\\User`) into a bare class name/FQCN.
     */
    private function normalizeClassRef(string $value): string
    {
        $value = $this->unquote(trim($value));
        if (str_ends_with($value, '::class')) {
            $value = substr($value, 0, -\strlen('::class'));
        }

        return ltrim($value, '?\\');
    }

    private function unquote(string $value): string
    {
        $value = trim($value);
        if (\strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[\strlen($value) - 1];
            if (("'" === $first && "'" === $last) || ('"' === $first && '"' === $last)) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}
