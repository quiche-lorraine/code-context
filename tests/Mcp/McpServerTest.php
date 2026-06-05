<?php

declare(strict_types=1);

namespace CodeContext\Tests\Mcp;

use CodeContext\Index\ClassIndex;
use CodeContext\Mcp\McpServer;
use PHPUnit\Framework\TestCase;

final class McpServerTest extends TestCase
{
    /**
     * @param array<string, mixed> $args
     */
    private function callTool(McpServer $server, string $name, array $args = []): string
    {
        $ref = new \ReflectionClass($server);
        $method = $ref->getMethod('handleToolsCall');
        $method->setAccessible(true);
        /** @var array{content: list<array{type: string, text: string}>, isError: bool} $response */
        $response = $method->invoke($server, ['name' => $name, 'arguments' => $args]);

        return (string) ($response['content'][0]['text'] ?? '');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listTools(McpServer $server): array
    {
        $ref = new \ReflectionClass($server);
        $method = $ref->getMethod('handleToolsList');
        $method->setAccessible(true);
        /** @var array{tools: list<array<string, mixed>>} $result */
        $result = $method->invoke($server);

        return $result['tools'];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function dispatch(McpServer $server, string $rpcMethod, array $params = []): array
    {
        $ref = new \ReflectionClass($server);
        $method = $ref->getMethod('dispatch');
        $method->setAccessible(true);
        /** @var array<string, mixed> $result */
        $result = $method->invoke($server, $rpcMethod, $params);

        return $result;
    }

    private function buildFixture(): McpServer
    {
        $contextData = [
            'php' => [
                'classes' => [
                    [
                        'fqcn' => 'App\\Entity\\User',
                        'short_name' => 'User',
                        'namespace' => 'App\\Entity',
                        'kind' => 'class',
                        'file' => 'src/Entity/User.php',
                        'abstract' => false,
                        'final' => false,
                        'readonly' => false,
                        'extends' => null,
                        'implements' => [],
                        'traits' => [],
                        'attributes' => [
                            ['name' => 'Doctrine\\ORM\\Mapping\\Entity', 'arguments' => []],
                        ],
                        'methods' => [
                            [
                                'name' => 'getId',
                                'visibility' => 'public',
                                'static' => false,
                                'return_type' => 'int',
                                'parameters' => [],
                                'attributes' => [],
                                'summary' => null,
                            ],
                        ],
                        'properties' => [
                            [
                                'name' => 'email',
                                'visibility' => 'private',
                                'static' => false,
                                'readonly' => false,
                                'type' => 'string',
                                'default' => null,
                                'attributes' => [
                                    [
                                        'name' => 'ORM\\Column',
                                        'arguments' => [
                                            ['name' => 'type', 'value' => '"string"'],
                                            ['name' => 'length', 'value' => '180'],
                                            ['name' => 'unique', 'value' => 'true'],
                                        ],
                                    ],
                                ],
                                'summary' => null,
                            ],
                        ],
                        'summary' => null,
                        'cases' => [],
                    ],
                    [
                        'fqcn' => 'App\\Command\\SyncCommand',
                        'short_name' => 'SyncCommand',
                        'namespace' => 'App\\Command',
                        'kind' => 'class',
                        'file' => 'src/Command/SyncCommand.php',
                        'abstract' => false,
                        'final' => true,
                        'readonly' => false,
                        'extends' => 'Symfony\\Component\\Console\\Command\\Command',
                        'implements' => [],
                        'traits' => [],
                        'attributes' => [
                            [
                                'name' => 'AsCommand',
                                'arguments' => [
                                    ['name' => 'name', 'value' => '"app:sync"'],
                                    ['name' => 'description', 'value' => '"Sync stuff"'],
                                ],
                            ],
                        ],
                        'methods' => [
                            [
                                'name' => 'execute',
                                'visibility' => 'protected',
                                'static' => false,
                                'return_type' => 'int',
                                'parameters' => [],
                                'attributes' => [
                                    ['name' => 'Override', 'arguments' => []],
                                ],
                                'summary' => null,
                            ],
                        ],
                        'properties' => [],
                        'summary' => null,
                        'cases' => [],
                    ],
                    [
                        'fqcn' => 'App\\Enum\\Status',
                        'short_name' => 'Status',
                        'namespace' => 'App\\Enum',
                        'kind' => 'enum',
                        'file' => 'src/Enum/Status.php',
                        'abstract' => false,
                        'final' => true,
                        'readonly' => false,
                        'extends' => null,
                        'implements' => [],
                        'traits' => [],
                        'attributes' => [],
                        'methods' => [],
                        'properties' => [],
                        'summary' => null,
                        'cases' => ['ACTIVE', 'DISABLED'],
                    ],
                ],
                'graph' => [],
            ],
            'symfony' => [
                'routes' => [],
                'commands' => [
                    [
                        'class' => 'App\\Command\\SyncCommand',
                        'file' => 'src/Command/SyncCommand.php',
                        'name' => 'app:sync',
                        'description' => 'Sync stuff',
                    ],
                ],
                'entities' => [
                    [
                        'class' => 'App\\Entity\\User',
                        'file' => 'src/Entity/User.php',
                        'properties' => [
                            [
                                'name' => 'email',
                                'type' => 'string',
                                'visibility' => 'private',
                                'attributes' => [
                                    [
                                        'name' => 'ORM\\Column',
                                        'arguments' => [
                                            ['name' => 'type', 'value' => '"string"'],
                                            ['name' => 'length', 'value' => '180'],
                                            ['name' => 'unique', 'value' => 'true'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'entity_enums' => [
                    [
                        'class' => 'App\\Enum\\Status',
                        'cases' => ['ACTIVE', 'DISABLED'],
                    ],
                ],
                'services' => [
                    'configured' => [
                        [
                            'id' => 'app.custom_service',
                            'definition' => ['class' => 'App\\Service\\Custom'],
                        ],
                    ],
                    'autowired' => [
                        ['fqcn' => 'App\\Command\\SyncCommand', 'namespace' => 'App\\Command'],
                        ['fqcn' => 'App\\Entity\\User', 'namespace' => 'App\\Entity'],
                    ],
                ],
            ],
        ];

        return new McpServer(ClassIndex::fromContextArray($contextData));
    }

    public function testGetClassRendersPropertyAttributes(): void
    {
        $output = $this->callTool($this->buildFixture(), 'get_class', ['fqcn' => 'App\\Entity\\User']);

        self::assertStringContainsString('### Properties', $output);
        self::assertStringContainsString('private string $email', $output);
        self::assertStringContainsString('Attributes:', $output);
        self::assertStringContainsString('#[ORM\\Column', $output);
    }

    public function testGetClassRendersMethodAttributes(): void
    {
        $output = $this->callTool($this->buildFixture(), 'get_class', ['fqcn' => 'App\\Command\\SyncCommand']);

        self::assertStringContainsString('### Methods', $output);
        self::assertStringContainsString('`execute()', $output);
        self::assertStringContainsString('#[Override]', $output);
    }

    public function testFindByAttributeReturnsClassMatches(): void
    {
        $output = $this->callTool($this->buildFixture(), 'find_by_attribute', ['attribute' => 'Entity']);

        self::assertStringContainsString('### Classes', $output);
        self::assertStringContainsString('App\\Entity\\User', $output);
        self::assertStringNotContainsString('### Properties', $output);
    }

    public function testFindByAttributeScopedToPropertyExcludesClass(): void
    {
        $output = $this->callTool($this->buildFixture(), 'find_by_attribute', [
            'attribute' => 'Column',
            'scope' => 'property',
        ]);

        self::assertStringContainsString('### Properties', $output);
        self::assertStringContainsString('App\\Entity\\User::$email', $output);
        self::assertStringNotContainsString('### Classes', $output);
    }

    public function testFindByAttributeIsCaseInsensitive(): void
    {
        $output = $this->callTool($this->buildFixture(), 'find_by_attribute', ['attribute' => 'entity']);

        self::assertStringContainsString('App\\Entity\\User', $output);
    }

    public function testGetCommandsListsAllCommands(): void
    {
        $output = $this->callTool($this->buildFixture(), 'get_commands');

        self::assertStringContainsString('Symfony Commands (1)', $output);
        self::assertStringContainsString('app:sync', $output);
        self::assertStringContainsString('Sync stuff', $output);
    }

    public function testGetCommandByName(): void
    {
        $output = $this->callTool($this->buildFixture(), 'get_command', ['name' => 'app:sync']);

        self::assertStringContainsString('## app:sync', $output);
        self::assertStringContainsString('App\\Command\\SyncCommand', $output);
        self::assertStringContainsString('Sync stuff', $output);
    }

    public function testGetCommandNotFound(): void
    {
        $output = $this->callTool($this->buildFixture(), 'get_command', ['name' => 'app:missing']);

        self::assertStringContainsString('not found', $output);
    }

    public function testListEntities(): void
    {
        $output = $this->callTool($this->buildFixture(), 'list_entities');

        self::assertStringContainsString('Doctrine Entities (1)', $output);
        self::assertStringContainsString('App\\Entity\\User', $output);
    }

    public function testGetEntityRendersProperties(): void
    {
        $output = $this->callTool($this->buildFixture(), 'get_entity', ['class' => 'App\\Entity\\User']);

        self::assertStringContainsString('## Entity App\\Entity\\User', $output);
        self::assertStringContainsString('private string $email', $output);
        self::assertStringContainsString('#[ORM\\Column', $output);
    }

    public function testGetEntityEnums(): void
    {
        $output = $this->callTool($this->buildFixture(), 'get_entity_enums');

        self::assertStringContainsString('Entity Enums (1)', $output);
        self::assertStringContainsString('App\\Enum\\Status', $output);
        self::assertStringContainsString('ACTIVE, DISABLED', $output);
    }

    public function testFindServiceMatchesAutowired(): void
    {
        $output = $this->callTool($this->buildFixture(), 'find_service', ['query' => 'Sync']);

        self::assertStringContainsString('Autowired services', $output);
        self::assertStringContainsString('App\\Command\\SyncCommand', $output);
        self::assertStringNotContainsString('App\\Entity\\User', $output);
    }

    public function testFindServiceMatchesConfigured(): void
    {
        $output = $this->callTool($this->buildFixture(), 'find_service', ['query' => 'app.custom_service']);

        self::assertStringContainsString('Configured services', $output);
        self::assertStringContainsString('app.custom_service', $output);
        self::assertStringContainsString('App\\Service\\Custom', $output);
    }

    public function testToolsListIncludesNewTools(): void
    {
        $tools = $this->listTools($this->buildFixture());
        $names = array_map(static fn (array $t): string => (string) ($t['name'] ?? ''), $tools);

        foreach ([
            'find_by_attribute',
            'search_method',
            'get_route',
            'get_commands',
            'get_command',
            'list_entities',
            'get_entity',
            'get_entity_enums',
            'find_service',
            'find_voters',
            'find_message_handlers',
            'find_subscribers',
            'list_workflows',
            'get_workflow',
        ] as $expected) {
            self::assertContains($expected, $names, "Tool {$expected} should be registered");
        }
    }

    public function testSearchMethodReturnMatchingMethod(): void
    {
        $output = $this->callTool($this->buildFixture(), 'search_method', ['query' => 'execute']);

        self::assertStringContainsString('`execute()', $output);
        self::assertStringContainsString('App\\Command\\SyncCommand', $output);
        // Should NOT return the containing class summary — it's a method result
        self::assertStringNotContainsString('## SyncCommand', $output);
    }

    public function testSearchMethodDoesNotReturnContainingClass(): void
    {
        // search_symbol for 'execute' would return SyncCommand class; search_method should return just the method
        $output = $this->callTool($this->buildFixture(), 'search_method', ['query' => 'getId']);

        self::assertStringContainsString('`getId()', $output);
        self::assertStringContainsString('App\\Entity\\User', $output);
    }

    public function testGetClassDisambiguatesAmbiguousShortName(): void
    {
        // Build a fixture with two classes sharing the same short name
        $contextData = [
            'php' => [
                'classes' => [
                    [
                        'fqcn' => 'App\\Entity\\Card',
                        'short_name' => 'Card',
                        'namespace' => 'App\\Entity',
                        'kind' => 'class',
                        'file' => 'src/Entity/Card.php',
                        'abstract' => false, 'final' => false, 'readonly' => false,
                        'extends' => null, 'implements' => [], 'traits' => [], 'attributes' => [],
                        'methods' => [], 'properties' => [], 'summary' => null, 'cases' => [],
                    ],
                    [
                        'fqcn' => 'App\\Document\\Card',
                        'short_name' => 'Card',
                        'namespace' => 'App\\Document',
                        'kind' => 'class',
                        'file' => 'src/Document/Card.php',
                        'abstract' => false, 'final' => false, 'readonly' => false,
                        'extends' => null, 'implements' => [], 'traits' => [], 'attributes' => [],
                        'methods' => [], 'properties' => [], 'summary' => null, 'cases' => [],
                    ],
                ],
                'graph' => [],
            ],
            'symfony' => [],
        ];

        $server = new McpServer(ClassIndex::fromContextArray($contextData));
        $output = $this->callTool($server, 'get_class', ['fqcn' => 'Card']);

        self::assertStringContainsString('Ambiguous', $output);
        self::assertStringContainsString('App\\Entity\\Card', $output);
        self::assertStringContainsString('App\\Document\\Card', $output);
    }

    public function testGetRoutesWithPagination(): void
    {
        // Build a fixture with multiple routes
        $contextData = [
            'php' => ['classes' => [], 'graph' => []],
            'symfony' => [
                'routes' => [
                    ['scope' => 'method', 'class' => 'App\\Controller\\FooController', 'method' => 'index', 'attribute' => "Route('/foo', name: 'foo_index')", 'name' => 'foo_index', 'path' => '/foo', 'methods' => ['GET']],
                    ['scope' => 'method', 'class' => 'App\\Controller\\FooController', 'method' => 'show', 'attribute' => "Route('/foo/{id}', name: 'foo_show')", 'name' => 'foo_show', 'path' => '/foo/{id}', 'methods' => ['GET']],
                    ['scope' => 'method', 'class' => 'App\\Controller\\BarController', 'method' => 'index', 'attribute' => "Route('/bar', name: 'bar_index')", 'name' => 'bar_index', 'path' => '/bar', 'methods' => ['GET']],
                ],
            ],
        ];

        $server = new McpServer(ClassIndex::fromContextArray($contextData));

        // Without pagination: all 3
        $output = $this->callTool($server, 'get_routes');
        self::assertStringContainsString('Routes (3)', $output);

        // With limit=2
        $output = $this->callTool($server, 'get_routes', ['limit' => 2]);
        self::assertStringContainsString('showing 0–1 of 3', $output);
        self::assertStringNotContainsString('bar_index', $output);

        // With offset=2
        $output = $this->callTool($server, 'get_routes', ['limit' => 2, 'offset' => 2]);
        self::assertStringContainsString('showing 2–2 of 3', $output);
        self::assertStringContainsString('bar_index', $output);
    }

    public function testGetRouteByName(): void
    {
        $contextData = [
            'php' => ['classes' => [], 'graph' => []],
            'symfony' => [
                'routes' => [
                    ['scope' => 'method', 'class' => 'App\\Controller\\FooController', 'method' => 'index', 'attribute' => "Route('/foo', name: 'foo_index')", 'name' => 'foo_index', 'path' => '/foo', 'methods' => ['GET']],
                ],
            ],
        ];

        $server = new McpServer(ClassIndex::fromContextArray($contextData));

        $output = $this->callTool($server, 'get_route', ['name' => 'foo_index']);
        self::assertStringContainsString('## Route `foo_index`', $output);
        self::assertStringContainsString('/foo', $output);
        self::assertStringContainsString('GET', $output);
        self::assertStringContainsString('FooController::index', $output);
    }

    public function testGetRouteNotFound(): void
    {
        $output = $this->callTool($this->buildFixture(), 'get_route', ['name' => 'nonexistent']);

        self::assertStringContainsString('not found', $output);
    }

    public function testFindVoters(): void
    {
        $contextData = [
            'php' => [
                'classes' => [],
                'graph' => [
                    'subclasses' => [
                        'Symfony\\Component\\Security\\Core\\Authorization\\Voter\\Voter' => [
                            'App\\Security\\Voter\\PostVoter',
                            'App\\Security\\Voter\\CardVoter',
                        ],
                    ],
                ],
            ],
            'symfony' => [],
        ];
        $server = new McpServer(ClassIndex::fromContextArray($contextData));

        $output = $this->callTool($server, 'find_voters');

        self::assertStringContainsString('Security Voters (2)', $output);
        self::assertStringContainsString('App\\Security\\Voter\\PostVoter', $output);
        self::assertStringContainsString('App\\Security\\Voter\\CardVoter', $output);
    }

    public function testFindMessageHandlers(): void
    {
        $contextData = [
            'php' => [
                'classes' => [
                    [
                        'fqcn' => 'App\\MessageHandler\\SendEmailHandler',
                        'short_name' => 'SendEmailHandler',
                        'namespace' => 'App\\MessageHandler',
                        'kind' => 'class',
                        'file' => 'src/MessageHandler/SendEmailHandler.php',
                        'abstract' => false, 'final' => false, 'readonly' => false,
                        'extends' => null, 'implements' => [], 'traits' => [],
                        'attributes' => [
                            ['name' => 'AsMessageHandler', 'arguments' => []],
                        ],
                        'methods' => [[
                            'name' => '__invoke',
                            'visibility' => 'public',
                            'static' => false,
                            'return_type' => 'void',
                            'parameters' => [['name' => 'message', 'type' => 'App\\Message\\SendEmailMessage']],
                            'attributes' => [],
                            'summary' => null,
                        ]],
                        'properties' => [], 'summary' => null, 'cases' => [],
                    ],
                ],
                'graph' => [],
            ],
            'symfony' => [],
        ];
        $server = new McpServer(ClassIndex::fromContextArray($contextData));

        $output = $this->callTool($server, 'find_message_handlers');

        self::assertStringContainsString('Messenger Handlers (1)', $output);
        self::assertStringContainsString('App\\MessageHandler\\SendEmailHandler::__invoke', $output);
        self::assertStringContainsString('App\\Message\\SendEmailMessage', $output);
    }

    public function testFindSubscribersWithoutFilter(): void
    {
        $contextData = [
            'php' => ['classes' => [], 'graph' => []],
            'symfony' => [
                'event_subscribers' => [
                    [
                        'class' => 'App\\Subscriber\\RequestSubscriber',
                        'file' => 'src/Subscriber/RequestSubscriber.php',
                        'events' => [
                            ['event' => 'kernel.request', 'method' => 'onRequest', 'priority' => 100],
                            ['event' => 'kernel.response', 'method' => 'onResponse', 'priority' => null],
                        ],
                    ],
                ],
            ],
        ];
        $server = new McpServer(ClassIndex::fromContextArray($contextData));

        $output = $this->callTool($server, 'find_subscribers');

        self::assertStringContainsString('Event Subscribers (1)', $output);
        self::assertStringContainsString('RequestSubscriber', $output);
        self::assertStringContainsString('kernel.request', $output);
        self::assertStringContainsString('priority: 100', $output);
        self::assertStringContainsString('kernel.response', $output);
    }

    public function testFindSubscribersFilteredByEvent(): void
    {
        $contextData = [
            'php' => ['classes' => [], 'graph' => []],
            'symfony' => [
                'event_subscribers' => [
                    [
                        'class' => 'App\\Subscriber\\OneSubscriber',
                        'file' => 'src/Subscriber/OneSubscriber.php',
                        'events' => [['event' => 'kernel.request', 'method' => 'onRequest', 'priority' => null]],
                    ],
                    [
                        'class' => 'App\\Subscriber\\OtherSubscriber',
                        'file' => 'src/Subscriber/OtherSubscriber.php',
                        'events' => [['event' => 'kernel.response', 'method' => 'onResponse', 'priority' => null]],
                    ],
                ],
            ],
        ];
        $server = new McpServer(ClassIndex::fromContextArray($contextData));

        $output = $this->callTool($server, 'find_subscribers', ['event' => 'request']);

        self::assertStringContainsString('OneSubscriber', $output);
        self::assertStringNotContainsString('OtherSubscriber', $output);
    }

    public function testListWorkflowsAndGetWorkflow(): void
    {
        $contextData = [
            'php' => ['classes' => [], 'graph' => []],
            'symfony' => [
                'workflows' => [
                    [
                        'name' => 'article_publishing',
                        'file' => 'config/packages/workflow.yaml',
                        'type' => 'state_machine',
                        'supports' => ['App\\Entity\\Article'],
                        'initial_marking' => 'draft',
                        'places' => ['draft', 'reviewed', 'published'],
                        'transitions' => [
                            ['name' => 'review', 'from' => ['draft'], 'to' => ['reviewed'], 'guard' => null],
                            ['name' => 'publish', 'from' => ['reviewed'], 'to' => ['published'], 'guard' => "is_granted('ROLE_ADMIN')"],
                        ],
                    ],
                ],
            ],
        ];
        $server = new McpServer(ClassIndex::fromContextArray($contextData));

        $listOutput = $this->callTool($server, 'list_workflows');
        self::assertStringContainsString('Workflows (1)', $listOutput);
        self::assertStringContainsString('article_publishing', $listOutput);
        self::assertStringContainsString('state_machine', $listOutput);

        $detailOutput = $this->callTool($server, 'get_workflow', ['name' => 'article_publishing']);
        self::assertStringContainsString('## Workflow `article_publishing`', $detailOutput);
        self::assertStringContainsString('App\\Entity\\Article', $detailOutput);
        self::assertStringContainsString('draft', $detailOutput);
        self::assertStringContainsString('reviewed', $detailOutput);
        self::assertStringContainsString('published', $detailOutput);
        self::assertStringContainsString('review', $detailOutput);
        self::assertStringContainsString("guard: `is_granted('ROLE_ADMIN')`", $detailOutput);
    }

    public function testGetWorkflowNotFound(): void
    {
        $output = $this->callTool($this->buildFixture(), 'get_workflow', ['name' => 'missing']);

        self::assertStringContainsString('not found', $output);
    }

    public function testGetRoutesFilterMatchesNamePathAndMethods(): void
    {
        $contextData = [
            'php' => ['classes' => [], 'graph' => []],
            'symfony' => [
                'routes' => [
                    ['scope' => 'method', 'class' => 'App\\Controller\\UserController', 'method' => 'list', 'attribute' => 'Route(...)', 'name' => 'api_users_list', 'path' => '/api/users', 'methods' => ['GET']],
                    ['scope' => 'method', 'class' => 'App\\Controller\\HomeController', 'method' => 'index', 'attribute' => 'Route(...)', 'name' => 'home', 'path' => '/', 'methods' => ['POST']],
                ],
            ],
        ];
        $server = new McpServer(ClassIndex::fromContextArray($contextData));

        // Filter on route name (not present in class/method/attribute)
        $byName = $this->callTool($server, 'get_routes', ['filter' => 'api_users_']);
        self::assertStringContainsString('api_users_list', $byName);
        self::assertStringNotContainsString('home', $byName);

        // Filter on path
        $byPath = $this->callTool($server, 'get_routes', ['filter' => '/api/']);
        self::assertStringContainsString('api_users_list', $byPath);
        self::assertStringNotContainsString('name=home', $byPath);

        // Filter on HTTP method
        $byMethod = $this->callTool($server, 'get_routes', ['filter' => 'post']);
        self::assertStringContainsString('home', $byMethod);
        self::assertStringNotContainsString('api_users_list', $byMethod);
    }

    public function testFindSubscribersPagination(): void
    {
        $contextData = [
            'php' => ['classes' => [], 'graph' => []],
            'symfony' => [
                'event_subscribers' => [
                    ['class' => 'App\\Subscriber\\OneSubscriber', 'file' => 'a.php', 'events' => [['event' => 'e1', 'method' => 'm', 'priority' => null]]],
                    ['class' => 'App\\Subscriber\\TwoSubscriber', 'file' => 'b.php', 'events' => [['event' => 'e2', 'method' => 'm', 'priority' => null]]],
                    ['class' => 'App\\Subscriber\\ThreeSubscriber', 'file' => 'c.php', 'events' => [['event' => 'e3', 'method' => 'm', 'priority' => null]]],
                ],
            ],
        ];
        $server = new McpServer(ClassIndex::fromContextArray($contextData));

        $output = $this->callTool($server, 'find_subscribers', ['limit' => 2]);
        self::assertStringContainsString('(3) showing 0–1', $output);
        self::assertStringContainsString('OneSubscriber', $output);
        self::assertStringNotContainsString('ThreeSubscriber', $output);

        $page2 = $this->callTool($server, 'find_subscribers', ['limit' => 2, 'offset' => 2]);
        self::assertStringContainsString('(3) showing 2–2', $page2);
        self::assertStringContainsString('ThreeSubscriber', $page2);
    }

    public function testFindServiceWithoutQueryListsEverything(): void
    {
        $output = $this->callTool($this->buildFixture(), 'find_service');

        self::assertStringContainsString('Configured services', $output);
        self::assertStringContainsString('app.custom_service', $output);
        self::assertStringContainsString('Autowired services', $output);
        self::assertStringContainsString('App\\Command\\SyncCommand', $output);
        self::assertStringContainsString('paged independently', $output);
    }

    public function testFindServiceQueryIsOptionalInSchema(): void
    {
        $tools = $this->listTools($this->buildFixture());
        $findService = null;
        foreach ($tools as $tool) {
            if (($tool['name'] ?? null) === 'find_service') {
                $findService = $tool;
                break;
            }
        }

        self::assertNotNull($findService);
        $required = (array) ($findService['inputSchema']['required'] ?? []);
        self::assertNotContains('query', $required);
    }

    public function testExplainClassIsRegistered(): void
    {
        $tools = $this->listTools($this->buildFixture());
        $names = array_map(static fn (array $t): string => (string) ($t['name'] ?? ''), $tools);

        self::assertContains('explain_class', $names);
    }

    public function testInitializeEchoesClientProtocolVersion(): void
    {
        $server = $this->buildFixture();

        $echoed = $this->dispatch($server, 'initialize', ['protocolVersion' => '2025-06-18']);
        self::assertSame('2025-06-18', $echoed['protocolVersion'] ?? null);

        $fallback = $this->dispatch($server, 'initialize', []);
        self::assertSame('2024-11-05', $fallback['protocolVersion'] ?? null);
    }

    /**
     * @return McpServer
     */
    private function buildExplainFixture(): McpServer
    {
        $contextData = [
            'php' => [
                'classes' => [
                    [
                        'fqcn' => 'App\\Manager\\FooManager',
                        'short_name' => 'FooManager',
                        'namespace' => 'App\\Manager',
                        'kind' => 'class',
                        'file' => 'src/Manager/FooManager.php',
                        'abstract' => false, 'final' => false, 'readonly' => false,
                        'extends' => null, 'implements' => [], 'traits' => [],
                        'attributes' => [],
                        'methods' => [
                            [
                                'name' => '__construct',
                                'visibility' => 'public', 'static' => false, 'return_type' => null,
                                'parameters' => [
                                    ['name' => 'entityManager', 'type' => 'Doctrine\\ORM\\EntityManagerInterface'],
                                    ['name' => 'barService', 'type' => 'App\\Service\\BarService'],
                                ],
                                'attributes' => [], 'summary' => null,
                            ],
                            [
                                'name' => 'createDraft',
                                'visibility' => 'public', 'static' => false, 'return_type' => 'App\\Entity\\Foo',
                                'parameters' => [], 'attributes' => [], 'summary' => null,
                            ],
                            [
                                'name' => 'internalHelper',
                                'visibility' => 'private', 'static' => false, 'return_type' => 'void',
                                'parameters' => [], 'attributes' => [], 'summary' => null,
                            ],
                        ],
                        'properties' => [], 'summary' => null, 'cases' => [],
                    ],
                    [
                        'fqcn' => 'App\\Controller\\FooController',
                        'short_name' => 'FooController',
                        'namespace' => 'App\\Controller',
                        'kind' => 'class',
                        'file' => 'src/Controller/FooController.php',
                        'abstract' => false, 'final' => false, 'readonly' => false,
                        'extends' => null, 'implements' => [], 'traits' => [],
                        'attributes' => [], 'methods' => [], 'properties' => [], 'summary' => null, 'cases' => [],
                    ],
                ],
                'graph' => [
                    'type_usages' => [
                        'App\\Manager\\FooManager' => [
                            'App\\Controller\\FooController',
                            'App\\Command\\CleanFooCommand',
                            'App\\Tests\\Manager\\FooManagerTest',
                        ],
                    ],
                ],
            ],
            'symfony' => [
                'routes' => [
                    ['scope' => 'method', 'class' => 'App\\Controller\\FooController', 'method' => 'index', 'attribute' => 'Route(...)', 'name' => 'foo_index', 'path' => '/foo', 'methods' => ['GET']],
                ],
            ],
        ];

        return new McpServer(ClassIndex::fromContextArray($contextData));
    }

    public function testExplainClassManager(): void
    {
        $output = $this->callTool($this->buildExplainFixture(), 'explain_class', ['fqcn' => 'App\\Manager\\FooManager']);

        // Role + header
        self::assertStringContainsString('## manager `App\\Manager\\FooManager`', $output);

        // Constructor dependencies (outgoing)
        self::assertStringContainsString('### Depends on (2)', $output);
        self::assertStringContainsString('$entityManager: `Doctrine\\ORM\\EntityManagerInterface`', $output);

        // Usages (incoming) with per-layer breakdown
        self::assertStringContainsString('### Used by (3)', $output);
        self::assertStringContainsString('controller:1', $output);
        self::assertStringContainsString('command:1', $output);
        self::assertStringContainsString('App\\Controller\\FooController', $output);

        // Public API: signatures only, excludes constructor and private methods
        self::assertStringContainsString('createDraft(): App\\Entity\\Foo', $output);
        self::assertStringNotContainsString('internalHelper', $output);

        // Tested by, derived from the test-namespace usage
        self::assertStringContainsString('### Tested by (1)', $output);
        self::assertStringContainsString('App\\Tests\\Manager\\FooManagerTest', $output);
    }

    public function testExplainClassControllerReportsRoutes(): void
    {
        $output = $this->callTool($this->buildExplainFixture(), 'explain_class', ['fqcn' => 'App\\Controller\\FooController']);

        self::assertStringContainsString('## controller `App\\Controller\\FooController`', $output);
        self::assertStringContainsString('### Symfony', $output);
        self::assertStringContainsString('foo_index', $output);
        self::assertStringContainsString('/foo', $output);
    }

    public function testExplainClassEntityReportsPersistence(): void
    {
        $output = $this->callTool($this->buildRelationsFixture(), 'explain_class', ['fqcn' => 'App\\Entity\\Post']);

        self::assertStringContainsString('## entity `App\\Entity\\Post`', $output);
        self::assertStringContainsString('### Persistence (orm)', $output);
        self::assertStringContainsString('comments: OneToMany', $output);
        self::assertStringContainsString('App\\Entity\\Comment', $output);
    }

    public function testExplainClassNotFound(): void
    {
        $output = $this->callTool($this->buildExplainFixture(), 'explain_class', ['fqcn' => 'App\\Nope\\Missing']);

        self::assertStringContainsString('not found', $output);
    }

    /**
     * Fixture whose entity records already carry parsed `relations` (as the
     * EntityExtractor would emit), to exercise rendering in get_entity / explain_class.
     */
    private function buildRelationsFixture(): McpServer
    {
        $contextData = [
            'php' => [
                'classes' => [
                    [
                        'fqcn' => 'App\\Entity\\Post',
                        'short_name' => 'Post',
                        'namespace' => 'App\\Entity',
                        'kind' => 'class',
                        'file' => 'src/Entity/Post.php',
                        'abstract' => false, 'final' => false, 'readonly' => false,
                        'extends' => null, 'implements' => [], 'traits' => [],
                        'attributes' => [['name' => 'Doctrine\\ORM\\Mapping\\Entity', 'arguments' => []]],
                        'methods' => [], 'properties' => [], 'summary' => null, 'cases' => [],
                    ],
                ],
                'graph' => [],
            ],
            'symfony' => [
                'entities' => [
                    [
                        'class' => 'App\\Entity\\Post',
                        'file' => 'src/Entity/Post.php',
                        'properties' => [],
                        'relations' => [
                            ['property' => 'author', 'kind' => 'ManyToOne', 'target' => 'App\\Entity\\User', 'inversedBy' => 'posts'],
                            ['property' => 'comments', 'kind' => 'OneToMany', 'target' => 'App\\Entity\\Comment', 'mappedBy' => 'post'],
                        ],
                    ],
                ],
            ],
        ];

        return new McpServer(ClassIndex::fromContextArray($contextData));
    }

    public function testGetEntityRendersRelations(): void
    {
        $output = $this->callTool($this->buildRelationsFixture(), 'get_entity', ['class' => 'App\\Entity\\Post']);

        self::assertStringContainsString('### Relations', $output);
        self::assertStringContainsString('author: ManyToOne → `App\\Entity\\User` (inversedBy: posts)', $output);
        self::assertStringContainsString('comments: OneToMany → `App\\Entity\\Comment` (mappedBy: post)', $output);
    }
}
