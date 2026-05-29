<?php

declare(strict_types=1);

namespace CodeContext\Model;

final readonly class MethodInfo
{
    /**
     * @param list<ParameterInfo> $parameters
     * @param list<AttributeInfo> $attributes
     */
    public function __construct(
        public string $name,
        public string $visibility,
        public bool $isStatic,
        public bool $isAbstract,
        public bool $isFinal,
        public ?string $returnType,
        public array $parameters,
        public array $attributes,
        public ?string $summary,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'visibility' => $this->visibility,
            'static' => $this->isStatic,
            'abstract' => $this->isAbstract,
            'final' => $this->isFinal,
            'return_type' => $this->returnType,
            'parameters' => array_map(static fn (ParameterInfo $p): array => $p->toArray(), $this->parameters),
            'attributes' => array_map(static fn (AttributeInfo $a): array => $a->toArray(), $this->attributes),
            'summary' => $this->summary,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $parameters = array_map(
            static fn (mixed $p): ParameterInfo => ParameterInfo::fromArray(\is_array($p) ? $p : []),
            (array) ($data['parameters'] ?? []),
        );

        return new self(
            name: (string) ($data['name'] ?? ''),
            visibility: (string) ($data['visibility'] ?? 'public'),
            isStatic: (bool) ($data['static'] ?? false),
            isAbstract: (bool) ($data['abstract'] ?? false),
            isFinal: (bool) ($data['final'] ?? false),
            returnType: isset($data['return_type']) ? (string) $data['return_type'] : null,
            parameters: array_values($parameters),
            attributes: AttributeInfo::listFromArray($data['attributes'] ?? []),
            summary: isset($data['summary']) ? (string) $data['summary'] : null,
        );
    }
}
