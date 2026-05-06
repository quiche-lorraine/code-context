<?php

declare(strict_types=1);

namespace CodeContext\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'init', description: 'Create a starter code-context.yaml in the target directory.')]
final class InitCommand extends Command
{
    private const DEFAULT_OUTPUT_FILE = 'code-context.yaml';
    private const DEFAULTS_RELATIVE = '/../../config/default.yaml';

    protected function configure(): void
    {
        $this
            ->addOption('cwd', null, InputOption::VALUE_REQUIRED, 'Target directory (defaults to current working directory).')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Output config file path (default: ./code-context.yaml).')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite the target file if it already exists.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $cwd = $this->resolveCwd($input);
        $target = $this->resolveTargetPath($input, $cwd);
        $defaults = realpath(__DIR__ . self::DEFAULTS_RELATIVE);

        if (false === $defaults || !is_file($defaults)) {
            $io->error('Bundled default configuration file is missing.');

            return Command::FAILURE;
        }

        if (is_file($target) && !$input->getOption('force')) {
            $io->warning(sprintf('Config file already exists: %s', $target));
            $io->text('Use --force to overwrite.');

            return Command::INVALID;
        }

        $contents = file_get_contents($defaults);
        if (false === $contents) {
            $io->error('Failed to read bundled default configuration.');

            return Command::FAILURE;
        }

        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
            $io->error(sprintf('Unable to create target directory for %s', $target));

            return Command::FAILURE;
        }

        file_put_contents($target, $contents);

        $io->success('Configuration file created.');
        $io->listing([$target]);

        return Command::SUCCESS;
    }

    private function resolveCwd(InputInterface $input): string
    {
        $value = $input->getOption('cwd');
        if (\is_string($value) && '' !== $value) {
            $real = realpath($value);

            return false !== $real ? $real : $value;
        }

        $cwd = getcwd();

        return false === $cwd ? __DIR__ : $cwd;
    }

    private function resolveTargetPath(InputInterface $input, string $cwd): string
    {
        $explicit = $input->getOption('config');
        if (\is_string($explicit) && '' !== $explicit) {
            if (str_starts_with($explicit, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $explicit) === 1) {
                return $explicit;
            }

            return $cwd . \DIRECTORY_SEPARATOR . $explicit;
        }

        return $cwd . \DIRECTORY_SEPARATOR . self::DEFAULT_OUTPUT_FILE;
    }
}
