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
                        'attributes' => ['Doctrine\\ORM\\Mapping\\Entity'],
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
                                'attributes' => ['ORM\\Column(type: "string", length: 180, unique: true)'],
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
                        'attributes' => ['AsCommand(name: "app:sync", description: "Sync stuff")'],
                        'methods' => [
                            [
                                'name' => 'execute',
                                'visibility' => 'protected',
                                'static' => false,
                                'return_type' => 'int',
                                'parameters' => [],
                                'attributes' => ['Override'],
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
                                'attributes' => ['ORM\\Column(type: "string", length: 180, unique: true)'],
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
                        'attributes' => ['AsMessageHandler'],
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
}
