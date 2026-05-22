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
            'get_commands',
            'get_command',
            'list_entities',
            'get_entity',
            'get_entity_enums',
            'find_service',
        ] as $expected) {
            self::assertContains($expected, $names, "Tool {$expected} should be registered");
        }
    }
}
