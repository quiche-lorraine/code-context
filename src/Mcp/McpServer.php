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
    private const string PROTOCOL_VERSION = '2024-11-05';
    private const string SERVER_NAME = 'code-context';
    private const string SERVER_VERSION = '0.1.0';

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
        $routes = $this->index->getRoutes($filter);

        if ([] === $routes) {
            $suffix = null !== $filter ? " matching \"{$filter}\"" : '';

            return "No routes found{$suffix}.";
        }

        $lines = ['Routes (' . \count($routes) . "):\n"];
        foreach ($routes as $route) {
            $scope = $route['scope'] ?? 'method';
            $class = $route['class'] ?? '';
            $method = isset($route['method']) ? '::' . $route['method'] : '';
            $attr = $route['attribute'] ?? '';
            $lines[] = "- [{$scope}] `{$class}{$method}` — `#[{$attr}]`";
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
                'description' => 'List Symfony routes extracted from #[Route] attributes.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'filter' => ['type' => 'string', 'description' => 'Optional substring filter on controller class or route attribute'],
                    ],
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
        }
    }
}
