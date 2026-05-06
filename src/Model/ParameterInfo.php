<?php

declare(strict_types=1);

namespace CodeContext\Model;

final readonly class ParameterInfo
{
    public function __construct(
        public string $name,
        public ?string $type,
        public bool $hasDefault,
        public ?string $default,
        public bool $variadic,
        public bool $byReference,
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
        ];
    }
}
