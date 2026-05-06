<?php

declare(strict_types=1);

namespace CodeContext\Extractor;

use CodeContext\Config\Config;
use CodeContext\Kernel\ProjectContext;
use CodeContext\Model\Context;

final class ExtractorRegistry
{
    /**
     * @param list<ExtractorInterface> $extractors
     */
    public function __construct(private readonly array $extractors)
    {
    }

    /**
     * @return list<string> Names of executed extractors.
     */
    public function run(ProjectContext $project, Config $config, Context $context): array
    {
        $executed = [];
        foreach ($this->extractors as $extractor) {
            if (!$extractor->supports($project, $config, $context)) {
                continue;
            }

            $extractor->extract($project, $config, $context);
            $executed[] = $extractor->name();
        }

        return $executed;
    }
}
