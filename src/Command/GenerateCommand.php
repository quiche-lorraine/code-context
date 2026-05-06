<?php

declare(strict_types=1);

namespace CodeContext\Command;

use CodeContext\Analyzer\PhpAstAnalyzer;
use CodeContext\Config\Config;
use CodeContext\Config\ConfigLoader;
use CodeContext\Extractor\ComposerExtractor;
use CodeContext\Extractor\DocsExtractor;
use CodeContext\Extractor\ExtractorRegistry;
use CodeContext\Extractor\PhpStructureExtractor;
use CodeContext\Extractor\SymfonyExtractor;
use CodeContext\Kernel\ProjectContext;
use CodeContext\Model\Context;
use CodeContext\Output\OutputWriter;
use CodeContext\Renderer\JsonRenderer;
use CodeContext\Renderer\Markdown\ArchitectureMdRenderer;
use CodeContext\Renderer\Markdown\AgentsMdRenderer;
use CodeContext\Renderer\Markdown\CommandsMdRenderer;
use CodeContext\Renderer\Markdown\EntitiesMdRenderer;
use CodeContext\Renderer\Markdown\RoutesMdRenderer;
use CodeContext\Renderer\Markdown\ServicesMdRenderer;
use CodeContext\Scanner\FileScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\SplFileInfo;

#[AsCommand(name: 'generate', description: 'Analyze the project and generate the AI context files.')]
final class GenerateCommand extends Command
{
    private const string DEFAULT_CONFIG_FILE = 'code-context.yaml';
    private const string DEFAULTS_RELATIVE = '/../../config/default.yaml';

    protected function configure(): void
    {
        $this
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Path to a custom code-context.yaml (defaults to ./code-context.yaml when present).',
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Override the output directory configured in the YAML file.',
            )
            ->addOption(
                'cwd',
                null,
                InputOption::VALUE_REQUIRED,
                'Project directory to analyze (defaults to the current working directory).',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $cwd = $this->resolveCwd($input);
        $userConfigPath = $this->resolveUserConfigPath($input, $cwd);
        $defaultsPath = realpath(__DIR__ . self::DEFAULTS_RELATIVE);

        if (false === $defaultsPath) {
            $io->error('Bundled default configuration file is missing.');

            return Command::FAILURE;
        }

        $config = (new ConfigLoader($defaultsPath))->load($userConfigPath);
        $project = ProjectContext::create($cwd, $config->projectRoot());

        $io->title('code-context');
        $io->writeln(sprintf('Project root: <info>%s</info>', $project->rootDir));
        $io->writeln(sprintf('User config:  <info>%s</info>', $userConfigPath ?? '(none, defaults only)'));

        $context = $this->analyzeProject($project, $config, $io);
        $registry = new ExtractorRegistry([
            new PhpStructureExtractor(),
            new ComposerExtractor(),
            new DocsExtractor(),
            new SymfonyExtractor(),
        ]);
        $executedExtractors = $registry->run($project, $config, $context);

        $outputDir = $this->resolveOutputDir($input, $project, $config);
        $writer = new OutputWriter($outputDir);

        $jsonRenderer = new JsonRenderer($config);
        $jsonPath = $writer->write($config->jsonFile(), $jsonRenderer->render($context));

        $agentsRenderer = new AgentsMdRenderer($config);
        $agentsPath = $writer->write($config->agentsMdFile(), $agentsRenderer->render($context));
        $architecturePath = $writer->write($config->architectureMdFile(), (new ArchitectureMdRenderer())->render($context));
        $routesPath = $writer->write($config->routesMdFile(), (new RoutesMdRenderer())->render($context));
        $entitiesPath = $writer->write($config->entitiesMdFile(), (new EntitiesMdRenderer())->render($context));
        $servicesPath = $writer->write($config->servicesMdFile(), (new ServicesMdRenderer())->render($context));
        $commandsPath = $writer->write($config->commandsMdFile(), (new CommandsMdRenderer())->render($context));

        $io->success('Context generated.');
        $io->listing([
            sprintf('JSON:     %s', $jsonPath),
            sprintf('AGENTS:   %s', $agentsPath),
            sprintf('ARCH:     %s', $architecturePath),
            sprintf('ROUTES:   %s', $routesPath),
            sprintf('ENTITIES: %s', $entitiesPath),
            sprintf('SERVICES: %s', $servicesPath),
            sprintf('COMMANDS: %s', $commandsPath),
            sprintf('Classes:  %d', \count($context->classes)),
            sprintf('Extractors: %s', implode(', ', $executedExtractors)),
        ]);

        return Command::SUCCESS;
    }

    private function analyzeProject(ProjectContext $project, Config $config, SymfonyStyle $io): Context
    {
        $context = new Context(
            projectRoot: $project->rootDir,
            generatedAt: (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
        );

        $scanner = new FileScanner();
        $analyzer = new PhpAstAnalyzer($config);

        $scanned = 0;
        $characters = 0;
        foreach ($scanner->scan($project, $config->includePaths(), $config->excludePaths()) as $file) {
            ++$scanned;
            \assert($file instanceof SplFileInfo);
            $relative = $this->relativePath($project->rootDir, $file->getRealPath() ?: $file->getPathname());
            $contents = @file_get_contents($file->getPathname());
            if (false !== $contents) {
                $characters += strlen($contents);
            }
            foreach ($analyzer->analyze($file->getPathname(), $relative) as $classInfo) {
                $context->classes[] = $classInfo;
            }
        }

        usort(
            $context->classes,
            static fn ($a, $b): int => $a->fqcn <=> $b->fqcn,
        );

        $io->writeln(sprintf('Scanned %d PHP files, captured %d class-like declarations.', $scanned, \count($context->classes)));
        $context = new Context(
            projectRoot: $context->projectRoot,
            generatedAt: $context->generatedAt,
            projectCharacters: $characters,
            estimatedTokens: (int) ceil($characters / 4),
            classes: $context->classes,
        );

        return $context;
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

    private function resolveUserConfigPath(InputInterface $input, string $cwd): ?string
    {
        $explicit = $input->getOption('config');
        if (\is_string($explicit) && '' !== $explicit) {
            $real = realpath($explicit);

            return false !== $real ? $real : $explicit;
        }

        $candidate = $cwd . \DIRECTORY_SEPARATOR . self::DEFAULT_CONFIG_FILE;

        return is_file($candidate) ? $candidate : null;
    }

    private function resolveOutputDir(InputInterface $input, ProjectContext $project, Config $config): string
    {
        $override = $input->getOption('output');
        $configured = \is_string($override) && '' !== $override ? $override : $config->outputDirectory();

        return $project->absolutePath($configured);
    }

    private function relativePath(string $base, string $absolute): string
    {
        $base = rtrim($base, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;
        if (str_starts_with($absolute, $base)) {
            return substr($absolute, \strlen($base));
        }

        return $absolute;
    }
}
