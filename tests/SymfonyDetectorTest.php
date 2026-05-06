<?php

declare(strict_types=1);

namespace CodeContext\Tests;

use CodeContext\Detector\SymfonyDetector;
use PHPUnit\Framework\TestCase;

final class SymfonyDetectorTest extends TestCase
{
    public function testItDetectsSymfonyFrameworkBundle(): void
    {
        $tmp = sys_get_temp_dir() . '/code-context-detector-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0775, true);
        file_put_contents($tmp . '/composer.json', <<<'JSON'
{
  "require": {
    "php": "^8.2",
    "symfony/framework-bundle": "^8.0"
  }
}
JSON);

        $detector = new SymfonyDetector();
        $result = $detector->detect($tmp);

        self::assertTrue($result['detected']);
        self::assertSame('^8.0', $result['version']);
    }
}
