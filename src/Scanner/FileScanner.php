<?php

declare(strict_types=1);

namespace CodeContext\Scanner;

use CodeContext\Kernel\ProjectContext;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Scans the project filesystem according to include/exclude rules and yields PHP files.
 */
final class FileScanner
{
    /**
     * @param list<string> $include Relative paths (directories or files) to include.
     * @param list<string> $exclude Relative paths (directories or files) to exclude.
     *
     * @return iterable<SplFileInfo>
     */
    public function scan(ProjectContext $project, array $include, array $exclude): iterable
    {
        $directories = [];
        $singleFiles = [];

        foreach ($include as $entry) {
            $absolute = $project->absolutePath($entry);
            if (is_dir($absolute)) {
                $directories[] = $absolute;
            } elseif (is_file($absolute) && self::isPhp($absolute)) {
                $singleFiles[] = $absolute;
            }
        }

        if ([] === $directories && [] === $singleFiles) {
            return [];
        }

        $excludeDirNames = self::buildExcludeNames($exclude);

        $finder = new Finder();
        $finder
            ->files()
            ->name('*.php')
            ->ignoreUnreadableDirs()
            ->ignoreVCS(true);

        if ([] !== $directories) {
            $finder->in($directories);
        }

        foreach ($excludeDirNames as $name) {
            $finder->exclude($name);
        }

        foreach ($singleFiles as $file) {
            $finder->append([new SplFileInfo($file, '', basename($file))]);
        }

        return $finder;
    }

    /**
     * Finder::exclude() expects directory names relative to the search roots, not absolute paths.
     * We strip leading slashes and trailing separators so users can write "vendor/" or "/vendor".
     *
     * @param list<string> $exclude
     *
     * @return list<string>
     */
    private static function buildExcludeNames(array $exclude): array
    {
        $names = [];
        foreach ($exclude as $entry) {
            $clean = trim($entry, " \t\n\r/\\");
            if ('' !== $clean) {
                $names[] = $clean;
            }
        }

        return $names;
    }

    private static function isPhp(string $path): bool
    {
        return 'php' === strtolower(pathinfo($path, \PATHINFO_EXTENSION));
    }
}
