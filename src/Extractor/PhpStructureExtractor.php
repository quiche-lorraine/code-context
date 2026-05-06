<?php

declare(strict_types=1);

namespace CodeContext\Extractor;

use CodeContext\Config\Config;
use CodeContext\Kernel\ProjectContext;
use CodeContext\Model\Context;

final class PhpStructureExtractor implements ExtractorInterface
{
    private const array PRIMITIVE_TYPES = [
        'string', 'int', 'integer', 'float', 'double', 'bool', 'boolean',
        'null', 'true', 'false', 'array', 'object', 'callable', 'iterable',
        'mixed', 'void', 'never', 'resource', 'self', 'static', 'parent',
    ];

    public function name(): string
    {
        return 'php_structure';
    }

    public function supports(ProjectContext $project, Config $config, Context $context): bool
    {
        return true;
    }

    public function extract(ProjectContext $project, Config $config, Context $context): void
    {
        $kinds = [
            'class' => 0,
            'interface' => 0,
            'trait' => 0,
            'enum' => 0,
            'unknown' => 0,
        ];
        $methods = 0;
        $properties = 0;
        $namespaces = [];
        foreach ($context->classes as $class) {
            $kinds[$class->kind] = ($kinds[$class->kind] ?? 0) + 1;
            $methods += \count($class->methods);
            $properties += \count($class->properties);
            $namespaces[$class->namespace] = true;
        }
        $context->phpSummary = [
            'classes_total' => \count($context->classes),
            'namespaces_total' => \count($namespaces),
            'methods_total' => $methods,
            'properties_total' => $properties,
            'kinds' => $kinds,
        ];

        $context->graph = $this->buildGraph($context);
    }

    /**
     * Builds an inverted cross-reference graph from the already-parsed classes.
     *
     * @return array<string, array<string, list<string>>>
     */
    private function buildGraph(Context $context): array
    {
        /** @var array<string, list<string>> $implementors */
        $implementors = [];
        /** @var array<string, list<string>> $subclasses */
        $subclasses = [];
        /** @var array<string, list<string>> $typeUsages */
        $typeUsages = [];

        foreach ($context->classes as $class) {
            foreach ($class->implements as $interface) {
                $implementors[$interface][] = $class->fqcn;
            }

            if (null !== $class->extends && '' !== $class->extends) {
                $subclasses[$class->extends][] = $class->fqcn;
            }

            // Collect type references from constructor parameters
            foreach ($class->methods as $method) {
                if ('__construct' !== $method->name) {
                    continue;
                }
                foreach ($method->parameters as $param) {
                    foreach ($this->extractClassTypes($param->type) as $type) {
                        if (!\in_array($class->fqcn, $typeUsages[$type] ?? [], true)) {
                            $typeUsages[$type][] = $class->fqcn;
                        }
                    }
                }
            }

            // Collect type references from property types
            foreach ($class->properties as $prop) {
                foreach ($this->extractClassTypes($prop->type) as $type) {
                    if (!\in_array($class->fqcn, $typeUsages[$type] ?? [], true)) {
                        $typeUsages[$type][] = $class->fqcn;
                    }
                }
            }
        }

        return [
            'implementors' => $implementors,
            'subclasses' => $subclasses,
            'type_usages' => $typeUsages,
        ];
    }

    /**
     * Extracts class/interface FQCNs from a rendered type string (e.g. "?Foo\Bar|Baz\Qux").
     *
     * @return list<string>
     */
    private function extractClassTypes(?string $type): array
    {
        if (null === $type || '' === $type) {
            return [];
        }

        $parts = preg_split('/[|&]/', str_replace('?', '', $type)) ?: [];
        $result = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ('' === $part || \in_array(strtolower($part), self::PRIMITIVE_TYPES, true)) {
                continue;
            }
            if (str_contains($part, '\\') || (isset($part[0]) && ctype_upper($part[0]))) {
                $result[] = $part;
            }
        }

        return $result;
    }
}
