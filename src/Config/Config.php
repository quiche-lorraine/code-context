<?php

declare(strict_types=1);

namespace CodeContext\Config;

/**
 * Immutable, typed view over the merged configuration tree.
 *
 * The tree is intentionally accessed through getters rather than exposed as a raw array so
 * that downstream components remain decoupled from the YAML schema.
 */
final readonly class Config
{
    /**
     * @param array<string, mixed> $tree The merged configuration tree, rooted at "code_context".
     */
    public function __construct(private array $tree)
    {
    }

    /**
     * @return list<string>
     */
    public function includePaths(): array
    {
        return $this->getList('paths.include');
    }

    /**
     * @return list<string>
     */
    public function excludePaths(): array
    {
        return $this->getList('paths.exclude');
    }

    public function projectRoot(): string
    {
        return (string) $this->get('paths.root', '.');
    }

    public function outputDirectory(): string
    {
        return (string) $this->get('output.directory', '.code-context/');
    }

    public function jsonFile(): string
    {
        return (string) $this->get('output.files.json', 'context.json');
    }

    public function agentsMdFile(): string
    {
        return (string) $this->get('output.files.agents_md', 'AGENTS.md');
    }

    public function architectureMdFile(): string
    {
        return (string) $this->get('output.files.thematic.architecture', 'architecture.md');
    }

    public function routesMdFile(): string
    {
        return (string) $this->get('output.files.thematic.routes', 'routes.md');
    }

    public function entitiesMdFile(): string
    {
        return (string) $this->get('output.files.thematic.entities', 'entities.md');
    }

    public function servicesMdFile(): string
    {
        return (string) $this->get('output.files.thematic.services', 'services.md');
    }

    public function commandsMdFile(): string
    {
        return (string) $this->get('output.files.thematic.commands', 'commands.md');
    }

    public function phpIncludeSignatures(): bool
    {
        return (bool) $this->get('analyzers.php.include_signatures', true);
    }

    public function phpIncludeProperties(): bool
    {
        return (bool) $this->get('analyzers.php.include_properties', true);
    }

    public function phpDocLevel(): string
    {
        $level = (string) $this->get('analyzers.php.include_phpdoc', 'first_line');

        return \in_array($level, ['none', 'first_line', 'full'], true) ? $level : 'first_line';
    }

    public function phpIncludeMethodBodies(): bool
    {
        return (bool) $this->get('analyzers.php.include_method_bodies', false);
    }

    public function phpIncludePrivate(): bool
    {
        return (bool) $this->get('analyzers.php.include_private', false);
    }

    public function phpIncludeProtected(): bool
    {
        return (bool) $this->get('analyzers.php.include_protected', true);
    }

    public function phpIncludeAttributes(): bool
    {
        return (bool) $this->get('analyzers.php.include_attributes', true);
    }

    public function jsonPretty(): bool
    {
        return (bool) $this->get('rendering.json.pretty', true);
    }

    public function jsonStripNulls(): bool
    {
        return (bool) $this->get('rendering.json.strip_nulls', true);
    }

    public function markdownGroupByNamespace(): bool
    {
        return (bool) $this->get('rendering.markdown.group_by_namespace', true);
    }

    public function markdownIncludeToc(): bool
    {
        return (bool) $this->get('rendering.markdown.include_toc', true);
    }

    public function extractorComposerEnabled(): bool
    {
        return (bool) $this->get('extractors.composer', true);
    }

    public function extractorDocsEnabled(): bool
    {
        return (bool) $this->get('extractors.docs.enabled', true);
    }

    /**
     * @return list<string>
     */
    public function docsPatterns(): array
    {
        return $this->getList('extractors.docs.patterns');
    }

    public function docsIncludeFullContent(): bool
    {
        return (bool) $this->get('extractors.docs.include_full_content', false);
    }

    public function symfonyAutoDetect(): bool
    {
        return (bool) $this->get('extractors.symfony.auto_detect', true);
    }

    public function symfonyRoutesEnabled(): bool
    {
        return (bool) $this->get('extractors.symfony.routes', true);
    }

    public function symfonyServicesEnabled(): bool
    {
        return (bool) $this->get('extractors.symfony.services', true);
    }

    public function symfonyEntitiesEnabled(): bool
    {
        return (bool) $this->get('extractors.symfony.entities', true);
    }

    public function symfonyCommandsEnabled(): bool
    {
        return (bool) $this->get('extractors.symfony.commands', true);
    }

    /**
     * Dot-path getter with a default fallback.
     */
    private function get(string $path, mixed $default = null): mixed
    {
        $node = $this->tree;
        foreach (explode('.', $path) as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * @return list<string>
     */
    private function getList(string $path): array
    {
        $value = $this->get($path, []);
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $v): string => (string) $v, $value),
            static fn (string $v): bool => '' !== $v,
        ));
    }
}
