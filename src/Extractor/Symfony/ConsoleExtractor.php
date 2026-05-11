<?php

declare(strict_types=1);

namespace CodeContext\Extractor\Symfony;

use CodeContext\Config\Config;
use CodeContext\Kernel\ProjectContext;
use CodeContext\Model\Context;

final class ConsoleExtractor
{
    public function extract(ProjectContext $project, Config $config, Context $context): void
    {
        if (!$config->symfonyCommandsEnabled()) {
            return;
        }

        $commands = [];
        foreach ($context->classes as $class) {
            foreach ($class->attributes as $attribute) {
                if (!str_starts_with($attribute, 'Symfony\\Component\\Console\\Attribute\\AsCommand')
                    && !str_starts_with($attribute, 'AsCommand')) {
                    continue;
                }

                $name = null;
                $description = null;

                if (preg_match('/\bname:\s*[\'"](.*?)[\'"]/', $attribute, $m)) {
                    $name = $m[1];
                } elseif (preg_match('/AsCommand\([\'"]([^\'"]+)[\'"]/', $attribute, $m)) {
                    $name = $m[1];
                }

                if (preg_match('/\bdescription:\s*[\'"](.*?)[\'"]/', $attribute, $m)) {
                    $description = $m[1];
                }

                $commands[] = [
                    'class' => $class->fqcn,
                    'file' => $class->file,
                    'name' => $name,
                    'description' => $description,
                ];
                break;
            }
        }

        $context->symfony['commands'] = $commands;
    }
}
