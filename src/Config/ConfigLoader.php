<?php

declare(strict_types=1);

namespace CodeContext\Config;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads the bundled defaults and deep-merges an optional user configuration on top.
 */
final class ConfigLoader
{
    private const ROOT_KEY = 'code_context';

    public function __construct(
        private readonly string $defaultsPath,
        private readonly ConfigSchema $schema = new ConfigSchema(),
    )
    {
    }

    /**
     * @param string|null $userConfigPath Absolute path to the user YAML file; null disables the override.
     */
    public function load(?string $userConfigPath): Config
    {
        $defaults = $this->parse($this->defaultsPath);
        $defaultsTree = $this->extractRoot($defaults);

        if (null === $userConfigPath || '' === $userConfigPath || !is_file($userConfigPath)) {
            return new Config($this->schema->process($defaultsTree));
        }

        $userTree = $this->extractRoot($this->parse($userConfigPath));
        $merged = self::deepMerge($defaultsTree, $userTree);

        return new Config($this->schema->process($merged));
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(\sprintf('Configuration file not found: "%s".', $path));
        }

        $parsed = Yaml::parseFile($path);
        if (null === $parsed) {
            return [];
        }
        if (!\is_array($parsed)) {
            throw new \RuntimeException(\sprintf('Configuration file "%s" must contain a mapping at the root.', $path));
        }

        /** @var array<string, mixed> $parsed */
        return $parsed;
    }

    /**
     * @param array<string, mixed> $tree
     *
     * @return array<string, mixed>
     */
    private function extractRoot(array $tree): array
    {
        if (!\array_key_exists(self::ROOT_KEY, $tree)) {
            return $tree;
        }
        $sub = $tree[self::ROOT_KEY];

        return \is_array($sub) ? $sub : [];
    }

    /**
     * Recursively merges $override into $base. Sequential lists are replaced wholesale; associative
     * arrays are merged key by key. This matches user expectations for include/exclude lists and
     * scalar overrides.
     *
     * @param array<int|string, mixed> $base
     * @param array<int|string, mixed> $override
     *
     * @return array<int|string, mixed>
     */
    private static function deepMerge(array $base, array $override): array
    {
        if (self::isList($override)) {
            return $override;
        }

        foreach ($override as $key => $value) {
            if (\is_array($value) && isset($base[$key]) && \is_array($base[$key]) && !self::isList($value)) {
                $base[$key] = self::deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private static function isList(array $value): bool
    {
        return [] === $value || array_is_list($value);
    }
}
