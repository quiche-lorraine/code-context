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

    /** @var list<array<string, mixed>> */
    private array $commands = [];

    /** @var list<array<string, mixed>> */
    private array $entities = [];

    /** @var list<array<string, mixed>> */
    private array $entityEnums = [];

    /** @var list<array<string, mixed>> */
    private array $servicesAutowired = [];

    /** @var list<array<string, mixed>> */
    private array $servicesConfigured = [];

    /**
     * Inverted index: lowercased last-segment of an attribute name → list of usages.
     *
     * @var array<string, list<array{scope: string, fqcn: string, member: ?string, raw: string}>>
     */
    private array $attributeIndex = [];

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

            foreach ((array) ($class['attributes'] ?? []) as $attr) {
                if (\is_string($attr)) {
                    $index->indexAttribute('class', $fqcn, null, $attr);
                }
            }

            foreach ((array) ($class['methods'] ?? []) as $method) {
                if (!\is_array($method) || !isset($method['name'])) {
                    continue;
                }
                $mName = strtolower((string) $method['name']);
                $index->byMethod[$mName][] = ['class' => $fqcn, 'method' => $method];
                foreach ((array) ($method['attributes'] ?? []) as $attr) {
                    if (\is_string($attr)) {
                        $index->indexAttribute('method', $fqcn, (string) $method['name'], $attr);
                    }
                }
            }

            foreach ((array) ($class['properties'] ?? []) as $prop) {
                if (!\is_array($prop) || !isset($prop['name'])) {
                    continue;
                }
                foreach ((array) ($prop['attributes'] ?? []) as $attr) {
                    if (\is_string($attr)) {
                        $index->indexAttribute('property', $fqcn, (string) $prop['name'], $attr);
                    }
                }
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

        // Symfony sections
        $symfony = \is_array($contextData['symfony'] ?? null) ? $contextData['symfony'] : [];
        $index->routes = \is_array($symfony['routes'] ?? null)
            ? array_values(array_filter($symfony['routes'], 'is_array'))
            : [];
        $index->commands = \is_array($symfony['commands'] ?? null)
            ? array_values(array_filter($symfony['commands'], 'is_array'))
            : [];
        $index->entities = \is_array($symfony['entities'] ?? null)
            ? array_values(array_filter($symfony['entities'], 'is_array'))
            : [];
        $index->entityEnums = \is_array($symfony['entity_enums'] ?? null)
            ? array_values(array_filter($symfony['entity_enums'], 'is_array'))
            : [];

        $services = \is_array($symfony['services'] ?? null) ? $symfony['services'] : [];
        $index->servicesAutowired = \is_array($services['autowired'] ?? null)
            ? array_values(array_filter($services['autowired'], 'is_array'))
            : [];
        $index->servicesConfigured = \is_array($services['configured'] ?? null)
            ? array_values(array_filter($services['configured'], 'is_array'))
            : [];

        return $index;
    }

    /**
     * Returns the last `\\`-separated segment of an attribute name (e.g. `ORM\Column` → `Column`).
     */
    private static function attributeShortName(string $attribute): string
    {
        $name = $attribute;
        $parenPos = strpos($name, '(');
        if (false !== $parenPos) {
            $name = substr($name, 0, $parenPos);
        }
        $name = trim($name, "\\ \t");
        $lastSlash = strrpos($name, '\\');

        return false === $lastSlash ? $name : substr($name, $lastSlash + 1);
    }

    private function indexAttribute(string $scope, string $fqcn, ?string $member, string $attribute): void
    {
        $short = strtolower(self::attributeShortName($attribute));
        if ('' === $short) {
            return;
        }
        $this->attributeIndex[$short][] = [
            'scope' => $scope,
            'fqcn' => $fqcn,
            'member' => $member,
            'raw' => $attribute,
        ];
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
     * @return list<array<string, mixed>>
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCommand(string $name): ?array
    {
        foreach ($this->commands as $command) {
            if (($command['name'] ?? null) === $name) {
                return $command;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getEntities(): array
    {
        return $this->entities;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getEntity(string $fqcn): ?array
    {
        $resolved = $this->resolveShortName($fqcn);
        foreach ($this->entities as $entity) {
            $cls = (string) ($entity['class'] ?? '');
            if ($cls === $fqcn || $cls === $resolved) {
                return $entity;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getEntityEnums(): array
    {
        return $this->entityEnums;
    }

    /**
     * @return array{configured: list<array<string, mixed>>, autowired: list<array<string, mixed>>}
     */
    public function findService(string $query): array
    {
        $q = strtolower(trim($query));
        if ('' === $q) {
            return ['configured' => $this->servicesConfigured, 'autowired' => $this->servicesAutowired];
        }

        $configured = array_values(array_filter(
            $this->servicesConfigured,
            static function (array $svc) use ($q): bool {
                $id = strtolower((string) ($svc['id'] ?? ''));
                $definition = \is_array($svc['definition'] ?? null) ? $svc['definition'] : [];
                $class = strtolower((string) ($definition['class'] ?? ''));

                return str_contains($id, $q) || str_contains($class, $q);
            },
        ));

        $autowired = array_values(array_filter(
            $this->servicesAutowired,
            static function (array $svc) use ($q): bool {
                $fqcn = strtolower((string) ($svc['fqcn'] ?? ''));
                $namespace = strtolower((string) ($svc['namespace'] ?? ''));

                return str_contains($fqcn, $q) || str_contains($namespace, $q);
            },
        ));

        return ['configured' => $configured, 'autowired' => $autowired];
    }

    /**
     * Finds usages of a PHP attribute across classes, methods and properties.
     *
     * @param string $needle Full or short attribute name (case-insensitive)
     * @param string $scope  One of `class`, `method`, `property`, `all`
     *
     * @return list<array{scope: string, fqcn: string, member: ?string, raw: string}>
     */
    public function findByAttribute(string $needle, string $scope = 'all'): array
    {
        $needle = trim($needle);
        if ('' === $needle) {
            return [];
        }

        $needleShort = strtolower(self::attributeShortName($needle));
        $needleLower = strtolower(ltrim($needle, '\\'));

        $hits = [];
        $seen = [];

        $emit = static function (array $entry) use (&$hits, &$seen): void {
            $key = $entry['scope'] . '|' . $entry['fqcn'] . '|' . ($entry['member'] ?? '') . '|' . $entry['raw'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $hits[] = $entry;
            }
        };

        // Fast path: exact short-name match against the inverted index.
        if (isset($this->attributeIndex[$needleShort])) {
            foreach ($this->attributeIndex[$needleShort] as $entry) {
                if ('all' === $scope || $entry['scope'] === $scope) {
                    $emit($entry);
                }
            }
        }

        // Fallback: substring scan over raw attribute strings, to catch fully-qualified
        // variants (`Doctrine\ORM\Mapping\Column`) when the user asked with a different form.
        foreach ($this->attributeIndex as $entries) {
            foreach ($entries as $entry) {
                if ('all' !== $scope && $entry['scope'] !== $scope) {
                    continue;
                }
                if (str_contains(strtolower($entry['raw']), $needleLower)) {
                    $emit($entry);
                }
            }
        }

        return $hits;
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
