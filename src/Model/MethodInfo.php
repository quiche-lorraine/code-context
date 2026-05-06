<?php

declare(strict_types=1);

namespace CodeContext\Model;

final readonly class MethodInfo
{
    /**
     * @param list<ParameterInfo> $parameters
     * @param list<string>        $attributes
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
            'attributes' => $this->attributes,
            'summary' => $this->summary,
        ];
    }
}
