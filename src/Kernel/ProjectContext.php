<?php

declare(strict_types=1);

namespace CodeContext\Kernel;

/**
 * Resolves and exposes filesystem locations of the project being analyzed.
 */
final readonly class ProjectContext
{
    public function __construct(
        public string $rootDir,
        public string $cwd,
    ) {
    }

    public static function create(string $cwd, string $configuredRoot): self
    {
        $resolvedRoot = self::resolvePath($cwd, $configuredRoot);

        if (!is_dir($resolvedRoot)) {
            throw new \RuntimeException(\sprintf('Configured project root "%s" does not exist.', $resolvedRoot));
        }

        return new self(rootDir: $resolvedRoot, cwd: $cwd);
    }

    public function absolutePath(string $relative): string
    {
        return self::resolvePath($this->rootDir, $relative);
    }

    /**
     * Returns $path resolved to an absolute, normalized path. If $path is already absolute it is
     * normalized as-is, otherwise it is resolved against $base.
     */
    private static function resolvePath(string $base, string $path): string
    {
        if ('' === $path) {
            return rtrim($base, \DIRECTORY_SEPARATOR);
        }

        if (self::isAbsolute($path)) {
            $resolved = $path;
        } else {
            $resolved = rtrim($base, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . $path;
        }

        $real = realpath($resolved);

        return false !== $real ? $real : rtrim($resolved, \DIRECTORY_SEPARATOR);
    }

    private static function isAbsolute(string $path): bool
    {
        return '' !== $path && (\DIRECTORY_SEPARATOR === $path[0] || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);
    }
}
