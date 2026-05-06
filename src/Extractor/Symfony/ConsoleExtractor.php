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
                if (!str_starts_with($attribute, 'Symfony\Component\Console\Attribute\AsCommand(')
                    && !str_starts_with($attribute, 'AsCommand(')) {
                    continue;
                }

                $commands[] = [
                    'class' => $class->fqcn,
                    'file' => $class->file,
                    'attribute' => $attribute,
                ];
            }
        }

        $context->symfony['commands'] = $commands;
    }
}
