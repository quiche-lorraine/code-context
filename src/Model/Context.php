<?php

declare(strict_types=1);

namespace CodeContext\Model;

final class Context
{
    /**
     * @param list<ClassInfo> $classes
     */
    public function __construct(
        public readonly string $projectRoot,
        public readonly string $generatedAt,
        public readonly int $projectCharacters = 0,
        public readonly int $estimatedTokens = 0,
        /** @var array<string, mixed> */
        public array $composer = [],
        /** @var list<array<string, mixed>> */
        public array $docs = [],
        /** @var array<string, mixed> */
        public array $symfony = [],
        /** @var array<string, mixed> */
        public array $phpSummary = [],
        public array $classes = [],
    ) {
    }

    /**
     * @return array<string, list<ClassInfo>>
     */
    public function classesByNamespace(): array
    {
        $grouped = [];
        foreach ($this->classes as $class) {
            $grouped[$class->namespace][] = $class;
        }
        ksort($grouped);

        return $grouped;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'project' => [
                'root' => $this->projectRoot,
                'generated_at' => $this->generatedAt,
                'characters' => $this->projectCharacters,
                'estimated_tokens' => $this->estimatedTokens,
            ],
            'php' => [
                'summary' => $this->phpSummary,
                'classes' => array_map(static fn (ClassInfo $c): array => $c->toArray(), $this->classes),
            ],
            'composer' => $this->composer,
            'docs' => $this->docs,
            'symfony' => $this->symfony,
        ];
    }
}
