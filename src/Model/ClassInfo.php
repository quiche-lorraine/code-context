<?php

declare(strict_types=1);

namespace CodeContext\Model;

final readonly class ClassInfo
{
    /**
     * @param list<string>       $implements
     * @param list<string>       $traits
     * @param list<string>       $attributes
     * @param list<MethodInfo>   $methods
     * @param list<PropertyInfo> $properties
     */
    public function __construct(
        public string $fqcn,
        public string $shortName,
        public string $namespace,
        public string $kind,
        public string $file,
        public bool $isAbstract,
        public bool $isFinal,
        public bool $isReadonly,
        public ?string $extends,
        public array $implements,
        public array $traits,
        public array $attributes,
        public array $methods,
        public array $properties,
        public ?string $summary,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fqcn' => $this->fqcn,
            'short_name' => $this->shortName,
            'namespace' => $this->namespace,
            'kind' => $this->kind,
            'file' => $this->file,
            'abstract' => $this->isAbstract,
            'final' => $this->isFinal,
            'readonly' => $this->isReadonly,
            'extends' => $this->extends,
            'implements' => $this->implements,
            'traits' => $this->traits,
            'attributes' => $this->attributes,
            'methods' => array_map(static fn (MethodInfo $m): array => $m->toArray(), $this->methods),
            'properties' => array_map(static fn (PropertyInfo $p): array => $p->toArray(), $this->properties),
            'summary' => $this->summary,
        ];
    }
}
