<?php

declare(strict_types=1);

namespace CodeContext\Tests\Extractor\Symfony;

use CodeContext\Analyzer\PhpAstAnalyzer;
use CodeContext\Config\Config;
use CodeContext\Extractor\Symfony\EntityExtractor;
use CodeContext\Kernel\ProjectContext;
use CodeContext\Model\Context;
use PHPUnit\Framework\TestCase;

final class EntityExtractorTest extends TestCase
{
    public function testExtractsTypedDoctrineRelations(): void
    {
        $fixtureRoot = realpath(__DIR__ . '/../../Fixtures/symfony-mini');
        self::assertIsString($fixtureRoot);

        $config = new Config([
            'extractors' => ['symfony' => ['entities' => true]],
            'analyzers' => ['php' => ['include_attributes' => true]],
        ]);
        $analyzer = new PhpAstAnalyzer($config);
        $relativeFile = 'src/Entity/Article.php';
        $classes = $analyzer->analyze($fixtureRoot . '/' . $relativeFile, $relativeFile);

        $context = new Context(projectRoot: $fixtureRoot, generatedAt: '2026-06-05T00:00:00Z');
        $context->classes = $classes;

        $project = new ProjectContext(rootDir: $fixtureRoot, cwd: $fixtureRoot);

        (new EntityExtractor())->extract($project, $config, $context);

        $entities = $context->symfony['entities'] ?? [];
        self::assertCount(1, $entities);
        self::assertSame('App\\Entity\\Article', $entities[0]['class']);

        $relations = $entities[0]['relations'];
        self::assertCount(3, $relations);

        // ManyToOne: target from explicit targetEntity (resolved to FQCN), inversedBy captured
        self::assertSame('author', $relations[0]['property']);
        self::assertSame('ManyToOne', $relations[0]['kind']);
        self::assertSame('App\\Entity\\Author', $relations[0]['target']);
        self::assertSame('articles', $relations[0]['inversedBy']);

        // OneToMany: Collection-typed side relies on targetEntity, mappedBy captured
        self::assertSame('comments', $relations[1]['property']);
        self::assertSame('OneToMany', $relations[1]['kind']);
        self::assertSame('App\\Entity\\Comment', $relations[1]['target']);
        self::assertSame('article', $relations[1]['mappedBy']);

        // OneToOne: no targetEntity argument → target inferred from the nullable property type
        self::assertSame('metadata', $relations[2]['property']);
        self::assertSame('OneToOne', $relations[2]['kind']);
        self::assertSame('App\\Entity\\Metadata', $relations[2]['target']);
        self::assertSame('article', $relations[2]['inversedBy']);
    }
}
