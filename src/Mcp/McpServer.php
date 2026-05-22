<?php

declare(strict_types=1);

namespace CodeContext\Mcp;

use CodeContext\Index\ClassIndex;

/**
 * Minimal MCP (Model Context Protocol) server over stdio.
 *
 * Implements the JSON-RPC 2.0 framing used by MCP 2024-11-05.
 * Each message is a single JSON line terminated by \n.
 * Reads from stdin, writes responses to stdout, logs errors to stderr.
 */
final class McpServer
{
    private const PROTOCOL_VERSION = '2024-11-05';
    private const SERVER_NAME = 'code-context';
    private const SERVER_VERSION = '0.1.0';

    public function __construct(private readonly ClassIndex $index)
    {
    }

    public function run(): void
    {
        while (false !== ($line = fgets(STDIN))) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            try {
                /** @var mixed $request */
                $request = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->sendError(null, -32700, 'Parse error: ' . $e->getMessage());
                continue;
            }

            if (!\is_array($request)) {
                $this->sendError(null, -32600, 'Invalid Request');
                continue;
            }

            // Notifications (no id) — acknowledge but don't respond
            if (!isset($request['id'])) {
                continue;
            }

            $id = $request['id'];
            $method = (string) ($request['method'] ?? '');
            /** @var array<string, mixed> $params */
            $params = \is_array($request['params'] ?? null) ? $request['params'] : [];

            try {
                $result = $this->dispatch($method, $params);
                $this->sendResult($id, $result);
            } catch (\InvalidArgumentException $e) {
                $this->sendError($id, -32602, $e->getMessage());
            } catch (\Throwable $e) {
                $this->sendError($id, -32603, 'Internal error: ' . $e->getMessage());
                fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
            }
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function dispatch(string $method, array $params): array
    {
        return match ($method) {
            'initialize' => $this->handleInitialize(),
            'tools/list' => $this->handleToolsList(),
            'tools/call' => $this->handleToolsCall($params),
            default => throw new \InvalidArgumentException("Method not found: {$method}"),
        };
    }

    /** @return array<string, mixed> */
    private function handleInitialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => ['tools' => new \stdClass()],
            'serverInfo' => ['name' => self::SERVER_NAME, 'version' => self::SERVER_VERSION],
        ];
    }

    /** @return array<string, mixed> */
    private function handleToolsList(): array
    {
        return ['tools' => $this->toolDefinitions()];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function handleToolsCall(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        /** @var array<string, mixed> $args */
        $args = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $text = match ($name) {
            'search_symbol' => $this->toolSearchSymbol($args),
            'get_class' => $this->toolGetClass($args),
            'find_implementations' => $this->toolFindImplementations($args),
            'find_subclasses' => $this->toolFindSubclasses($args),
            'find_usages' => $this->toolFindUsages($args),
            'get_routes' => $this->toolGetRoutes($args),
            'get_namespace' => $this->toolGetNamespace($args),
            'find_by_attribute' => $this->toolFindByAttribute($args),
            'search_method' => $this->toolSearchMethod($args),
            'get_route' => $this->toolGetRoute($args),
            'get_commands' => $this->toolGetCommands(),
            'get_command' => $this->toolGetCommand($args),
            'list_entities' => $this->toolListEntities(),
            'get_entity' => $this->toolGetEntity($args),
            'get_entity_enums' => $this->toolGetEntityEnums(),
            'find_service' => $this->toolFindService($args),
            default => throw new \InvalidArgumentException("Unknown tool: {$name}"),
        };

        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => false,
        ];
    }

    // -------------------------------------------------------------------------
    // Tool implementations
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $args */
    private function toolSearchSymbol(array $args): string
    {
        $query = (string) ($args['query'] ?? '');
        $kind = isset($args['kind']) ? (string) $args['kind'] : null;

        if ('' === $query) {
            throw new \InvalidArgumentException('query is required');
        }

        $results = $this->index->searchSymbol($query, $kind);

        if ([] === $results) {
            return "No symbols found matching \"{$query}\".";
        }

        $lines = ["Found " . \count($results) . " symbol(s) matching \"{$query}\":\n"];
        foreach ($results as $r) {
            $summary = '' !== (string) ($r['summary'] ?? '') ? ' — ' . $r['summary'] : '';
            $implements = [] !== (array) ($r['implements'] ?? []) ? ' implements ' . implode(', ', (array) $r['implements']) : '';
            $extends = null !== ($r['extends'] ?? null) ? ' extends ' . $r['extends'] : '';
            $lines[] = sprintf(
                '- [%s] %s%s%s%s (%d methods) in %s',
                $r['kind'] ?? 'class',
                $r['fqcn'] ?? '',
                $extends,
                $implements,
                $summary,
                (int) ($r['method_count'] ?? 0),
                $r['file'] ?? '',
            );
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $args */
    private function toolGetClass(array $args): string
    {
        $fqcn = (string) ($args['fqcn'] ?? '');
        if ('' === $fqcn) {
            throw new \InvalidArgumentException('fqcn is required');
        }

        // Disambiguate short names that match multiple FQCNs
        if (!str_contains($fqcn, '\\')) {
            $candidates = $this->index->getShortNameCandidates($fqcn);
            if (\count($candidates) > 1) {
                $count = \count($candidates);
                $list = implode("\n", array_map(static fn (string $c): string => "- `{$c}`", $candidates));

                return "Ambiguous short name \"{$fqcn}\" — {$count} matches found. Please provide a fully qualified class name:\n\n{$list}";
            }
        }

        $class = $this->index->getClass($fqcn);
        if (null === $class) {
            return "Class \"{$fqcn}\" not found in the index.";
        }

        $lines = [];
        $kind = $class['kind'] ?? 'class';
        $modifiers = [];
        if ($class['abstract'] ?? false) {
            $modifiers[] = 'abstract';
        }
        if ($class['final'] ?? false) {
            $modifiers[] = 'final';
        }
        if ($class['readonly'] ?? false) {
            $modifiers[] = 'readonly';
        }
        $modStr = [] !== $modifiers ? implode(' ', $modifiers) . ' ' : '';
        $lines[] = "## {$modStr}{$kind} {$class['fqcn']}";
        $lines[] = '';
        $lines[] = '**File:** `' . ($class['file'] ?? '') . '`';

        if (null !== ($class['extends'] ?? null)) {
            $lines[] = '**Extends:** `' . $class['extends'] . '`';
        }
        if ([] !== (array) ($class['implements'] ?? [])) {
            $lines[] = '**Implements:** ' . implode(', ', array_map(
                static fn (string $i): string => '`' . $i . '`',
                (array) $class['implements'],
            ));
        }
        if ([] !== (array) ($class['traits'] ?? [])) {
            $lines[] = '**Uses:** ' . implode(', ', array_map(
                static fn (string $t): string => '`' . $t . '`',
                (array) $class['traits'],
            ));
        }
        if ([] !== (array) ($class['attributes'] ?? [])) {
            $lines[] = '**Attributes:** ' . implode(', ', array_map(
                static fn (string $a): string => '`#[' . $a . ']`',
                (array) $class['attributes'],
            ));
        }
        if (null !== ($class['summary'] ?? null) && '' !== $class['summary']) {
            $lines[] = '';
            $lines[] = '> ' . $class['summary'];
        }

        // Properties
        $props = array_values(array_filter((array) ($class['properties'] ?? []), 'is_array'));
        if ([] !== $props) {
            $lines[] = '';
            $lines[] = '### Properties';
            foreach ($props as $prop) {
                $visibility = $prop['visibility'] ?? 'public';
                $type = null !== ($prop['type'] ?? null) ? (string) $prop['type'] . ' ' : '';
                $readonly = ($prop['readonly'] ?? false) ? 'readonly ' : '';
                $lines[] = "- {$visibility} {$readonly}{$type}\${$prop['name']}";
                $propAttrs = array_values(array_filter((array) ($prop['attributes'] ?? []), 'is_string'));
                if ([] !== $propAttrs) {
                    $lines[] = '  - Attributes: ' . implode(', ', array_map(
                        static fn (string $a): string => '`#[' . $a . ']`',
                        $propAttrs,
                    ));
                }
            }
        }

        // Methods
        $methods = array_values(array_filter((array) ($class['methods'] ?? []), 'is_array'));
        if ([] !== $methods) {
            $lines[] = '';
            $lines[] = '### Methods';
            foreach ($methods as $method) {
                $params = [];
                foreach ((array) ($method['parameters'] ?? []) as $p) {
                    if (!\is_array($p)) {
                        continue;
                    }
                    $pType = null !== ($p['type'] ?? null) ? (string) $p['type'] . ' ' : '';
                    $params[] = $pType . '$' . ($p['name'] ?? '');
                }
                $returnType = null !== ($method['return_type'] ?? null) ? ': ' . $method['return_type'] : '';
                $static = ($method['static'] ?? false) ? 'static ' : '';
                $visibility = $method['visibility'] ?? 'public';
                $sig = "`{$method['name']}(" . implode(', ', $params) . "){$returnType}`";
                $summary = null !== ($method['summary'] ?? null) && '' !== $method['summary']
                    ? ' — ' . $method['summary']
                    : '';
                $lines[] = "- {$visibility} {$static}{$sig}{$summary}";
                $methodAttrs = array_values(array_filter((array) ($method['attributes'] ?? []), 'is_string'));
                if ([] !== $methodAttrs) {
                    $lines[] = '  - Attributes: ' . implode(', ', array_map(
                        static fn (string $a): string => '`#[' . $a . ']`',
                        $methodAttrs,
                    ));
                }
            }
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $args */
    private function toolFindImplementations(array $args): string
    {
        $fqcn = (string) ($args['fqcn'] ?? '');
        if ('' === $fqcn) {
            throw new \InvalidArgumentException('fqcn is required');
        }

        $impls = $this->index->findImplementations($fqcn);

        if ([] === $impls) {
            return "No classes found implementing \"{$fqcn}\".";
        }

        $lines = ["Classes implementing `{$fqcn}` (" . \count($impls) . "):\n"];
        foreach ($impls as $impl) {
            $class = $this->index->getClass($impl);
            $summary = null !== $class && null !== ($class['summary'] ?? null) ? ' — ' . $class['summary'] : '';
            $file = null !== $class ? ' (' . ($class['file'] ?? '') . ')' : '';
            $lines[] = "- `{$impl}`{$summary}{$file}";
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $args */
    private function toolFindSubclasses(array $args): string
    {
        $fqcn = (string) ($args['fqcn'] ?? '');
        if ('' === $fqcn) {
            throw new \InvalidArgumentException('fqcn is required');
        }

        $subs = $this->index->findSubclasses($fqcn);

        if ([] === $subs) {
            return "No classes found extending \"{$fqcn}\".";
        }

        $lines = ["Classes extending `{$fqcn}` (" . \count($subs) . "):\n"];
        foreach ($subs as $sub) {
            $class = $this->index->getClass($sub);
            $summary = null !== $class && null !== ($class['summary'] ?? null) ? ' — ' . $class['summary'] : '';
            $file = null !== $class ? ' (' . ($class['file'] ?? '') . ')' : '';
            $lines[] = "- `{$sub}`{$summary}{$file}";
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $args */
    private function toolFindUsages(array $args): string
    {
        $fqcn = (string) ($args['fqcn'] ?? '');
        if ('' === $fqcn) {
            throw new \InvalidArgumentException('fqcn is required');
        }

        $users = $this->index->findUsages($fqcn);

        if ([] === $users) {
            return "No classes found referencing \"{$fqcn}\" in constructor or properties.";
        }

        $lines = ["Classes referencing `{$fqcn}` (" . \count($users) . "):\n"];
        foreach ($users as $user) {
            $class = $this->index->getClass($user);
            $summary = null !== $class && null !== ($class['summary'] ?? null) ? ' — ' . $class['summary'] : '';
            $file = null !== $class ? ' (' . ($class['file'] ?? '') . ')' : '';
            $lines[] = "- `{$user}`{$summary}{$file}";
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $args */
    private function toolGetRoutes(array $args): string
    {
        $filter = isset($args['filter']) ? (string) $args['filter'] : null;
        $limit = isset($args['limit']) ? max(1, (int) $args['limit']) : null;
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;

        $routes = $this->index->getRoutes($filter);
        $total = \count($routes);

        if ([] === $routes) {
            $suffix = null !== $filter ? " matching \"{$filter}\"" : '';

            return "No routes found{$suffix}.";
        }

        if ($offset > 0 || null !== $limit) {
            $routes = array_slice($routes, $offset, $limit);
        }

        $pageInfo = null !== $limit
            ? " (showing {$offset}–" . ($offset + \count($routes) - 1) . " of {$total})"
            : '';
        $lines = ["Routes ({$total}){$pageInfo}:\n"];
        foreach ($routes as $route) {
            $scope = $route['scope'] ?? 'method';
            $class = $route['class'] ?? '';
            $method = isset($route['method']) ? '::' . $route['method'] : '';
            $name = isset($route['name']) ? " name={$route['name']}" : '';
            $path = isset($route['path']) ? " path={$route['path']}" : '';
            $methods = [] !== (array) ($route['methods'] ?? []) ? ' [' . implode(',', (array) $route['methods']) . ']' : '';
            $lines[] = "- [{$scope}]{$methods} `{$class}{$method}`{$name}{$path}";
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $args */
    private function toolGetNamespace(array $args): string
    {
        $namespace = (string) ($args['namespace'] ?? '');
        if ('' === $namespace) {
            throw new \InvalidArgumentException('namespace is required');
        }

        $classes = $this->index->getNamespace($namespace);

        if ([] === $classes) {
            return "No classes found in namespace \"{$namespace}\".";
        }

        $lines = ["Classes in `{$namespace}` (" . \count($classes) . "):\n"];
        foreach ($classes as $class) {
            $kind = $class['kind'] ?? 'class';
            $summary = null !== ($class['summary'] ?? null) ? ' — ' . $class['summary'] : '';
            $lines[] = "- [{$kind}] `{$class['fqcn']}`{$summary}";
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $args */
    private function toolFindByAttribute(array $args): string
    {
        $attribute = (string) ($args['attribute'] ?? '');
        if ('' === $attribute) {
            throw new \InvalidArgumentException('attribute is required');
        }
        $scope = (string) ($args['scope'] ?? 'all');
        if (!\in_array($scope, ['class', 'method', 'property', 'all'], true)) {
            throw new \InvalidArgumentException('scope must be one of: class, method, property, all');
        }

        $hits = $this->index->findByAttribute($attribute, $scope);
        if ([] === $hits) {
            $scopeLabel = 'all' === $scope ? '' : " on {$scope}s";
            return "No usages of attribute \"{$attribute}\" found{$scopeLabel}.";
        }

        $sections = [
            'class' => ['title' => '### Classes', 'format' => static fn (array $h): string => "- `{$h['fqcn']}` — `#[{$h['raw']}]`"],
            'method' => ['title' => '### Methods', 'format' => static fn (array $h): string => "- `{$h['fqcn']}::{$h['member']}` — `#[{$h['raw']}]`"],
            'property' => ['title' => '### Properties', 'format' => static fn (array $h): string => "- `{$h['fqcn']}::\${$h['member']}` — `#[{$h['raw']}]`"],
        ];

        $lines = ['Attribute usages for `' . $attribute . '` (' . \count($hits) . "):\n"];

        foreach ($sections as $scopeKey => $section) {
            $filtered = array_values(array_filter(
                $hits,
                static fn (array $h): bool => $h['scope'] === $scopeKey,
            ));
            if ([] === $filtered) {
                continue;
            }
            $lines[] = $section['title'];
            foreach ($filtered as $hit) {
                $lines[] = $section['format']($hit);
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }

    private function toolGetCommands(): string
    {
        $commands = $this->index->getCommands();
        if ([] === $commands) {
            return 'No Symfony commands found in the index.';
        }

        $lines = ['## Symfony Commands (' . \count($commands) . ")\n"];
        foreach ($commands as $command) {
            $name = (string) ($command['name'] ?? '<unknown>');
            $description = (string) ($command['description'] ?? '');
            $class = (string) ($command['class'] ?? '');
            $descPart = '' !== $description ? " — {$description}" : '';
            $lines[] = "- `{$name}`{$descPart} (in `{$class}`)";
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $args */
    private function toolGetCommand(array $args): string
    {
        $name = (string) ($args['name'] ?? '');
        if ('' === $name) {
            throw new \InvalidArgumentException('name is required');
        }

        $command = $this->index->getCommand($name);
        if (null === $command) {
            return "Command \"{$name}\" not found in the index.";
        }

        $lines = [];
        $lines[] = "## {$name}";
        $lines[] = '';
        $lines[] = '**Class:** `' . (string) ($command['class'] ?? '') . '`';
        $lines[] = '**File:** `' . (string) ($command['file'] ?? '') . '`';
        $description = (string) ($command['description'] ?? '');
        if ('' !== $description) {
            $lines[] = '**Description:** ' . $description;
        }

        return implode("\n", $lines);
    }

    private function toolListEntities(): string
    {
        $entities = $this->index->getEntities();
        if ([] === $entities) {
            return 'No Doctrine entities found in the index.';
        }

        $lines = ['## Doctrine Entities (' . \count($entities) . ")\n"];
        foreach ($entities as $entity) {
            $class = (string) ($entity['class'] ?? '');
            $file = (string) ($entity['file'] ?? '');
            $lines[] = "- `{$class}` (`{$file}`)";
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $args */
    private function toolGetEntity(array $args): string
    {
        $class = (string) ($args['class'] ?? '');
        if ('' === $class) {
            throw new \InvalidArgumentException('class is required');
        }

        $entity = $this->index->getEntity($class);
        if (null === $entity) {
            return "Entity \"{$class}\" not found in the index.";
        }

        $lines = [];
        $lines[] = '## Entity ' . (string) ($entity['class'] ?? $class);
        $lines[] = '';
        $lines[] = '**File:** `' . (string) ($entity['file'] ?? '') . '`';

        $props = array_values(array_filter((array) ($entity['properties'] ?? []), 'is_array'));
        if ([] !== $props) {
            $lines[] = '';
            $lines[] = '### Properties';
            foreach ($props as $prop) {
                $visibility = (string) ($prop['visibility'] ?? 'public');
                $type = null !== ($prop['type'] ?? null) ? (string) $prop['type'] . ' ' : '';
                $name = (string) ($prop['name'] ?? '');
                $lines[] = "- {$visibility} {$type}\${$name}";
                $propAttrs = array_values(array_filter((array) ($prop['attributes'] ?? []), 'is_string'));
                if ([] !== $propAttrs) {
                    $lines[] = '  - Attributes: ' . implode(', ', array_map(
                        static fn (string $a): string => '`#[' . $a . ']`',
                        $propAttrs,
                    ));
                }
            }
        }

        return implode("\n", $lines);
    }

    private function toolGetEntityEnums(): string
    {
        $enums = $this->index->getEntityEnums();
        if ([] === $enums) {
            return 'No enums found in the index.';
        }

        $lines = ['## Entity Enums (' . \count($enums) . ")\n"];
        foreach ($enums as $enum) {
            $class = (string) ($enum['class'] ?? '');
            $cases = array_values(array_filter((array) ($enum['cases'] ?? []), 'is_string'));
            $casesStr = [] !== $cases ? ': ' . implode(', ', $cases) : '';
            $lines[] = "- `{$class}`{$casesStr}";
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $args */
    private function toolFindService(array $args): string
    {
        $query = (string) ($args['query'] ?? '');
        $result = $this->index->findService($query);

        $configured = $result['configured'];
        $autowired = $result['autowired'];

        if ([] === $configured && [] === $autowired) {
            return "No services matching \"{$query}\".";
        }

        $lines = [];
        if ([] !== $configured) {
            $lines[] = '### Configured services (' . \count($configured) . ')';
            foreach ($configured as $svc) {
                $id = (string) ($svc['id'] ?? '');
                $definition = \is_array($svc['definition'] ?? null) ? $svc['definition'] : [];
                $class = isset($definition['class']) ? (string) $definition['class'] : null;
                $classPart = null !== $class && '' !== $class ? " → `{$class}`" : '';
                $lines[] = "- `{$id}`{$classPart}";
            }
            $lines[] = '';
        }
        if ([] !== $autowired) {
            $lines[] = '### Autowired services (' . \count($autowired) . ')';
            foreach ($autowired as $svc) {
                $fqcn = (string) ($svc['fqcn'] ?? '');
                $lines[] = "- `{$fqcn}`";
            }
        }

        return rtrim(implode("\n", $lines));
    }

    /** @param array<string, mixed> $args */
    private function toolSearchMethod(array $args): string
    {
        $query = (string) ($args['query'] ?? '');
        if ('' === $query) {
            throw new \InvalidArgumentException('query is required');
        }

        $results = $this->index->searchMethod($query);
        if ([] === $results) {
            return "No methods found matching \"{$query}\".";
        }

        $lines = ["Found " . \count($results) . " method(s) matching \"{$query}\":\n"];
        foreach ($results as $entry) {
            $fqcn = (string) $entry['class'];
            $method = $entry['method'];
            $name = (string) ($method['name'] ?? '');
            $visibility = (string) ($method['visibility'] ?? 'public');
            $static = ($method['static'] ?? false) ? 'static ' : '';
            $returnType = null !== ($method['return_type'] ?? null) ? ': ' . $method['return_type'] : '';
            $params = [];
            foreach ((array) ($method['parameters'] ?? []) as $p) {
                if (!\is_array($p)) {
                    continue;
                }
                $pType = null !== ($p['type'] ?? null) ? (string) $p['type'] . ' ' : '';
                $params[] = $pType . '$' . ($p['name'] ?? '');
            }
            $sig = "`{$name}(" . implode(', ', $params) . "){$returnType}`";
            $summary = null !== ($method['summary'] ?? null) && '' !== $method['summary']
                ? ' — ' . $method['summary']
                : '';
            $class = $this->index->getClass($fqcn);
            $file = null !== $class ? ' (' . ($class['file'] ?? '') . ')' : '';
            $lines[] = "- {$visibility} {$static}{$sig} in `{$fqcn}`{$summary}{$file}";
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $args */
    private function toolGetRoute(array $args): string
    {
        $name = (string) ($args['name'] ?? '');
        if ('' === $name) {
            throw new \InvalidArgumentException('name is required');
        }

        $route = $this->index->getRoute($name);
        if (null === $route) {
            return "Route \"{$name}\" not found in the index.";
        }

        $lines = [];
        $lines[] = "## Route `{$name}`";
        $lines[] = '';
        $path = $route['path'] ?? null;
        if (null !== $path && '' !== $path) {
            $lines[] = "**Path:** `{$path}`";
        }
        $routeMethods = (array) ($route['methods'] ?? []);
        if ([] !== $routeMethods) {
            $lines[] = '**Methods:** ' . implode(', ', $routeMethods);
        }
        $lines[] = '**Controller:** `' . (string) ($route['class'] ?? '') . '::' . (string) ($route['method'] ?? '') . '`';

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Tool definitions (JSON Schema)
    // -------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function toolDefinitions(): array
    {
        return [
            [
                'name' => 'search_symbol',
                'description' => 'Search for classes, interfaces, traits, enums, or methods by name substring. Returns compact summaries.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Name substring to search for'],
                        'kind' => ['type' => 'string', 'enum' => ['class', 'interface', 'trait', 'enum'], 'description' => 'Optional kind filter'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'get_class',
                'description' => 'Get full details of a class/interface/trait/enum including all methods and properties.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'fqcn' => ['type' => 'string', 'description' => 'Fully qualified class name (or short name for unambiguous matches)'],
                    ],
                    'required' => ['fqcn'],
                ],
            ],
            [
                'name' => 'find_implementations',
                'description' => 'Find all classes that implement a given interface.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'fqcn' => ['type' => 'string', 'description' => 'Interface FQCN'],
                    ],
                    'required' => ['fqcn'],
                ],
            ],
            [
                'name' => 'find_subclasses',
                'description' => 'Find all classes that extend a given class.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'fqcn' => ['type' => 'string', 'description' => 'Parent class FQCN'],
                    ],
                    'required' => ['fqcn'],
                ],
            ],
            [
                'name' => 'find_usages',
                'description' => 'Find all classes that reference a given type in their constructor parameters or properties.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'fqcn' => ['type' => 'string', 'description' => 'Type FQCN to find usages of'],
                    ],
                    'required' => ['fqcn'],
                ],
            ],
            [
                'name' => 'get_routes',
                'description' => 'List Symfony routes extracted from #[Route] attributes. Supports filtering and pagination.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'filter' => ['type' => 'string', 'description' => 'Optional substring filter on controller class, method or route attribute'],
                        'limit' => ['type' => 'integer', 'description' => 'Maximum number of routes to return'],
                        'offset' => ['type' => 'integer', 'description' => 'Number of routes to skip (default: 0)'],
                    ],
                ],
            ],
            [
                'name' => 'get_route',
                'description' => 'Get a Symfony route by its declared name.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Route name as declared in #[Route(name: "...")]'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'get_namespace',
                'description' => 'List all classes in a namespace subtree.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'namespace' => ['type' => 'string', 'description' => 'Namespace prefix (e.g. "App\\\\Service")'],
                    ],
                    'required' => ['namespace'],
                ],
            ],
            [
                'name' => 'search_method',
                'description' => 'Search for methods by name substring across all classes. Returns matching methods with their signature, class and file — unlike search_symbol which returns the containing class.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Method name substring to search for'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'find_by_attribute',
                'description' => 'Find classes, methods or properties carrying a given PHP attribute (e.g. Route, IsGranted, ORM\\Column). Matching is case-insensitive and accepts short or fully-qualified attribute names.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'attribute' => ['type' => 'string', 'description' => 'Attribute name (short or FQN)'],
                        'scope' => ['type' => 'string', 'enum' => ['class', 'method', 'property', 'all'], 'description' => 'Optional scope filter (default: all)'],
                    ],
                    'required' => ['attribute'],
                ],
            ],
            [
                'name' => 'get_commands',
                'description' => 'List all Symfony console commands declared via #[AsCommand].',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'get_command',
                'description' => 'Get details of a Symfony console command by its declared name.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Command name as declared in #[AsCommand(name: ...)]'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'list_entities',
                'description' => 'List all Doctrine entities (classes annotated with ORM\\Entity).',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'get_entity',
                'description' => 'Get a Doctrine entity with its properties, types, and ORM attributes.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'class' => ['type' => 'string', 'description' => 'Entity FQCN (or short name for unambiguous matches)'],
                    ],
                    'required' => ['class'],
                ],
            ],
            [
                'name' => 'get_entity_enums',
                'description' => 'List all PHP enums declared in the project (often used as Doctrine enum types).',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'find_service',
                'description' => 'Search the Symfony service container: matches configured services by id and autowired services by FQCN/namespace.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Substring to match against service ids, FQCNs or namespaces'],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // JSON-RPC framing helpers
    // -------------------------------------------------------------------------

    private function sendResult(mixed $id, mixed $result): void
    {
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function sendError(mixed $id, int $code, string $message): void
    {
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }

    /** @param array<string, mixed> $payload */
    private function send(array $payload): void
    {
        $json = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (false !== $json) {
            fwrite(STDOUT, $json . "\n");
            // Force flush: PHP buffers stdout fully when connected to a pipe,
            // which causes large MCP responses to hang until the buffer fills.
            fflush(STDOUT);
        }
    }
}
