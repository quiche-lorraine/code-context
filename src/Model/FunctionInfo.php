<?php

declare(strict_types=1);

namespace CodeContext\Model;

final readonly class FunctionInfo
{
    /**
     * @param list<ParameterInfo> $parameters
     * @param list<AttributeInfo> $attributes
     */
    public function __construct(
        public string $name,
        public string $namespace,
        public string $file,
        public ?string $returnType,
        public array $parameters,
        public array $attributes,
        public ?string $summary,
    ) {
    }

    public function fqn(): string
    {
        return '' !== $this->namespace ? $this->namespace . '\\' . $this->name : $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'fqn' => $this->fqn(),
            'namespace' => $this->namespace,
            'file' => $this->file,
            'return_type' => $this->returnType,
            'parameters' => array_map(static fn (ParameterInfo $p): array => $p->toArray(), $this->parameters),
            'attributes' => array_map(static fn (AttributeInfo $a): array => $a->toArray(), $this->attributes),
            'summary' => $this->summary,
        ];
    }
}
