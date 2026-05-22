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

        $this->add(new GenerateCommand());
        $this->add(new InitCommand());
        $this->add(new ServeCommand());
    }
}
