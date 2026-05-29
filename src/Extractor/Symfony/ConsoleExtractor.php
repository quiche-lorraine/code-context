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
                if ('AsCommand' !== $attribute->shortName()) {
                    continue;
                }

                $name = null;
                $description = null;
                $firstPositional = null;

                foreach ($attribute->arguments as $arg) {
                    $value = self::unquote($arg->value);
                    switch ($arg->name) {
                        case 'name':
                            $name = $value;
                            break;
                        case 'description':
                            $description = $value;
                            break;
                        case null:
                            $firstPositional ??= $value;
                            break;
                    }
                }

                // The first positional argument is the command name.
                $name ??= $firstPositional;

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

    private static function unquote(string $value): string
    {
        return trim(trim($value), "'\"");
    }
}
