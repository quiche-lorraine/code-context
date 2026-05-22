<?php

declare(strict_types=1);

namespace CodeContext\Tests\Extractor\Symfony;

use CodeContext\Analyzer\PhpAstAnalyzer;
use CodeContext\Config\Config;
use CodeContext\Extractor\Symfony\EventSubscriberExtractor;
use CodeContext\Kernel\ProjectContext;
use CodeContext\Model\Context;
use PHPUnit\Framework\TestCase;

final class EventSubscriberExtractorTest extends TestCase
{
    public function testExtractsEventsFromGetSubscribedEvents(): void
    {
        $fixtureRoot = realpath(__DIR__ . '/../../Fixtures/symfony-mini');
        self::assertIsString($fixtureRoot);

        $config = new Config([
            'extractors' => ['symfony' => ['event_subscribers' => true]],
            'analyzers' => ['php' => ['include_attributes' => true]],
        ]);
        $analyzer = new PhpAstAnalyzer($config);
        $relativeFile = 'src/Subscriber/ExampleSubscriber.php';
        $classes = $analyzer->analyze($fixtureRoot . '/' . $relativeFile, $relativeFile);

        $context = new Context(projectRoot: $fixtureRoot, generatedAt: '2026-05-22T00:00:00Z');
        $context->classes = $classes;

        $project = new ProjectContext(rootDir: $fixtureRoot, cwd: $fixtureRoot);

        (new EventSubscriberExtractor())->extract($project, $config, $context);

        $subscribers = $context->symfony['event_subscribers'] ?? [];
        self::assertCount(1, $subscribers);
        self::assertSame('App\\Subscriber\\ExampleSubscriber', $subscribers[0]['class']);

        $events = $subscribers[0]['events'];
        self::assertCount(4, $events);

        // 1. 'kernel.request' => 'onRequest'
        self::assertSame('kernel.request', $events[0]['event']);
        self::assertSame('onRequest', $events[0]['method']);
        self::assertNull($events[0]['priority']);

        // 2. 'kernel.response' => ['onResponse', 100]
        self::assertSame('kernel.response', $events[1]['event']);
        self::assertSame('onResponse', $events[1]['method']);
        self::assertSame(100, $events[1]['priority']);

        // 3 & 4: 'app.signed' => [['onSignedHigh', 200], ['onSignedLow', -10]]
        self::assertSame('app.signed', $events[2]['event']);
        self::assertSame('onSignedHigh', $events[2]['method']);
        self::assertSame(200, $events[2]['priority']);

        self::assertSame('app.signed', $events[3]['event']);
        self::assertSame('onSignedLow', $events[3]['method']);
        self::assertSame(-10, $events[3]['priority']);
    }
}
