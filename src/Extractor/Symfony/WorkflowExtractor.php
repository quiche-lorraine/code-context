<?php

declare(strict_types=1);

namespace CodeContext\Extractor\Symfony;

use CodeContext\Config\Config;
use CodeContext\Kernel\ProjectContext;
use CodeContext\Model\Context;
use Symfony\Component\Yaml\Yaml;

/**
 * Extracts Symfony Workflow / State Machine definitions from config/packages/*.yaml.
 *
 * Looks for the `framework.workflows.<name>` section.
 */
final class WorkflowExtractor
{
    public function extract(ProjectContext $project, Config $config, Context $context): void
    {
        if (!$config->symfonyWorkflowsEnabled()) {
            return;
        }

        $configDir = $project->rootDir . '/config/packages';
        if (!is_dir($configDir)) {
            $context->symfony['workflows'] = [];

            return;
        }

        $workflows = [];
        $candidates = glob($configDir . '/*.yaml') ?: [];
        foreach ($candidates as $file) {
            $parsed = $this->parseYaml($file);
            if (null === $parsed) {
                continue;
            }
            $node = $parsed['framework']['workflows'] ?? null;
            if (!\is_array($node)) {
                continue;
            }
            foreach ($node as $name => $definition) {
                if (!\is_string($name) || !\is_array($definition)) {
                    continue;
                }
                $workflows[] = $this->normalize($name, $definition, $file, $project->rootDir);
            }
        }

        $context->symfony['workflows'] = $workflows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseYaml(string $file): ?array
    {
        try {
            $parsed = Yaml::parseFile($file);

            return \is_array($parsed) ? $parsed : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function normalize(string $name, array $definition, string $absoluteFile, string $rootDir): array
    {
        $relativeFile = ltrim(str_replace($rootDir, '', $absoluteFile), '/');

        return [
            'name' => $name,
            'file' => $relativeFile,
            'type' => $definition['type'] ?? 'workflow',
            'supports' => array_values(array_map('strval', (array) ($definition['supports'] ?? []))),
            'initial_marking' => $definition['initial_marking'] ?? null,
            'places' => $this->normalizePlaces($definition['places'] ?? []),
            'transitions' => $this->normalizeTransitions($definition['transitions'] ?? []),
        ];
    }

    /**
     * Symfony accepts places as a flat list `['draft', 'published']` or a map with metadata.
     *
     * @return list<string>
     */
    private function normalizePlaces(mixed $places): array
    {
        if (!\is_array($places)) {
            return [];
        }

        $result = [];
        foreach ($places as $key => $value) {
            if (\is_string($key)) {
                $result[] = $key;
            } elseif (\is_string($value)) {
                $result[] = $value;
            } elseif (\is_array($value) && isset($value['name']) && \is_string($value['name'])) {
                $result[] = $value['name'];
            }
        }

        return $result;
    }

    /**
     * @return list<array{name: string, from: list<string>, to: list<string>, guard: ?string}>
     */
    private function normalizeTransitions(mixed $transitions): array
    {
        if (!\is_array($transitions)) {
            return [];
        }

        $result = [];
        foreach ($transitions as $key => $value) {
            if (\is_string($key) && \is_array($value)) {
                $result[] = [
                    'name' => $key,
                    'from' => array_values(array_map('strval', (array) ($value['from'] ?? []))),
                    'to' => array_values(array_map('strval', (array) ($value['to'] ?? []))),
                    'guard' => isset($value['guard']) ? (string) $value['guard'] : null,
                ];
            } elseif (\is_array($value) && isset($value['name'])) {
                $result[] = [
                    'name' => (string) $value['name'],
                    'from' => array_values(array_map('strval', (array) ($value['from'] ?? []))),
                    'to' => array_values(array_map('strval', (array) ($value['to'] ?? []))),
                    'guard' => isset($value['guard']) ? (string) $value['guard'] : null,
                ];
            }
        }

        return $result;
    }
}
