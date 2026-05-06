<?php

declare(strict_types=1);

namespace CodeContext\Extractor\Symfony;

use CodeContext\Config\Config;
use CodeContext\Kernel\ProjectContext;
use CodeContext\Model\Context;

final class RouteExtractor
{
    public function extract(ProjectContext $project, Config $config, Context $context): void
    {
        if (!$config->symfonyRoutesEnabled()) {
            return;
        }

        $routes = [];
        foreach ($context->classes as $class) {
            foreach ($class->attributes as $attribute) {
                if (!str_contains($attribute, 'Route(')) {
                    continue;
                }
                $routes[] = [
                    'scope' => 'class',
                    'class' => $class->fqcn,
                    'method' => null,
                    'attribute' => $attribute,
                ];
            }
            foreach ($class->methods as $method) {
                foreach ($method->attributes as $attribute) {
                    if (!str_contains($attribute, 'Route(')) {
                        continue;
                    }
                    $routes[] = [
                        'scope' => 'method',
                        'class' => $class->fqcn,
                        'method' => $method->name,
                        'attribute' => $attribute,
                    ];
                }
            }
        }

        $context->symfony['routes'] = $routes;
    }
}
