<?php

declare(strict_types=1);

namespace CodeContext\Detector;

final class SymfonyDetector
{
    /**
     * @return array{detected: bool, version: ?string}
     */
    public function detect(string $projectRoot): array
    {
        $composerPath = $projectRoot . '/composer.json';
        if (!is_file($composerPath)) {
            return ['detected' => false, 'version' => null];
        }

        $raw = file_get_contents($composerPath);
        if (false === $raw) {
            return ['detected' => false, 'version' => null];
        }

        /** @var array<string, mixed>|null $json */
        $json = json_decode($raw, true);
        if (!\is_array($json)) {
            return ['detected' => false, 'version' => null];
        }

        $require = \is_array($json['require'] ?? null) ? $json['require'] : [];
        $version = null;
        foreach (['symfony/framework-bundle', 'symfony/symfony'] as $package) {
            if (isset($require[$package]) && \is_string($require[$package])) {
                $version = $require[$package];
                break;
            }
        }

        return ['detected' => null !== $version, 'version' => $version];
    }
}
