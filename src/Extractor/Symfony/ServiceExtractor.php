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

        $yamlServices = [];
        $servicesYaml = $project->rootDir . '/config/services.yaml';

        if (is_file($servicesYaml)) {
            $parsed = Yaml::parseFile($servicesYaml);
            if (\is_array($parsed)) {
                $servicesNode = $parsed['services'] ?? [];
                if (\is_array($servicesNode)) {
                    foreach ($servicesNode as $id => $definition) {
                        if (!\is_string($id)) {
                            continue;
                        }
                        if (str_starts_with($id, '_')) {
                            continue;
                        }
                        // Skip namespace wildcard entries (App\: with resource:)
                        if (\is_array($definition) && isset($definition['resource'])) {
                            continue;
                        }
                        $yamlServices[] = [
                            'id' => $id,
                            'definition' => \is_array($definition) ? $definition : ['value' => $definition],
                        ];
                    }
                }
            }
        }

        $yamlIds = array_column($yamlServices, 'id');
        $autowired = [];
        foreach ($context->classes as $class) {
            if ($class->isAbstract) {
                continue;
            }
            if (\in_array($class->kind, ['interface', 'trait'], true)) {
                continue;
            }
            if (\in_array($class->fqcn, $yamlIds, true)) {
                continue;
            }
            $autowired[] = [
                'fqcn' => $class->fqcn,
                'namespace' => $class->namespace,
            ];
        }

        $context->symfony['services'] = [
            'configured' => $yamlServices,
            'autowired' => $autowired,
        ];
    }
}
