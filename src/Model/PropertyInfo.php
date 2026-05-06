<?php

declare(strict_types=1);

namespace CodeContext\Model;

final readonly class PropertyInfo
{
    /**
     * @param list<string> $attributes
     */
    public function __construct(
        public string $name,
        public string $visibility,
        public bool $isStatic,
        public bool $isReadonly,
        public ?string $type,
        public ?string $default,
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
            'readonly' => $this->isReadonly,
            'type' => $this->type,
            'default' => $this->default,
            'attributes' => $this->attributes,
            'summary' => $this->summary,
        ];
    }
}
