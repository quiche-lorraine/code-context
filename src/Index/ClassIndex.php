<?php

declare(strict_types=1);

namespace CodeContext\Index;

/**
 * In-memory query layer built from a deserialized context.json.
 *
 * All lookups run in O(1) or O(k) (result-set size) after an O(n) build pass.
 */
final class ClassIndex
{
    /** @var array<string, array<string, mixed>> FQCN → full class array */
    private array $byFqcn = [];

    /** @var array<string, list<string>> lowercase short_name → list of FQCNs */
    private array $byShortName = [];

    /** @var array<string, list<array{class: string, method: array<string, mixed>}>> lowercase method name → list */
    private array $byMethod = [];

    /** @var array<string, list<string>> namespace prefix → list of FQCNs */
    private array $byNamespace = [];

    /** @var array<string, list<string>> interface FQCN → implementing FQCNs */
    private array $implementors = [];

    /** @var array<string, list<string>> parent FQCN → child FQCNs */
    private array $subclasses = [];

    /** @var array<string, list<string>> type FQCN → FQCNs referencing it */
    private array $typeUsages = [];

    /** @var list<array<string, mixed>> */
    private array $routes = [];

    /**
     * @param array<string, mixed> $contextData Decoded context.json top-level array.
     */
    public static function fromContextArray(array $contextData): self
    {
        $index = new self();

        $php = \is_array($contextData['php'] ?? null) ? $contextData['php'] : [];

        // Build class lookups
        $classes = \is_array($php['classes'] ?? null) ? $php['classes'] : [];
        foreach ($classes as $class) {
            if (!\is_array($class) || !isset($class['fqcn'])) {
                continue;
            }
            $fqcn = (string) $class['fqcn'];
            $index->byFqcn[$fqcn] = $class;

            $short = strtolower((string) ($class['short_name'] ?? ''));
            if ('' !== $short) {
                $index->byShortName[$short][] = $fqcn;
            }

            $ns = (string) ($class['namespace'] ?? '');
            $index->byNamespace[$ns][] = $fqcn;

            foreach ((array) ($class['methods'] ?? []) as $method) {
                if (!\is_array($method) || !isset($method['name'])) {
                    continue;
                }
                $mName = strtolower((string) $method['name']);
                $index->byMethod[$mName][] = ['class' => $fqcn, 'method' => $method];
            }
        }

        // Load pre-built cross-reference graph
        $graph = \is_array($php['graph'] ?? null) ? $php['graph'] : [];

        foreach ((array) ($graph['implementors'] ?? []) as $iface => $impls) {
            $index->implementors[(string) $iface] = array_values(array_map('strval', (array) $impls));
        }
        foreach ((array) ($graph['subclasses'] ?? []) as $parent => $children) {
            $index->subclasses[(string) $parent] = array_values(array_map('strval', (array) $children));
        }
        foreach ((array) ($graph['type_usages'] ?? []) as $type => $users) {
            $index->typeUsages[(string) $type] = array_values(array_map('strval', (array) $users));
        }

        // Symfony routes
        $symfony = \is_array($contextData['symfony'] ?? null) ? $contextData['symfony'] : [];
        $index->routes = \is_array($symfony['routes'] ?? null)
            ? array_values(array_filter($symfony['routes'], 'is_array'))
            : [];

        return $index;
    }

    /**
     * Fuzzy search across FQCNs, short names, and method names.
     *
     * @return list<array<string, mixed>>
     */
    public function searchSymbol(string $query, ?string $kind = null): array
    {
        $q = strtolower(trim($query));
        if ('' === $q) {
            return [];
        }

        $hits = [];
        $seen = [];

        // Exact short-name match first
        foreach ($this->byShortName as $name => $fqcns) {
            if (str_contains($name, $q)) {
                foreach ($fqcns as $fqcn) {
                    if (!isset($seen[$fqcn])) {
                        $seen[$fqcn] = true;
                        $class = $this->byFqcn[$fqcn] ?? null;
                        if (null !== $class && $this->matchesKind($class, $kind)) {
                            $hits[] = $this->summarize($class);
                        }
                    }
                }
            }
        }

        // FQCN substring match
        foreach ($this->byFqcn as $fqcn => $class) {
            if (!isset($seen[$fqcn]) && str_contains(strtolower($fqcn), $q)) {
                $seen[$fqcn] = true;
                if ($this->matchesKind($class, $kind)) {
                    $hits[] = $this->summarize($class);
                }
            }
        }

        // Method name match — return containing class (deduplicated)
        foreach ($this->byMethod as $mName => $entries) {
            if (str_contains($mName, $q)) {
                foreach ($entries as $entry) {
                    $fqcn = $entry['class'];
                    if (!isset($seen[$fqcn])) {
                        $seen[$fqcn] = true;
                        $class = $this->byFqcn[$fqcn] ?? null;
                        if (null !== $class && $this->matchesKind($class, $kind)) {
                            $hits[] = $this->summarize($class);
                        }
                    }
                }
            }
        }

        return array_slice($hits, 0, 30);
    }

    /**
     * Returns the full class detail array, or null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function getClass(string $fqcn): ?array
    {
        return $this->byFqcn[$fqcn] ?? $this->byFqcn[$this->resolveShortName($fqcn)] ?? null;
    }

    /**
     * Returns FQCNs of all classes implementing the given interface.
     *
     * @return list<string>
     */
    public function findImplementations(string $interfaceFqcn): array
    {
        return $this->implementors[$interfaceFqcn]
            ?? $this->implementors[$this->resolveShortName($interfaceFqcn)]
            ?? [];
    }

    /**
     * Returns FQCNs of all classes extending the given class.
     *
     * @return list<string>
     */
    public function findSubclasses(string $parentFqcn): array
    {
        return $this->subclasses[$parentFqcn]
            ?? $this->subclasses[$this->resolveShortName($parentFqcn)]
            ?? [];
    }

    /**
     * Returns FQCNs of all classes that reference the given type in constructor or properties.
     *
     * @return list<string>
     */
    public function findUsages(string $fqcn): array
    {
        return $this->typeUsages[$fqcn]
            ?? $this->typeUsages[$this->resolveShortName($fqcn)]
            ?? [];
    }

    /**
     * Returns all routes, optionally filtered by a substring on the controller or path attribute.
     *
     * @return list<array<string, mixed>>
     */
    public function getRoutes(?string $filter = null): array
    {
        if (null === $filter || '' === $filter) {
            return $this->routes;
        }

        $f = strtolower($filter);

        return array_values(array_filter(
            $this->routes,
            static function (array $route) use ($f): bool {
                return str_contains(strtolower((string) ($route['class'] ?? '')), $f)
                    || str_contains(strtolower((string) ($route['attribute'] ?? '')), $f)
                    || str_contains(strtolower((string) ($route['method'] ?? '')), $f);
            },
        ));
    }

    /**
     * Returns all classes whose namespace starts with the given prefix.
     *
     * @return list<array<string, mixed>>
     */
    public function getNamespace(string $namespace): array
    {
        $prefix = rtrim($namespace, '\\');
        $result = [];

        foreach ($this->byNamespace as $ns => $fqcns) {
            if ('' === $prefix || $ns === $prefix || str_starts_with($ns, $prefix . '\\')) {
                foreach ($fqcns as $fqcn) {
                    $class = $this->byFqcn[$fqcn] ?? null;
                    if (null !== $class) {
                        $result[] = $this->summarize($class);
                    }
                }
            }
        }

        usort($result, static fn (array $a, array $b): int => ($a['fqcn'] ?? '') <=> ($b['fqcn'] ?? ''));

        return $result;
    }

    /**
     * Returns a compact summary of a class (no method bodies, trimmed methods list).
     *
     * @param array<string, mixed> $class
     *
     * @return array<string, mixed>
     */
    private function summarize(array $class): array
    {
        return [
            'fqcn' => $class['fqcn'] ?? '',
            'kind' => $class['kind'] ?? 'class',
            'file' => $class['file'] ?? '',
            'namespace' => $class['namespace'] ?? '',
            'extends' => $class['extends'] ?? null,
            'implements' => $class['implements'] ?? [],
            'summary' => $class['summary'] ?? null,
            'method_count' => \count((array) ($class['methods'] ?? [])),
        ];
    }

    /**
     * If $fqcn contains no backslash, tries to resolve it via short-name lookup.
     * Returns the first matching FQCN, or the original value if not found.
     */
    private function resolveShortName(string $fqcn): string
    {
        if (str_contains($fqcn, '\\')) {
            return $fqcn;
        }

        $matches = $this->byShortName[strtolower($fqcn)] ?? [];

        return [] !== $matches ? $matches[0] : $fqcn;
    }

    /**
     * @param array<string, mixed> $class
     */
    private function matchesKind(array $class, ?string $kind): bool
    {
        if (null === $kind || '' === $kind) {
            return true;
        }

        return strtolower((string) ($class['kind'] ?? '')) === strtolower($kind);
    }
}
