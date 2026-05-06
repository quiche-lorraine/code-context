<?php

declare(strict_types=1);

namespace CodeContext\Extractor\Symfony;

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

        $entities = [];
        foreach ($context->classes as $class) {
            $isEntity = false;
            foreach ($class->attributes as $attribute) {
                if (str_starts_with($attribute, 'Doctrine\ORM\Mapping\Entity') || str_contains($attribute, '\ORM\Entity')) {
                    $isEntity = true;
                    break;
                }
            }
            if (!$isEntity) {
                continue;
            }

            $entities[] = [
                'class' => $class->fqcn,
                'file' => $class->file,
                'properties' => array_map(
                    static fn ($p): array => ['name' => $p->name, 'type' => $p->type, 'visibility' => $p->visibility],
                    $class->properties,
                ),
            ];
        }

        $context->symfony['entities'] = $entities;
    }
}
