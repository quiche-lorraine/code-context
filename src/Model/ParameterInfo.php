<?php

declare(strict_types=1);

namespace CodeContext\Model;

final readonly class ParameterInfo
{
    /**
     * @param list<AttributeInfo> $attributes
     */
    public function __construct(
        public string $name,
        public ?string $type,
        public bool $hasDefault,
        public ?string $default,
        public bool $variadic,
        public bool $byReference,
        public array $attributes = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'has_default' => $this->hasDefault,
            'default' => $this->default,
            'variadic' => $this->variadic,
            'by_reference' => $this->byReference,
            'attributes' => array_map(static fn (AttributeInfo $a): array => $a->toArray(), $this->attributes),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            type: isset($data['type']) ? (string) $data['type'] : null,
            hasDefault: (bool) ($data['has_default'] ?? false),
            default: isset($data['default']) ? (string) $data['default'] : null,
            variadic: (bool) ($data['variadic'] ?? false),
            byReference: (bool) ($data['by_reference'] ?? false),
            attributes: AttributeInfo::listFromArray($data['attributes'] ?? []),
        );
    }
}
