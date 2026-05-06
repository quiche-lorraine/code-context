<?php

declare(strict_types=1);

namespace CodeContext\Renderer\Markdown;

use CodeContext\Model\Context;

final class RoutesMdRenderer
{
    public function render(Context $context): string
    {
        $routes = \is_array($context->symfony['routes'] ?? null) ? $context->symfony['routes'] : [];
        $lines = ['# routes.md', ''];
        if ([] === $routes) {
            $lines[] = '> No Symfony routes detected.';

            return implode("\n", $lines) . "\n";
        }
        foreach ($routes as $route) {
            if (!\is_array($route)) {
                continue;
            }
            $lines[] = sprintf(
                '- `%s::%s` — `%s`',
                (string) ($route['class'] ?? '?'),
                (string) (($route['method'] ?? null) ?: '__class__'),
                (string) ($route['attribute'] ?? ''),
            );
        }

        return implode("\n", $lines) . "\n";
    }
}
