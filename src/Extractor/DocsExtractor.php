<?php

declare(strict_types=1);

namespace CodeContext\Extractor;

use CodeContext\Config\Config;
use CodeContext\Kernel\ProjectContext;
use CodeContext\Model\Context;

final class DocsExtractor implements ExtractorInterface
{
    public function name(): string
    {
        return 'docs';
    }

    public function supports(ProjectContext $project, Config $config, Context $context): bool
    {
        return $config->extractorDocsEnabled();
    }

    public function extract(ProjectContext $project, Config $config, Context $context): void
    {
        $patterns = $config->docsPatterns();
        if ([] === $patterns) {
            return;
        }

        $summaryMaxChars = $config->docsSummaryMaxChars();
        $contentMaxBytes = $config->docsContentMaxBytes();

        $docs = [];
        foreach ($this->matchingFiles($project->rootDir, $patterns) as $absolute) {
            $raw = file_get_contents($absolute);
            if (false === $raw) {
                continue;
            }
            $contents = $this->stripFrontmatter($raw);
            $summary = $this->extractSummary($contents, $summaryMaxChars);
            $fullContent = null;
            if ($config->docsIncludeFullContent()) {
                $fullContent = \strlen($raw) > $contentMaxBytes
                    ? substr($raw, 0, $contentMaxBytes) . "\n…[truncated]"
                    : $raw;
            }
            $docs[] = [
                'path' => $this->relativePath($project->rootDir, $absolute),
                'title' => $this->extractTitle($contents) ?? basename($absolute),
                'summary' => $summary,
                'content' => $fullContent,
            ];
        }
        usort($docs, static fn (array $a, array $b): int => $a['path'] <=> $b['path']);
        $context->docs = $docs;
    }

    /**
     * @param list<string> $patterns
     *
     * @return list<string>
     */
    private function matchingFiles(string $root, array $patterns): array
    {
        $matches = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $absolute = $file->getPathname();
            $relative = $this->relativePath($root, $absolute);
            foreach ($patterns as $pattern) {
                if (fnmatch($pattern, $relative)) {
                    $matches[$absolute] = $absolute;
                    break;
                }
            }
        }

        return array_values($matches);
    }

    private function extractTitle(string $contents): ?string
    {
        foreach (preg_split('/\R/u', $contents) ?: [] as $line) {
            $trim = trim($line);
            if (str_starts_with($trim, '# ')) {
                return trim(substr($trim, 2));
            }
        }

        return null;
    }

    private function extractSummary(string $contents, int $maxChars): ?string
    {
        $lines = preg_split('/\R/u', $contents) ?: [];
        $text = '';
        foreach ($lines as $line) {
            $trim = trim($line);
            if ('' === $trim) {
                continue;
            }
            // Stop at the next heading (signals a new section)
            if ('' !== $text && str_starts_with($trim, '#')) {
                break;
            }
            // Skip the main title heading itself
            if ('' === $text && str_starts_with($trim, '# ')) {
                continue;
            }
            $text .= ('' !== $text ? ' ' : '') . $trim;
            if (\strlen($text) >= $maxChars) {
                $text = substr($text, 0, $maxChars) . '…';
                break;
            }
        }

        return '' === $text ? null : $text;
    }

    private function stripFrontmatter(string $contents): string
    {
        if (!str_starts_with(ltrim($contents), '---')) {
            return $contents;
        }

        $end = strpos($contents, '---', 3);
        if (false === $end) {
            return $contents;
        }

        return ltrim(substr($contents, $end + 3));
    }

    private function relativePath(string $base, string $absolute): string
    {
        $base = rtrim($base, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;
        if (str_starts_with($absolute, $base)) {
            return substr($absolute, \strlen($base));
        }

        return $absolute;
    }
}
