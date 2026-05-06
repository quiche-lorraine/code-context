<?php

declare(strict_types=1);

namespace Fixture\App;

final class FooService
{
    public function compute(string $name): string
    {
        return strtoupper($name);
    }
}
