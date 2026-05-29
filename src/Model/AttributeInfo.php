<?php

declare(strict_types=1);

namespace CodeContext\Model;

/**
 * A PHP 8 attribute stored in structured form: the attribute name (as resolved by the
 * analyzer, e.g. `Doctrine\ORM\Mapping\Column`) plus its individual arguments.
 *
 * Consumers that need a human-readable representation (Markdown output) call render(),
 * which reproduces the classic `Route('/blog', name: 'home')` form.
 */
final readonly class AttributeInfo
{
    /**
     * @param list<AttributeArgumentInfo> $arguments
     */
    public function __construct(
        public string $name,
        public array $arguments,
    ) {
    }

    /**
     * Returns the last `\`-separated segment of the attribute name (e.g. `ORM\Column` → `Column`).
     */
    public function shortName(): string
    {
        $name = trim($this->name, "\\ \t");
        $lastSlash = strrpos($name, '\\');

        return false === $lastSlash ? $name : substr($name, $lastSlash + 1);
    }

    /**
     * Reconstructs the readable attribute string, e.g. `Route('/blog', name: 'home')`.
     */
    public function render(): string
    {
        if ([] === $this->arguments) {
            return $this->name;
        }

        $args = array_map(static fn (AttributeArgumentInfo $a): string => $a->render(), $this->arguments);

        return $this->name . '(' . implode(', ', $args) . ')';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'arguments' => array_map(static fn (AttributeArgumentInfo $a): array => $a->toArray(), $this->arguments),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $arguments = array_map(
            static fn (mixed $a): AttributeArgumentInfo => AttributeArgumentInfo::fromArray(\is_array($a) ? $a : []),
            (array) ($data['arguments'] ?? []),
        );

        return new self(
            name: (string) ($data['name'] ?? ''),
            arguments: array_values($arguments),
        );
    }

    /**
     * Builds a list of attributes from the raw `attributes` JSON value.
     *
     * @param mixed $raw
     *
     * @return list<AttributeInfo>
     */
    public static function listFromArray(mixed $raw): array
    {
        return array_values(array_map(
            static fn (mixed $a): self => self::fromArray(\is_array($a) ? $a : []),
            (array) $raw,
        ));
    }
}
