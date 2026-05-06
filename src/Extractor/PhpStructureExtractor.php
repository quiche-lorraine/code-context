<?php

declare(strict_types=1);

namespace CodeContext\Extractor;

use CodeContext\Config\Config;
use CodeContext\Kernel\ProjectContext;
use CodeContext\Model\Context;

final class PhpStructureExtractor implements ExtractorInterface
{
    public function name(): string
    {
        return 'php_structure';
    }

    public function supports(ProjectContext $project, Config $config, Context $context): bool
    {
        return true;
    }

    public function extract(ProjectContext $project, Config $config, Context $context): void
    {
        $kinds = [
            'class' => 0,
            'interface' => 0,
            'trait' => 0,
            'enum' => 0,
            'unknown' => 0,
        ];
        $methods = 0;
        $properties = 0;
        $namespaces = [];
        foreach ($context->classes as $class) {
            $kinds[$class->kind] = ($kinds[$class->kind] ?? 0) + 1;
            $methods += \count($class->methods);
            $properties += \count($class->properties);
            $namespaces[$class->namespace] = true;
        }
        $context->phpSummary = [
            'classes_total' => \count($context->classes),
            'namespaces_total' => \count($namespaces),
            'methods_total' => $methods,
            'properties_total' => $properties,
            'kinds' => $kinds,
        ];
    }
}
