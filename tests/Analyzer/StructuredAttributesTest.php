<?php

declare(strict_types=1);

namespace CodeContext\Tests\Analyzer;

use CodeContext\Analyzer\PhpAstAnalyzer;
use CodeContext\Config\Config;
use CodeContext\Extractor\Symfony\RouteExtractor;
use CodeContext\Kernel\ProjectContext;
use CodeContext\Model\AttributeInfo;
use CodeContext\Model\Context;
use PHPUnit\Framework\TestCase;

final class StructuredAttributesTest extends TestCase
{
    private function analyzeController(): Context
    {
        $fixtureRoot = realpath(__DIR__ . '/../Fixtures/symfony-mini');
        self::assertIsString($fixtureRoot);

        $config = new Config([
            'analyzers' => ['php' => ['include_attributes' => true]],
        ]);
        $analyzer = new PhpAstAnalyzer($config);
        $relativeFile = 'src/Controller/BlogController.php';
        $classes = $analyzer->analyze($fixtureRoot . '/' . $relativeFile, $relativeFile);

        $context = new Context(projectRoot: $fixtureRoot, generatedAt: '2026-05-29T00:00:00Z');
        $context->classes = $classes;

        return $context;
    }

    public function testAttributesAreExtractedAsStructuredObjects(): void
    {
        $context = $this->analyzeController();
        $class = $context->classes[0];

        self::assertCount(1, $class->attributes);
        $classAttr = $class->attributes[0];
        self::assertInstanceOf(AttributeInfo::class, $classAttr);
        self::assertSame('Route', $classAttr->shortName());
        self::assertCount(1, $classAttr->arguments);
        self::assertNull($classAttr->arguments[0]->name);
        self::assertSame("'/blog'", $classAttr->arguments[0]->value);
    }

    public function testMethodAttributeExposesNamedArguments(): void
    {
        $context = $this->analyzeController();
        $method = $context->classes[0]->methods[0];

        self::assertSame('list', $method->name);
        self::assertCount(1, $method->attributes);
        $route = $method->attributes[0];

        $named = [];
        foreach ($route->arguments as $arg) {
            if (null !== $arg->name) {
                $named[$arg->name] = $arg->value;
            }
        }
        self::assertSame("'blog_list'", $named['name'] ?? null);
        self::assertArrayHasKey('methods', $named);
    }

    public function testParameterAttributesAreCaptured(): void
    {
        $context = $this->analyzeController();
        $param = $context->classes[0]->methods[0]->parameters[0];

        self::assertSame('token', $param->name);
        self::assertCount(1, $param->attributes);
        self::assertSame('SensitiveParameter', $param->attributes[0]->shortName());
    }

    public function testRouteExtractorResolvesConstantMethods(): void
    {
        $context = $this->analyzeController();
        $config = new Config(['extractors' => ['symfony' => ['routes' => true]]]);
        $fixtureRoot = $context->projectRoot;
        $project = new ProjectContext(rootDir: $fixtureRoot, cwd: $fixtureRoot);

        (new RouteExtractor())->extract($project, $config, $context);

        $routes = $context->symfony['routes'] ?? [];
        $methodRoute = null;
        foreach ($routes as $route) {
            if (($route['name'] ?? null) === 'blog_list') {
                $methodRoute = $route;
                break;
            }
        }

        self::assertNotNull($methodRoute, 'The method-level route should be extracted.');
        self::assertSame('/list', $methodRoute['path']);
        // `methods: [Request::METHOD_GET]` must resolve to the bare verb, which the
        // old regex-based parser could not do.
        self::assertSame(['GET'], $methodRoute['methods']);
    }
}
