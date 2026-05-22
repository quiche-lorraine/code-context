<?php

declare(strict_types=1);

namespace CodeContext\Tests;

use CodeContext\Config\ConfigLoader;
use PHPUnit\Framework\TestCase;

final class DefaultConfigTest extends TestCase
{
    public function testDefaultConfigProcessesWithoutError(): void
    {
        $defaultsPath = \dirname(__DIR__) . '/config/default.yaml';

        $config = (new ConfigLoader($defaultsPath))->load(null);

        self::assertTrue($config->symfonyEventSubscribersEnabled());
        self::assertTrue($config->symfonyWorkflowsEnabled());
        self::assertTrue($config->symfonyRoutesEnabled());
        self::assertTrue($config->symfonyServicesEnabled());
        self::assertTrue($config->symfonyEntitiesEnabled());
        self::assertTrue($config->symfonyCommandsEnabled());
    }
}
