<?php

declare(strict_types=1);

namespace CodeContext\Mcp;

use CodeContext\Index\ClassIndex;
use CodeContext\Model\AttributeInfo;

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

    public function __construct(private readonly ClassIndex $index)
    {
    }

    private static function serverVersion(): string
    {
        if (class_exists(\Composer\InstalledVersions::class)) {
            $v = \Composer\InstalledVersions::getPrettyVersion('quiche-lorraine/code-context');
            if (null !== $v) {
                return $v;
            }
        }

        return 'dev';
    }

    /**
     * Renders structured attribute arrays back into readable strings (e.g. `Route('/blog')`).
     *
     * @param mixed $attributes The raw `attributes` value from the context JSON
     *
     * @return list<string>
     */
    private static function renderAttributes(mixed $attributes): array
    {
        $rendered = [];
        foreach ((array) $attributes as $attribute) {
            if (\is_array($attribute)) {
                $rendered[] = AttributeInfo::fromArray($attribute)->render();
            }
        }

        return $rendered;
    }

    /**
     * Slices a list according to the `limit` (default 50) / `offset` (default 0)
     * tool arguments and returns the page together with a count suffix to embed in
     * a header. The suffix is a bare `(N)` when the whole list fits on the first
     * page, or `(N) showing X–Y` once pagination kicks in.
     *
     * @template T
     *
     * @param array<string, mixed> $args
     * @param list<T>               $items
     *
     * @return array{0: list<T>, 1: string}
     */
    private static function paginate(array $args, array $items): array
    {
        $limit = isset($args['limit']) ? max(1, (int) $args['limit']) : 50;
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
        $total = \count($items);
        $page = array_slice($items, $offset, $limit);

        $suffix = (0 === $offset && \count($page) === $total)
            ? "({$total})"
            : "({$total}) showing {$offset}–" . ($offset + \count($page) - 1);

        return [$page, $suffix];
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
            'initialize' => $this->handleInitialize($params),
            'tools/list' => $this->handleToolsList(),
            'tools/call' => $this->handleToolsCall($params),
            default => throw new \InvalidArgumentException("Method not found: {$method}"),
        };
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function handleInitialize(array $params): array
    {
        // Echo back the client's requested protocol version when present, so the
        // server stays forward-compatible as the MCP spec evolves; fall back to the
        // revision we are written against otherwise.
        $requested = $params['protocolVersion'] ?? null;
        $protocolVersion = \is_string($requested) && '' !== $requested
            ? $requested
            : self::PROTOCOL_VERSION;

        return [
            'protocolVersion' => $protocolVersion,
            'capabilities' => ['tools' => new \stdClass()],
            'serverInfo' => ['name' => self::SERVER_NAME, 'version' => self::serverVersion()],
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
            'explain_class' => $this->toolExplainClass($args),
            'find_implementations' => $this->toolFindImplementations($args),
            'find_subclasses' => $this->toolFindSubclasses($args),
            'find_usages' => $this->toolFindUsages($args),
            'get_routes' => $this->toolGetRoutes($args),
            'get_namespace' => $this->toolGetNamespace($args),
            'find_by_attribute' => $this->toolFindByAttribute($args),
            'search_method' => $this->toolSearchMethod($args),
            'get_route' => $this->toolGetRoute($args),
            'get_commands' => $this->toolGetCommands($args),
            'get_command' => $this->toolGetCommand($args),
            'list_entities' => $this->toolListEntities($args),
            'get_entity' => $this->toolGetEntity($args),
            'get_entity_enums' => $this->toolGetEntityEnums($args),
            'find_service' => $this->toolFindService($args),
            'find_voters' => $this->toolFindVoters($args),
            'find_message_handlers' => $this->toolFindMessageHandlers($args),
            'find_subscribers' => $this->toolFindSubscribers($args),
            'get_workflow' => $this->toolGetWorkflow($args),
            'list_workflows' => $this->toolListWorkflows($args),
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
        $limit = isset($args['limit']) ? max(1, (int) $args['limit']) : 50;
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;

        if ('' === $query) {
            throw new \InvalidArgumentException('query is required');
        }

        $results = $this->index->searchSymbol($query, $kind);
        $total = \count($results);

        if ([] === $results) {
            return "No symbols found matching \"{$query}\".";
        }

        $page = array_slice($results, $offset, $limit);
        $pageInfo = "Symbols ({$total}) showing {$offset}–" . ($offset + \count($page) - 1) . ":\n";
        $lines = [$pageInfo];
        foreach ($page as $r) {
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
        $classAttrs = self::renderAttributes($class['attributes'] ?? []);
        if ([] !== $classAttrs) {
            $lines[] = '**Attributes:** ' . implode(', ', array_map(
                static fn (string $a): string => '`#[' . $a . ']`',
                $classAttrs,
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
                $propAttrs = self::renderAttributes($prop['attributes'] ?? []);
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
                $methodAttrs = self::renderAttributes($method['attributes'] ?? []);
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
    private function toolExplainClass(array $args): string
    {
        $fqcn = (string) ($args['fqcn'] ?? '');
        if ('' === $fqcn) {
            throw new \InvalidArgumentException('fqcn is required');
        }

        // Disambiguate short names that match multiple FQCNs (mirrors get_class).
        if (!str_contains($fqcn, '\\')) {
            $candidates = $this->index->getShortNameCandidates($fqcn);
            if (\count($candidates) > 1) {
                $count = \count($candidates);
                $list = implode("\n", array_map(static fn (string $c): string => "- `{$c}`", $candidates));

                return "Ambiguous short name \"{$fqcn}\" — {$count} matches found. Please provide a fully qualified class name:\n\n{$list}";
            }
        }

        $info = $this->index->explainClass($fqcn);
        if (null === $info) {
            return "Class \"{$fqcn}\" not found in the index.";
        }

        return $this->renderExplainClass($info);
    }

    /**
     * @param array<string, mixed> $info
     */
    private function renderExplainClass(array $info): string
    {
        $shortName = (static function (string $fqcn): string {
            $pos = strrpos($fqcn, '\\');

            return false === $pos ? $fqcn : substr($fqcn, $pos + 1);
        })((string) ($info['fqcn'] ?? ''));

        $lines = [];
        $lines[] = '## ' . (string) ($info['role'] ?? 'class') . ' `' . (string) ($info['fqcn'] ?? '') . '`';
        $lines[] = '**File:** `' . (string) ($info['file'] ?? '') . '`';
        if (null !== ($info['extends'] ?? null)) {
            $lines[] = '**Extends:** `' . (string) $info['extends'] . '`';
        }
        if ([] !== (array) ($info['implements'] ?? [])) {
            $lines[] = '**Implements:** ' . implode(', ', array_map(
                static fn (string $i): string => '`' . $i . '`',
                array_map('strval', (array) $info['implements']),
            ));
        }

        // Dependencies (outgoing)
        $deps = array_values(array_filter((array) ($info['depends_on'] ?? []), 'is_array'));
        if ([] !== $deps) {
            $lines[] = '';
            $lines[] = '### Depends on (' . \count($deps) . ')';
            foreach ($deps as $dep) {
                $type = null !== ($dep['type'] ?? null) ? '`' . (string) $dep['type'] . '`' : 'mixed';
                $lines[] = '- $' . (string) ($dep['name'] ?? '') . ': ' . $type;
            }
        }

        // Usages (incoming)
        $usedBy = \is_array($info['used_by'] ?? null) ? $info['used_by'] : [];
        $usedCount = (int) ($usedBy['count'] ?? 0);
        if ($usedCount > 0) {
            $byLayer = \is_array($usedBy['by_layer'] ?? null) ? $usedBy['by_layer'] : [];
            $layerParts = [];
            foreach ($byLayer as $layer => $n) {
                $layerParts[] = (string) $layer . ':' . (int) $n;
            }
            $layerStr = [] !== $layerParts ? ' — ' . implode(', ', $layerParts) : '';
            $lines[] = '';
            $lines[] = "### Used by ({$usedCount}){$layerStr}";
            $top = array_map('strval', (array) ($usedBy['top'] ?? []));
            foreach ($top as $user) {
                $lines[] = "- `{$user}`";
            }
            if ($usedCount > \count($top)) {
                $more = $usedCount - \count($top);
                $lines[] = "- … +{$more} more — call find_usages('{$shortName}') for the rest";
            }
        }

        // Public API (signatures only)
        $api = array_map('strval', (array) ($info['public_api'] ?? []));
        if ([] !== $api) {
            $lines[] = '';
            $lines[] = '### Public API (' . \count($api) . ')';
            $shown = \array_slice($api, 0, 30);
            foreach ($shown as $signature) {
                $lines[] = "- `{$signature}`";
            }
            if (\count($api) > \count($shown)) {
                $more = \count($api) - \count($shown);
                $lines[] = "- … +{$more} more — call get_class('{$shortName}') for the full list";
            }
        }

        $lines = array_merge($lines, $this->renderExplainSymfony($info, $shortName));

        // Persistence
        $persistence = \is_array($info['persistence'] ?? null) ? $info['persistence'] : null;
        if (null !== $persistence) {
            $relations = array_values(array_filter((array) ($persistence['relations'] ?? []), 'is_array'));
            $lines[] = '';
            $lines[] = '### Persistence (' . (string) ($persistence['storage'] ?? 'orm') . ')';
            if ([] !== $relations) {
                foreach ($relations as $relation) {
                    $lines[] = self::renderRelation($relation);
                }
            } else {
                $lines[] = '- no associations';
            }
        }

        // Test coverage
        $tested = array_map('strval', (array) ($info['tested_by'] ?? []));
        if ([] !== $tested) {
            $lines[] = '';
            $lines[] = '### Tested by (' . \count($tested) . ')';
            foreach (\array_slice($tested, 0, 10) as $test) {
                $lines[] = "- `{$test}`";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Renders the role-aware Symfony bindings block of explain_class (only populated sections).
     *
     * @param array<string, mixed> $info
     *
     * @return list<string>
     */
    private function renderExplainSymfony(array $info, string $shortName): array
    {
        $symfony = \is_array($info['symfony'] ?? null) ? $info['symfony'] : [];
        $routes = array_values(array_filter((array) ($symfony['routes'] ?? []), 'is_array'));
        $handlesMessage = $symfony['handles_message'] ?? null;
        $subscribesTo = array_map('strval', (array) ($symfony['subscribes_to'] ?? []));
        $isVoter = true === ($symfony['voter'] ?? null);
        $workflows = array_map('strval', (array) ($symfony['workflows'] ?? []));

        if ([] === $routes && null === $handlesMessage && [] === $subscribesTo && !$isVoter && [] === $workflows) {
            return [];
        }

        $lines = ['', '### Symfony'];
        if ([] !== $routes) {
            $lines[] = '- Routes (' . \count($routes) . '):';
            foreach (\array_slice($routes, 0, 10) as $route) {
                $name = null !== ($route['name'] ?? null) ? (string) $route['name'] : '(unnamed)';
                $path = null !== ($route['path'] ?? null) ? (string) $route['path'] : '';
                $methods = [] !== (array) ($route['methods'] ?? []) ? ' [' . implode(',', array_map('strval', (array) $route['methods'])) . ']' : '';
                $lines[] = "  - `{$name}` {$path}{$methods}";
            }
            if (\count($routes) > 10) {
                $lines[] = "  - … call get_routes(filter: '{$shortName}') for the rest";
            }
        }
        if (null !== $handlesMessage && '' !== $handlesMessage) {
            $lines[] = '- Handles message: `' . (string) $handlesMessage . '`';
        }
        if ([] !== $subscribesTo) {
            $lines[] = '- Subscribes to: ' . implode(', ', array_map(
                static fn (string $e): string => '`' . $e . '`',
                $subscribesTo,
            ));
        }
        if ($isVoter) {
            $lines[] = '- Security voter: yes';
        }
        if ([] !== $workflows) {
            $lines[] = '- Workflows: ' . implode(', ', array_map(
                static fn (string $w): string => '`' . $w . '`',
                $workflows,
            ));
        }

        return $lines;
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
        $limit = isset($args['limit']) ? max(1, (int) $args['limit']) : 50;
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;

        $classes = $this->index->getNamespace($namespace);
        $total = \count($classes);

        if ([] === $classes) {
            return "No classes found in namespace \"{$namespace}\".";
        }

        $page = array_slice($classes, $offset, $limit);
        $lines = ["Classes in `{$namespace}` ({$total}) showing {$offset}–" . ($offset + \count($page) - 1) . ":\n"];
        foreach ($page as $class) {
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
        $limit = isset($args['limit']) ? max(1, (int) $args['limit']) : 50;
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;

        $hits = $this->index->findByAttribute($attribute, $scope);
        $total = \count($hits);

        if ([] === $hits) {
            $scopeLabel = 'all' === $scope ? '' : " on {$scope}s";
            return "No usages of attribute \"{$attribute}\" found{$scopeLabel}.";
        }

        $hits = array_slice($hits, $offset, $limit);

        $sections = [
            'class' => ['title' => '### Classes', 'format' => static fn (array $h): string => "- `{$h['fqcn']}` — `#[{$h['raw']}]`"],
            'method' => ['title' => '### Methods', 'format' => static fn (array $h): string => "- `{$h['fqcn']}::{$h['member']}` — `#[{$h['raw']}]`"],
            'property' => ['title' => '### Properties', 'format' => static fn (array $h): string => "- `{$h['fqcn']}::\${$h['member']}` — `#[{$h['raw']}]`"],
        ];

        $lines = ['Attribute usages for `' . $attribute . "` ({$total}) showing {$offset}–" . ($offset + \count($hits) - 1) . ":\n"];

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

    /** @param array<string, mixed> $args */
    private function toolGetCommands(array $args): string
    {
        $commands = $this->index->getCommands();
        if ([] === $commands) {
            return 'No Symfony commands found in the index.';
        }

        [$commands, $suffix] = self::paginate($args, $commands);
        $lines = ["## Symfony Commands {$suffix}\n"];
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

    /** @param array<string, mixed> $args */
    private function toolListEntities(array $args): string
    {
        $entities = $this->index->getEntities();
        if ([] === $entities) {
            return 'No Doctrine entities found in the index.';
        }

        [$entities, $suffix] = self::paginate($args, $entities);
        $lines = ["## Doctrine Entities {$suffix}\n"];
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
                $propAttrs = self::renderAttributes($prop['attributes'] ?? []);
                if ([] !== $propAttrs) {
                    $lines[] = '  - Attributes: ' . implode(', ', array_map(
                        static fn (string $a): string => '`#[' . $a . ']`',
                        $propAttrs,
                    ));
                }
            }
        }

        $relations = array_values(array_filter((array) ($entity['relations'] ?? []), 'is_array'));
        if ([] !== $relations) {
            $lines[] = '';
            $lines[] = '### Relations';
            foreach ($relations as $relation) {
                $lines[] = self::renderRelation($relation);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Renders a typed Doctrine relation, e.g. `- comments: OneToMany → App\Entity\Comment (mappedBy: post)`.
     *
     * @param array<string, mixed> $relation
     */
    private static function renderRelation(array $relation): string
    {
        $property = (string) ($relation['property'] ?? '');
        $kind = (string) ($relation['kind'] ?? '');
        $target = null !== ($relation['target'] ?? null) ? (string) $relation['target'] : '?';

        $extras = [];
        if (isset($relation['mappedBy'])) {
            $extras[] = 'mappedBy: ' . (string) $relation['mappedBy'];
        }
        if (isset($relation['inversedBy'])) {
            $extras[] = 'inversedBy: ' . (string) $relation['inversedBy'];
        }
        $extrasStr = [] !== $extras ? ' (' . implode(', ', $extras) . ')' : '';

        return "- {$property}: {$kind} → `{$target}`{$extrasStr}";
    }

    /** @param array<string, mixed> $args */
    private function toolGetEntityEnums(array $args): string
    {
        $enums = $this->index->getEntityEnums();
        if ([] === $enums) {
            return 'No enums found in the index.';
        }

        [$enums, $suffix] = self::paginate($args, $enums);
        $lines = ["## Entity Enums {$suffix}\n"];
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
        $limit = isset($args['limit']) ? max(1, (int) $args['limit']) : 50;
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;

        $result = $this->index->findService($query);

        $configured = $result['configured'];
        $autowired = $result['autowired'];

        if ([] === $configured && [] === $autowired) {
            return "No services matching \"{$query}\".";
        }

        $totalConfigured = \count($configured);
        $totalAutowired = \count($autowired);
        $lines = ['_Sections are paged independently (limit/offset applies to each)._', ''];

        if ([] !== $configured) {
            $page = array_slice($configured, $offset, $limit);
            $lines[] = "### Configured services ({$totalConfigured}) showing {$offset}–" . ($offset + \count($page) - 1);
            foreach ($page as $svc) {
                $id = (string) ($svc['id'] ?? '');
                $definition = \is_array($svc['definition'] ?? null) ? $svc['definition'] : [];
                $class = isset($definition['class']) ? (string) $definition['class'] : null;
                $classPart = null !== $class && '' !== $class ? " → `{$class}`" : '';
                $lines[] = "- `{$id}`{$classPart}";
            }
            $lines[] = '';
        }
        if ([] !== $autowired) {
            $page = array_slice($autowired, $offset, $limit);
            $lines[] = "### Autowired services ({$totalAutowired}) showing {$offset}–" . ($offset + \count($page) - 1);
            foreach ($page as $svc) {
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
        $limit = isset($args['limit']) ? max(1, (int) $args['limit']) : 50;
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;

        if ('' === $query) {
            throw new \InvalidArgumentException('query is required');
        }

        $results = $this->index->searchMethod($query);
        $total = \count($results);

        if ([] === $results) {
            return "No methods found matching \"{$query}\".";
        }

        $results = array_slice($results, $offset, $limit);
        $lines = ["Methods ({$total}) showing {$offset}–" . ($offset + \count($results) - 1) . ":\n"];
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
            if ($this->index->routeCount() > 0 && !$this->index->hasNamedRoutes()) {
                return "Route \"{$name}\" not found.\nHint: the loaded index does not include route names — regenerate it with code-context >= 1.1.0 to enable get_route(name).";
            }

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

    /** @param array<string, mixed> $args */
    private function toolFindVoters(array $args): string
    {
        $voters = $this->index->findVoters();
        if ([] === $voters) {
            return 'No Security Voters found (no classes extend Symfony\\Component\\Security\\Core\\Authorization\\Voter\\Voter).';
        }

        [$voters, $suffix] = self::paginate($args, $voters);
        $lines = ["## Security Voters {$suffix}\n"];
        foreach ($voters as $voter) {
            $class = $this->index->getClass($voter);
            $file = null !== $class ? ' (`' . ($class['file'] ?? '') . '`)' : '';
            $lines[] = "- `{$voter}`{$file}";
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $args */
    private function toolFindMessageHandlers(array $args): string
    {
        $handlers = $this->index->findMessageHandlers();
        if ([] === $handlers) {
            return 'No Messenger handlers found (no classes carry #[AsMessageHandler]).';
        }

        [$handlers, $suffix] = self::paginate($args, $handlers);
        $lines = ["## Messenger Handlers {$suffix}\n"];
        foreach ($handlers as $handler) {
            $message = null !== $handler['message'] && '' !== $handler['message']
                ? ' — message: `' . $handler['message'] . '`'
                : '';
            $lines[] = "- `{$handler['class']}::{$handler['method']}`{$message}";
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $args */
    private function toolFindSubscribers(array $args): string
    {
        $event = isset($args['event']) ? (string) $args['event'] : null;
        $subscribers = $this->index->getSubscribers($event);

        if ([] === $subscribers) {
            $suffix = null !== $event && '' !== $event ? " for event \"{$event}\"" : '';
            if (!$this->index->hasEventSubscribersSection()) {
                return "No event subscribers found{$suffix}.\nHint: the loaded index does not include an event_subscribers section — regenerate it with code-context >= 1.1.0 to enable find_subscribers.";
            }

            return "No event subscribers found{$suffix}.";
        }

        [$subscribers, $suffix] = self::paginate($args, $subscribers);
        $title = null !== $event && '' !== $event
            ? "## Event Subscribers matching \"{$event}\" {$suffix}"
            : "## Event Subscribers {$suffix}";
        $lines = [$title, ''];
        foreach ($subscribers as $subscriber) {
            $class = (string) ($subscriber['class'] ?? '');
            $file = (string) ($subscriber['file'] ?? '');
            $lines[] = "### `{$class}`";
            $lines[] = "**File:** `{$file}`";
            $events = (array) ($subscriber['events'] ?? []);
            if ([] !== $events) {
                $lines[] = '';
                foreach ($events as $ev) {
                    if (!\is_array($ev)) {
                        continue;
                    }
                    $priorityStr = null !== ($ev['priority'] ?? null) ? ' [priority: ' . $ev['priority'] . ']' : '';
                    $lines[] = "- `{$ev['event']}` → `{$ev['method']}()`{$priorityStr}";
                }
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }

    /** @param array<string, mixed> $args */
    private function toolListWorkflows(array $args): string
    {
        $workflows = $this->index->getWorkflows();
        if ([] === $workflows) {
            return 'No workflows or state machines found in config/packages/.';
        }

        [$workflows, $suffix] = self::paginate($args, $workflows);
        $lines = ["## Workflows {$suffix}\n"];
        foreach ($workflows as $workflow) {
            $name = (string) ($workflow['name'] ?? '');
            $type = (string) ($workflow['type'] ?? 'workflow');
            $places = \count((array) ($workflow['places'] ?? []));
            $transitions = \count((array) ($workflow['transitions'] ?? []));
            $lines[] = "- `{$name}` [{$type}] — {$places} places, {$transitions} transitions";
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $args */
    private function toolGetWorkflow(array $args): string
    {
        $name = (string) ($args['name'] ?? '');
        if ('' === $name) {
            throw new \InvalidArgumentException('name is required');
        }

        $workflow = $this->index->getWorkflow($name);
        if (null === $workflow) {
            if (!$this->index->hasWorkflowsSection()) {
                return "Workflow \"{$name}\" not found.\nHint: the loaded index does not include a workflows section — regenerate it with code-context >= 1.1.0 to enable get_workflow.";
            }

            return "Workflow \"{$name}\" not found in config/packages/.";
        }

        $lines = [];
        $lines[] = "## Workflow `{$name}`";
        $lines[] = '';
        $lines[] = '**Type:** `' . (string) ($workflow['type'] ?? 'workflow') . '`';
        $lines[] = '**File:** `' . (string) ($workflow['file'] ?? '') . '`';

        $supports = (array) ($workflow['supports'] ?? []);
        if ([] !== $supports) {
            $lines[] = '**Supports:** ' . implode(', ', array_map(static fn (string $s): string => '`' . $s . '`', array_map('strval', $supports)));
        }

        if (null !== ($workflow['initial_marking'] ?? null)) {
            $lines[] = '**Initial marking:** `' . (string) $workflow['initial_marking'] . '`';
        }

        $places = (array) ($workflow['places'] ?? []);
        if ([] !== $places) {
            $lines[] = '';
            $lines[] = '### Places';
            foreach ($places as $place) {
                $lines[] = "- `{$place}`";
            }
        }

        $transitions = (array) ($workflow['transitions'] ?? []);
        if ([] !== $transitions) {
            $lines[] = '';
            $lines[] = '### Transitions';
            foreach ($transitions as $transition) {
                if (!\is_array($transition)) {
                    continue;
                }
                $tName = (string) ($transition['name'] ?? '');
                $from = implode(', ', array_map('strval', (array) ($transition['from'] ?? [])));
                $to = implode(', ', array_map('strval', (array) ($transition['to'] ?? [])));
                $guard = isset($transition['guard']) ? ' — guard: `' . $transition['guard'] . '`' : '';
                $lines[] = "- `{$tName}`: [{$from}] → [{$to}]{$guard}";
            }
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Tool definitions (JSON Schema)
    // -------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function toolDefinitions(): array
    {
        $pagination = [
            'limit' => ['type' => 'integer', 'description' => 'Maximum number of results to return (default: 50)'],
            'offset' => ['type' => 'integer', 'description' => 'Number of results to skip (default: 0)'],
        ];

        return [
            [
                'name' => 'search_symbol',
                'description' => 'Search for classes, interfaces, traits, and enums by name substring. Returns compact summaries. Use search_method to find methods by name.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Name substring to search for'],
                        'kind' => ['type' => 'string', 'enum' => ['class', 'interface', 'trait', 'enum'], 'description' => 'Optional kind filter'],
                        'limit' => ['type' => 'integer', 'description' => 'Maximum number of results to return (default: 50)'],
                        'offset' => ['type' => 'integer', 'description' => 'Number of results to skip (default: 0)'],
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
                'name' => 'explain_class',
                'description' => 'One-call situational overview of a class: inferred role, constructor dependencies, who uses it (with a per-layer breakdown), public API signatures, Symfony bindings (routes / message handler / subscribed events / voter / workflows) and Doctrine persistence. Compact by design — method signatures only, never bodies (use get_class for those).',
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
                'description' => 'Find all classes that reference a given type in their constructor parameters or properties (DI / type hints). Does not detect calls within method bodies.',
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
                        'filter' => ['type' => 'string', 'description' => 'Optional substring filter on controller class/method, route name, path, HTTP methods or the raw attribute'],
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
                        'limit' => ['type' => 'integer', 'description' => 'Maximum number of classes to return (default: 50)'],
                        'offset' => ['type' => 'integer', 'description' => 'Number of classes to skip (default: 0)'],
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
                        'limit' => ['type' => 'integer', 'description' => 'Maximum number of results to return (default: 50)'],
                        'offset' => ['type' => 'integer', 'description' => 'Number of results to skip (default: 0)'],
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
                        'limit' => ['type' => 'integer', 'description' => 'Maximum number of results to return (default: 50)'],
                        'offset' => ['type' => 'integer', 'description' => 'Number of results to skip (default: 0)'],
                    ],
                    'required' => ['attribute'],
                ],
            ],
            [
                'name' => 'get_commands',
                'description' => 'List all Symfony console commands declared via #[AsCommand].',
                'inputSchema' => ['type' => 'object', 'properties' => $pagination],
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
                'inputSchema' => ['type' => 'object', 'properties' => $pagination],
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
                'inputSchema' => ['type' => 'object', 'properties' => $pagination],
            ],
            [
                'name' => 'find_service',
                'description' => 'Search the Symfony service container: matches configured services by id and autowired services by FQCN/namespace. Omit query to list the whole container. Each section (configured / autowired) is paginated independently.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Optional substring to match against service ids, FQCNs or namespaces. Omit to list everything.'],
                        'limit' => ['type' => 'integer', 'description' => 'Maximum number of results per section to return (default: 50)'],
                        'offset' => ['type' => 'integer', 'description' => 'Number of results to skip per section (default: 0)'],
                    ],
                ],
            ],
            [
                'name' => 'find_voters',
                'description' => 'List all Symfony Security Voters (transitive subclasses of Voter or implementors of VoterInterface).',
                'inputSchema' => ['type' => 'object', 'properties' => $pagination],
            ],
            [
                'name' => 'find_message_handlers',
                'description' => 'List all Symfony Messenger handlers (classes carrying #[AsMessageHandler]) with the message type they handle.',
                'inputSchema' => ['type' => 'object', 'properties' => $pagination],
            ],
            [
                'name' => 'find_subscribers',
                'description' => 'List Symfony EventSubscriberInterface implementors with their subscribed events and priorities. Optional event filter does substring match on event names.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'event' => ['type' => 'string', 'description' => 'Optional event name substring filter (e.g. "kernel.request")'],
                    ] + $pagination,
                ],
            ],
            [
                'name' => 'list_workflows',
                'description' => 'List all Symfony workflows and state machines configured under framework.workflows in config/packages/.',
                'inputSchema' => ['type' => 'object', 'properties' => $pagination],
            ],
            [
                'name' => 'get_workflow',
                'description' => 'Get the full definition of a Symfony workflow or state machine: places, transitions, supports, guards.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Workflow name as declared in framework.workflows.<name>'],
                    ],
                    'required' => ['name'],
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
