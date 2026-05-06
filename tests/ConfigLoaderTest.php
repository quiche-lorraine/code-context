<?php

declare(strict_types=1);

namespace CodeContext\Tests;

use CodeContext\Config\ConfigLoader;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    public function testItMergesDefaultAndUserConfig(): void
    {
        $tmp = sys_get_temp_dir() . '/code-context-test-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0775, true);

        $defaults = $tmp . '/default.yaml';
        file_put_contents($defaults, <<<'YAML'
code_context:
  paths:
    include: ['src/']
    exclude: ['vendor/']
  output:
    directory: '.code-context/'
YAML);

        $user = $tmp . '/user.yaml';
        file_put_contents($user, <<<'YAML'
code_context:
  paths:
    include: ['app/']
  output:
    directory: '.ctx/'
YAML);

        $config = (new ConfigLoader($defaults))->load($user);

        self::assertSame(['app/'], $config->includePaths());
        self::assertSame(['vendor/'], $config->excludePaths());
        self::assertSame('.ctx/', $config->outputDirectory());
    }
}
