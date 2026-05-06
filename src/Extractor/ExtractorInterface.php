<?php

declare(strict_types=1);

namespace CodeContext\Extractor;

use CodeContext\Config\Config;
use CodeContext\Kernel\ProjectContext;
use CodeContext\Model\Context;

interface ExtractorInterface
{
    /**
     * Returns the extractor unique name for debug and ordering.
     */
    public function name(): string;

    /**
     * Whether this extractor should run for the current context/config.
     */
    public function supports(ProjectContext $project, Config $config, Context $context): bool;

    /**
     * Mutates the Context by appending extracted data.
     */
    public function extract(ProjectContext $project, Config $config, Context $context): void;
}
