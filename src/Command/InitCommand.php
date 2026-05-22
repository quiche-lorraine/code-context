<?php

declare(strict_types=1);

namespace CodeContext\Command;

use CodeContext\Mcp\McpManifestMerger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'init', description: 'Initialize agent configuration files or create a starter code-context.yaml.')]
final class InitCommand extends Command
{
    private const DEFAULT_OUTPUT_FILE = 'code-context.yaml';
    private const DEFAULTS_RELATIVE = '/../../config/default.yaml';
    private const VALID_AGENTS = ['mcp', 'claude-code', 'cursor', 'all'];

    private const MCP_SERVER_KEY = 'code-context';
    private const MCP_SERVER_CONFIG = [
        'type' => 'stdio',
        'command' => 'vendor/bin/code-context',
        'args' => ['serve', '--index=.code-context/context.json'],
    ];

    protected function configure(): void
    {
        $this
            ->addOption('cwd', null, InputOption::VALUE_REQUIRED, 'Target directory (defaults to current working directory).')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Output config file path (default: ./code-context.yaml).')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite the target file if it already exists.')
            ->addOption('agent', 'a', InputOption::VALUE_REQUIRED, sprintf('Agent target: %s.', implode(', ', self::VALID_AGENTS)))
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be written without making changes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $agent = $input->getOption('agent');

        if ($agent !== null) {
            return $this->executeAgentInit($input, $io, (string) $agent);
        }

        return $this->executeLegacyInit($input, $io);
    }

    private function executeAgentInit(InputInterface $input, SymfonyStyle $io, string $agent): int
    {
        if (!in_array($agent, self::VALID_AGENTS, true)) {
            $io->error(sprintf('Invalid --agent value "%s". Valid values: %s.', $agent, implode(', ', self::VALID_AGENTS)));

            return Command::INVALID;
        }

        $cwd = $this->resolveCwd($input);
        $dryRun = (bool) $input->getOption('dry-run');
        $agents = $agent === 'all' ? ['mcp', 'claude-code', 'cursor'] : [$agent];
        $exitCode = Command::SUCCESS;

        foreach ($agents as $a) {
            $result = match ($a) {
                'mcp' => $this->initMcp($io, $cwd, $dryRun),
                'claude-code' => $this->initClaudeCode($io, $cwd, $dryRun),
                'cursor' => $this->initCursor($io, $cwd, $dryRun),
                default => Command::SUCCESS,
            };
            if ($result !== Command::SUCCESS) {
                $exitCode = $result;
            }
        }

        return $exitCode;
    }

    private function initMcp(SymfonyStyle $io, string $cwd, bool $dryRun): int
    {
        $mcpPath = $cwd . '/.mcp.json';
        $merger = new McpManifestMerger($mcpPath);

        try {
            $result = $merger->merge(self::MCP_SERVER_KEY, self::MCP_SERVER_CONFIG);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->section('Dry run: .mcp.json');
            $io->writeln($result->prettyJson());

            return Command::SUCCESS;
        }

        if ($result->isNoOp()) {
            $io->note('.mcp.json already up to date.');

            return Command::SUCCESS;
        }

        file_put_contents($mcpPath, $result->prettyJson());
        $io->success(sprintf('MCP server "%s" registered in %s', self::MCP_SERVER_KEY, $mcpPath));

        return Command::SUCCESS;
    }

    private function initClaudeCode(SymfonyStyle $io, string $cwd, bool $dryRun): int
    {
        $resourcesDir = realpath(__DIR__ . '/../../resources/agents/claude-code');
        if (false === $resourcesDir) {
            $io->error('claude-code resources directory not found.');

            return Command::FAILURE;
        }

        $files = [
            '.claude/settings.json',
            '.claude/hooks/code-context-rebuild.sh',
        ];

        foreach ($files as $relative) {
            $source = $resourcesDir . '/' . $relative;
            $target = $cwd . '/' . $relative;

            if (!is_file($source)) {
                $io->warning(sprintf('Resource missing: %s', $source));
                continue;
            }

            if (is_file($target)) {
                $io->note(sprintf('Already exists, skipping: %s', $relative));
                continue;
            }

            if ($dryRun) {
                $io->writeln(sprintf('[dry-run] Would write: %s', $target));
                continue;
            }

            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                $io->error(sprintf('Cannot create directory: %s', $dir));

                return Command::FAILURE;
            }

            copy($source, $target);

            if (str_ends_with($target, '.sh')) {
                chmod($target, 0755);
            }

            $io->writeln(sprintf('  Written: %s', $target));
        }

        return Command::SUCCESS;
    }

    private function initCursor(SymfonyStyle $io, string $cwd, bool $dryRun): int
    {
        $source = realpath(__DIR__ . '/../../resources/agents/cursor/.cursor/rules/code-context.md');
        if (false === $source) {
            $io->warning('Cursor resource file not found.');

            return Command::SUCCESS;
        }

        $target = $cwd . '/.cursor/rules/code-context.md';

        if (is_file($target)) {
            $io->note('Cursor rules already exist, skipping.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->writeln(sprintf('[dry-run] Would write: %s', $target));

            return Command::SUCCESS;
        }

        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $io->error(sprintf('Cannot create directory: %s', $dir));

            return Command::FAILURE;
        }

        copy($source, $target);
        $io->success(sprintf('Cursor rules written to %s', $target));

        return Command::SUCCESS;
    }

    private function executeLegacyInit(InputInterface $input, SymfonyStyle $io): int
    {
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
