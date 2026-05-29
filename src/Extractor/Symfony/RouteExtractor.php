<?php

declare(strict_types=1);

namespace CodeContext\Extractor\Symfony;

use CodeContext\Config\Config;
use CodeContext\Kernel\ProjectContext;
use CodeContext\Model\AttributeInfo;
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
                if (!self::isRoute($attribute)) {
                    continue;
                }
                $routes[] = array_merge(
                    ['scope' => 'class', 'class' => $class->fqcn, 'method' => null, 'attribute' => $attribute->render()],
                    self::parseRouteAttribute($attribute),
                );
            }
            foreach ($class->methods as $method) {
                foreach ($method->attributes as $attribute) {
                    if (!self::isRoute($attribute)) {
                        continue;
                    }
                    $routes[] = array_merge(
                        ['scope' => 'method', 'class' => $class->fqcn, 'method' => $method->name, 'attribute' => $attribute->render()],
                        self::parseRouteAttribute($attribute),
                    );
                }
            }
        }

        $context->symfony['routes'] = $routes;
    }

    private static function isRoute(AttributeInfo $attribute): bool
    {
        return 'Route' === $attribute->shortName();
    }

    /**
     * Extracts `name`, `path` and `methods` from a structured Route attribute.
     *
     * Handles forms like:
     *   Route('/path', name: 'my_route', methods: ['GET', 'POST'])
     *   Route(path: '/path', name: 'my_route', methods: [Request::METHOD_GET])
     *
     * @return array{name: ?string, path: ?string, methods: list<string>}
     */
    private static function parseRouteAttribute(AttributeInfo $attribute): array
    {
        $name = null;
        $path = null;
        $methods = [];
        $firstPositional = null;

        foreach ($attribute->arguments as $arg) {
            switch ($arg->name) {
                case 'path':
                    $path = self::unquote($arg->value);
                    break;
                case 'name':
                    $name = self::unquote($arg->value);
                    break;
                case 'methods':
                    $methods = self::parseMethods($arg->value);
                    break;
                case null:
                    $firstPositional ??= self::unquote($arg->value);
                    break;
            }
        }

        // The first positional argument is the route path when no named `path:` was given.
        if (null === $path && null !== $firstPositional) {
            $path = $firstPositional;
        }

        return ['name' => $name, 'path' => $path, 'methods' => $methods];
    }

    /**
     * Parses a `methods` array value such as `['GET', 'POST']` or `[Request::METHOD_GET]`.
     *
     * @return list<string>
     */
    private static function parseMethods(string $value): array
    {
        $inner = trim($value);
        $inner = trim($inner, '[]');
        if ('' === $inner) {
            return [];
        }

        $methods = [];
        foreach (explode(',', $inner) as $part) {
            $clean = self::unquote(trim($part));
            // Resolve class constants like `Request::METHOD_GET` to `GET`.
            $sep = strrpos($clean, '::');
            if (false !== $sep) {
                $clean = substr($clean, $sep + 2);
                if (str_starts_with($clean, 'METHOD_')) {
                    $clean = substr($clean, \strlen('METHOD_'));
                }
            }
            $clean = strtoupper($clean);
            if ('' !== $clean) {
                $methods[] = $clean;
            }
        }

        return $methods;
    }

    private static function unquote(string $value): string
    {
        return trim(trim($value), "'\"");
    }
}
