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
                $routes[] = array_merge(
                    ['scope' => 'class', 'class' => $class->fqcn, 'method' => null, 'attribute' => $attribute],
                    self::parseRouteAttribute($attribute),
                );
            }
            foreach ($class->methods as $method) {
                foreach ($method->attributes as $attribute) {
                    if (!str_contains($attribute, 'Route(')) {
                        continue;
                    }
                    $routes[] = array_merge(
                        ['scope' => 'method', 'class' => $class->fqcn, 'method' => $method->name, 'attribute' => $attribute],
                        self::parseRouteAttribute($attribute),
                    );
                }
            }
        }

        $context->symfony['routes'] = $routes;
    }

    /**
     * Extracts `name`, `path` and `methods` from a rendered Route attribute string.
     *
     * Handles forms like:
     *   Route('/path', name: 'my_route', methods: ['GET', 'POST'])
     *   Route(path: '/path', name: 'my_route')
     *
     * @return array{name: ?string, path: ?string, methods: list<string>}
     */
    private static function parseRouteAttribute(string $attribute): array
    {
        $name = null;
        $path = null;
        $methods = [];

        // Named `name:` argument
        if (preg_match("/\\bname:\\s*['\"]([^'\"]+)['\"]/", $attribute, $m)) {
            $name = $m[1];
        }

        // Named `path:` argument, or first positional string (the route path)
        if (preg_match("/\\bpath:\\s*['\"]([^'\"]+)['\"]/", $attribute, $m)) {
            $path = $m[1];
        } elseif (preg_match("/Route\\(['\"]([^'\"]+)['\"]/", $attribute, $m)) {
            $path = $m[1];
        }

        // `methods:` array argument
        if (preg_match("/\\bmethods:\\s*\\[([^\\]]+)\\]/", $attribute, $m)) {
            $parts = preg_split('/\s*,\s*/', $m[1]) ?: [];
            foreach ($parts as $part) {
                $clean = trim($part, " '\"\t");
                if ('' !== $clean) {
                    $methods[] = strtoupper($clean);
                }
            }
        }

        return ['name' => $name, 'path' => $path, 'methods' => $methods];
    }
}
