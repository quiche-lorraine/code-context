<?php

declare(strict_types=1);

namespace CodeContext\Extractor;

use CodeContext\Config\Config;
use CodeContext\Detector\EntryPointDetector;
use CodeContext\Kernel\ProjectContext;
use CodeContext\Model\Context;

final class EntryPointExtractor implements ExtractorInterface
{
    public function __construct(private readonly EntryPointDetector $detector)
    {
    }

    public function name(): string
    {
        return 'entry_points';
    }

    public function supports(ProjectContext $project, Config $config, Context $context): bool
    {
        return true;
    }

    public function extract(ProjectContext $project, Config $config, Context $context): void
    {
        $context->entryPoints = $this->detector->detect($project->rootDir);
    }
}
