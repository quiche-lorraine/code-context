<?php

declare(strict_types=1);

namespace CodeContext\Extractor;

use CodeContext\Config\Config;
use CodeContext\Kernel\ProjectContext;
use CodeContext\Model\Context;

final class ComposerExtractor implements ExtractorInterface
{
    public function name(): string
    {
        return 'composer';
    }

    public function supports(ProjectContext $project, Config $config, Context $context): bool
    {
        return $config->extractorComposerEnabled() && is_file($project->rootDir . '/composer.json');
    }

    public function extract(ProjectContext $project, Config $config, Context $context): void
    {
        $path = $project->rootDir . '/composer.json';
        $raw = file_get_contents($path);
        if (false === $raw) {
            return;
        }

        /** @var array<string, mixed>|null $json */
        $json = json_decode($raw, true);
        if (!\is_array($json)) {
            return;
        }

        $context->composer = [
            'name' => $json['name'] ?? null,
            'type' => $json['type'] ?? null,
            'description' => $json['description'] ?? null,
            'require' => \is_array($json['require'] ?? null) ? $json['require'] : [],
            'require_dev' => \is_array($json['require-dev'] ?? null) ? $json['require-dev'] : [],
            'autoload' => \is_array($json['autoload'] ?? null) ? $json['autoload'] : [],
        ];
    }
}
