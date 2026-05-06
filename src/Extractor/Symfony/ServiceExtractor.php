<?php

declare(strict_types=1);

namespace CodeContext\Extractor\Symfony;

use CodeContext\Config\Config;
use CodeContext\Kernel\ProjectContext;
use CodeContext\Model\Context;
use Symfony\Component\Yaml\Yaml;

final class ServiceExtractor
{
    public function extract(ProjectContext $project, Config $config, Context $context): void
    {
        if (!$config->symfonyServicesEnabled()) {
            return;
        }

        $servicesYaml = $project->rootDir . '/config/services.yaml';
        if (!is_file($servicesYaml)) {
            $context->symfony['services'] = [];

            return;
        }

        $parsed = Yaml::parseFile($servicesYaml);
        if (!\is_array($parsed)) {
            $context->symfony['services'] = [];

            return;
        }

        $servicesNode = $parsed['services'] ?? [];
        if (!\is_array($servicesNode)) {
            $context->symfony['services'] = [];

            return;
        }

        $services = [];
        foreach ($servicesNode as $id => $definition) {
            if (!\is_string($id)) {
                continue;
            }
            if (str_starts_with($id, '_')) {
                continue;
            }
            $services[] = [
                'id' => $id,
                'definition' => \is_array($definition) ? $definition : ['value' => $definition],
            ];
        }

        $context->symfony['services'] = $services;
    }
}
