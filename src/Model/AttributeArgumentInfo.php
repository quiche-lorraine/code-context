<?php

declare(strict_types=1);

namespace CodeContext\Model;

/**
 * A single argument of a PHP attribute.
 *
 * `value` holds the raw pretty-printed expression (quotes included), e.g. `'/blog'`,
 * `180`, `['GET', 'POST']`. `name` is the named-argument key, or null for a positional argument.
 */
final readonly class AttributeArgumentInfo
{
    public function __construct(
        public ?string $name,
        public string $value,
    ) {
    }

    /**
     * Renders the argument as it appears inside the attribute parentheses.
     */
    public function render(): string
    {
        return null !== $this->name ? $this->name . ': ' . $this->value : $this->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'value' => $this->value,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: isset($data['name']) ? (string) $data['name'] : null,
            value: (string) ($data['value'] ?? ''),
        );
    }
}
