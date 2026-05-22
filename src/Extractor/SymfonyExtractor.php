<?php

declare(strict_types=1);

namespace CodeContext\Extractor;

use CodeContext\Config\Config;
use CodeContext\Detector\SymfonyDetector;
use CodeContext\Extractor\Symfony\ConsoleExtractor;
use CodeContext\Extractor\Symfony\EntityExtractor;
use CodeContext\Extractor\Symfony\EventSubscriberExtractor;
use CodeContext\Extractor\Symfony\RouteExtractor;
use CodeContext\Extractor\Symfony\ServiceExtractor;
use CodeContext\Extractor\Symfony\WorkflowExtractor;
use CodeContext\Kernel\ProjectContext;
use CodeContext\Model\Context;

final class SymfonyExtractor implements ExtractorInterface
{
    public function __construct(
        private readonly SymfonyDetector $detector = new SymfonyDetector(),
        private readonly RouteExtractor $routeExtractor = new RouteExtractor(),
        private readonly ServiceExtractor $serviceExtractor = new ServiceExtractor(),
        private readonly EntityExtractor $entityExtractor = new EntityExtractor(),
        private readonly ConsoleExtractor $consoleExtractor = new ConsoleExtractor(),
        private readonly EventSubscriberExtractor $eventSubscriberExtractor = new EventSubscriberExtractor(),
        private readonly WorkflowExtractor $workflowExtractor = new WorkflowExtractor(),
    ) {
    }

    public function name(): string
    {
        return 'symfony';
    }

    public function supports(ProjectContext $project, Config $config, Context $context): bool
    {
        if (!$config->symfonyAutoDetect()) {
            return true;
        }

        $detection = $this->detector->detect($project->rootDir);

        return $detection['detected'];
    }

    public function extract(ProjectContext $project, Config $config, Context $context): void
    {
        $detection = $this->detector->detect($project->rootDir);
        $context->symfony['detected'] = $detection['detected'];
        $context->symfony['version'] = $detection['version'];

        if ($config->symfonyRoutesEnabled()) {
            $this->routeExtractor->extract($project, $config, $context);
        }
        if ($config->symfonyServicesEnabled()) {
            $this->serviceExtractor->extract($project, $config, $context);
        }
        if ($config->symfonyEntitiesEnabled()) {
            $this->entityExtractor->extract($project, $config, $context);
        }
        if ($config->symfonyCommandsEnabled()) {
            $this->consoleExtractor->extract($project, $config, $context);
        }
        if ($config->symfonyEventSubscribersEnabled()) {
            $this->eventSubscriberExtractor->extract($project, $config, $context);
        }
        if ($config->symfonyWorkflowsEnabled()) {
            $this->workflowExtractor->extract($project, $config, $context);
        }
    }
}
