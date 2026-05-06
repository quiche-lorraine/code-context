<?php

declare(strict_types=1);

namespace CodeContext\Command;

use CodeContext\Index\ClassIndex;
use CodeContext\Mcp\McpServer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'serve', description: 'Start a MCP (Model Context Protocol) server over stdio backed by a context.json index.')]
final class ServeCommand extends Command
{
    private const DEFAULT_INDEX = '.code-context/context.json';

    protected function configure(): void
    {
        $this
            ->addOption(
                'index',
                'i',
                InputOption::VALUE_REQUIRED,
                sprintf('Path to context.json (defaults to %s relative to --cwd).', self::DEFAULT_INDEX),
            )
            ->addOption(
                'cwd',
                null,
                InputOption::VALUE_REQUIRED,
                'Base directory used to resolve the default index path.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $indexPath = $this->resolveIndexPath($input);

        if (!is_file($indexPath)) {
            fwrite(STDERR, sprintf(
                "code-context serve: index file not found: %s\nRun `code-context generate` first.\n",
                $indexPath,
            ));

            return Command::FAILURE;
        }

        $raw = @file_get_contents($indexPath);
        if (false === $raw) {
            fwrite(STDERR, "code-context serve: cannot read index file: {$indexPath}\n");

            return Command::FAILURE;
        }

        try {
            /** @var mixed $data */
            $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            fwrite(STDERR, 'code-context serve: invalid JSON in index: ' . $e->getMessage() . "\n");

            return Command::FAILURE;
        }

        if (!\is_array($data)) {
            fwrite(STDERR, "code-context serve: index file does not contain a JSON object.\n");

            return Command::FAILURE;
        }

        fwrite(STDERR, sprintf("code-context MCP server ready (index: %s)\n", $indexPath));

        $index = ClassIndex::fromContextArray($data);
        (new McpServer($index))->run();

        return Command::SUCCESS;
    }

    private function resolveIndexPath(InputInterface $input): string
    {
        $explicit = $input->getOption('index');
        if (\is_string($explicit) && '' !== $explicit) {
            $real = realpath($explicit);

            return false !== $real ? $real : $explicit;
        }

        $cwdOpt = $input->getOption('cwd');
        $cwd = \is_string($cwdOpt) && '' !== $cwdOpt ? $cwdOpt : (getcwd() ?: __DIR__);
        $real = realpath($cwd);
        $base = false !== $real ? $real : $cwd;

        return $base . \DIRECTORY_SEPARATOR . str_replace('/', \DIRECTORY_SEPARATOR, self::DEFAULT_INDEX);
    }
}
