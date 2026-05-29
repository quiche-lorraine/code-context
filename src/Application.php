<?php

declare(strict_types=1);

namespace CodeContext;

use CodeContext\Command\GenerateCommand;
use CodeContext\Command\InitCommand;
use CodeContext\Command\ServeCommand;
use Symfony\Component\Console\Application as SymfonyApplication;

final class Application extends SymfonyApplication
{
    public const NAME = 'code-context';
    public const VERSION = '0.1.0';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        // addCommands() is available across Symfony Console 6.4, 7 and 8, whereas the
        // singular add() was removed in 8.0 in favour of addCommand().
        $this->addCommands([
            new GenerateCommand(),
            new InitCommand(),
            new ServeCommand(),
        ]);
    }
}
