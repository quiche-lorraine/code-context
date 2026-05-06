<?php

declare(strict_types=1);

namespace CodeContext\Model;

final class Context
{
    /**
     * @param list<ClassInfo>      $classes
     * @param list<FunctionInfo>   $functions
     * @param array<string, mixed> $graph
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
        public array $functions = [],
        /** @var array<string, array<string, list<string>>> */
        public array $graph = [],
        /** @var list<string> */
        public array $entryPoints = [],
        /** @var array<string, mixed> */
        public array $vendorContracts = [],
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
                'entry_points' => [] !== $this->entryPoints ? $this->entryPoints : null,
            ],
            'php' => [
                'summary' => $this->phpSummary,
                'graph' => $this->graph,
                'classes' => array_map(static fn (ClassInfo $c): array => $c->toArray(), $this->classes),
                'functions' => [] !== $this->functions
                    ? array_map(static fn (FunctionInfo $f): array => $f->toArray(), $this->functions)
                    : null,
            ],
            'composer' => $this->composer,
            'docs' => $this->docs,
            'symfony' => $this->symfony,
            'vendor_contracts' => [] !== $this->vendorContracts ? $this->vendorContracts : null,
        ];
    }
}
