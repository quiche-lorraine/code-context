<?php

declare(strict_types=1);

namespace CodeContext\Extractor\Symfony;

use CodeContext\Analyzer\PhpAstAnalyzer;
use CodeContext\Config\Config;
use CodeContext\Kernel\ProjectContext;
use CodeContext\Model\Context;

final class EntityExtractor
{
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
                if (str_starts_with($attribute, 'Doctrine\\ORM\\Mapping\\Entity')
                    || str_contains($attribute, '\\ORM\\Entity')) {
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
                        'attributes' => $p->attributes,
                    ],
                    $entityClass->properties,
                ),
            ];
        }

        $context->symfony['entities'] = $entities;
        $context->symfony['entity_enums'] = $enums;
    }
}
