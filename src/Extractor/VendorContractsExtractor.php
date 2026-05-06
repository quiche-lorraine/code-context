<?php

declare(strict_types=1);

namespace CodeContext\Extractor;

use CodeContext\Analyzer\PhpAstAnalyzer;
use CodeContext\Config\Config;
use CodeContext\Kernel\ProjectContext;
use CodeContext\Model\ClassInfo;
use CodeContext\Model\Context;

/**
 * Extracts interface and abstract class contracts from vendor packages.
 *
 * Only indexes types that are actually referenced by the project code
 * (in extends/implements/constructor/property types), capped at max_classes.
 * This gives Claude visibility into inherited APIs without bloating the index.
 */
final class VendorContractsExtractor implements ExtractorInterface
{
    public function name(): string
    {
        return 'vendor_contracts';
    }

    public function supports(ProjectContext $project, Config $config, Context $context): bool
    {
        return $config->vendorContractsEnabled()
            && is_dir($project->rootDir . \DIRECTORY_SEPARATOR . 'vendor');
    }

    public function extract(ProjectContext $project, Config $config, Context $context): void
    {
        $maxClasses = $config->vendorContractsMaxClasses();

        // Collect all types referenced by the project code
        $referencedTypes = $this->collectReferencedTypes($context);
        if ([] === $referencedTypes) {
            return;
        }

        $vendorDir = $project->rootDir . \DIRECTORY_SEPARATOR . 'vendor';
        $analyzer = new PhpAstAnalyzer($config);

        $contracts = [];
        $scanned = 0;

        // Walk vendor looking for files that declare referenced types
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($vendorDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (\count($contracts) >= $maxClasses) {
                break;
            }
            if (!$file instanceof \SplFileInfo || !$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            ++$scanned;
            $absolute = $file->getPathname();
            $relative = 'vendor/' . ltrim(str_replace($vendorDir, '', $absolute), \DIRECTORY_SEPARATOR);
            $classes = $analyzer->analyze($absolute, $relative);

            foreach ($classes as $class) {
                if (\count($contracts) >= $maxClasses) {
                    break;
                }
                if (!isset($referencedTypes[$class->fqcn])) {
                    continue;
                }
                if (!$class->isAbstract && 'interface' !== $class->kind) {
                    continue;
                }
                $contracts[] = $class->toArray();
            }
        }

        if ([] !== $contracts) {
            $context->vendorContracts = [
                'scanned_vendor_files' => $scanned,
                'contracts' => $contracts,
            ];
        }
    }

    /**
     * @return array<string, true> Set of FQCNs referenced in extends/implements/constructor/property types.
     */
    private function collectReferencedTypes(Context $context): array
    {
        $types = [];

        foreach ($context->classes as $class) {
            if (null !== $class->extends && '' !== $class->extends) {
                $types[$class->extends] = true;
            }
            foreach ($class->implements as $interface) {
                $types[$interface] = true;
            }
            foreach ($class->methods as $method) {
                if ('__construct' !== $method->name) {
                    continue;
                }
                foreach ($method->parameters as $param) {
                    foreach ($this->splitTypes($param->type) as $t) {
                        $types[$t] = true;
                    }
                }
            }
            foreach ($class->properties as $prop) {
                foreach ($this->splitTypes($prop->type) as $t) {
                    $types[$t] = true;
                }
            }
        }

        // Remove types that are already indexed (project code)
        foreach ($context->classes as $class) {
            unset($types[$class->fqcn]);
        }

        return $types;
    }

    /** @return list<string> */
    private function splitTypes(?string $type): array
    {
        if (null === $type || '' === $type) {
            return [];
        }

        $parts = preg_split('/[|&]/', str_replace('?', '', $type)) ?: [];
        $result = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ('' !== $part && str_contains($part, '\\')) {
                $result[] = $part;
            }
        }

        return $result;
    }
}
