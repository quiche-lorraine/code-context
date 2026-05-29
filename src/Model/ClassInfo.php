<?php

declare(strict_types=1);

namespace CodeContext\Model;

final readonly class ClassInfo
{
    /**
     * @param list<string>        $implements
     * @param list<string>        $traits
     * @param list<AttributeInfo> $attributes
     * @param list<MethodInfo>    $methods
     * @param list<PropertyInfo> $properties
     * @param list<string>       $cases      Enum case names (populated for kind='enum')
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
        public array $cases = [],
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
            'attributes' => array_map(static fn (AttributeInfo $a): array => $a->toArray(), $this->attributes),
            'methods' => array_map(static fn (MethodInfo $m): array => $m->toArray(), $this->methods),
            'properties' => array_map(static fn (PropertyInfo $p): array => $p->toArray(), $this->properties),
            'summary' => $this->summary,
            'cases' => $this->cases,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $methods = array_map(
            static fn (mixed $m): MethodInfo => MethodInfo::fromArray(\is_array($m) ? $m : []),
            (array) ($data['methods'] ?? []),
        );
        $properties = array_map(
            static fn (mixed $p): PropertyInfo => PropertyInfo::fromArray(\is_array($p) ? $p : []),
            (array) ($data['properties'] ?? []),
        );

        return new self(
            fqcn: (string) ($data['fqcn'] ?? ''),
            shortName: (string) ($data['short_name'] ?? ''),
            namespace: (string) ($data['namespace'] ?? ''),
            kind: (string) ($data['kind'] ?? 'class'),
            file: (string) ($data['file'] ?? ''),
            isAbstract: (bool) ($data['abstract'] ?? false),
            isFinal: (bool) ($data['final'] ?? false),
            isReadonly: (bool) ($data['readonly'] ?? false),
            extends: isset($data['extends']) ? (string) $data['extends'] : null,
            implements: array_values(array_map('strval', (array) ($data['implements'] ?? []))),
            traits: array_values(array_map('strval', (array) ($data['traits'] ?? []))),
            attributes: AttributeInfo::listFromArray($data['attributes'] ?? []),
            methods: array_values($methods),
            properties: array_values($properties),
            summary: isset($data['summary']) ? (string) $data['summary'] : null,
            cases: array_values(array_map('strval', (array) ($data['cases'] ?? []))),
        );
    }
}
