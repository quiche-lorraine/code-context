<?php

declare(strict_types=1);

namespace CodeContext\Tests\Model;

use CodeContext\Model\AttributeArgumentInfo;
use CodeContext\Model\AttributeInfo;
use PHPUnit\Framework\TestCase;

final class AttributeInfoTest extends TestCase
{
    public function testRenderWithoutArguments(): void
    {
        $attribute = new AttributeInfo('Doctrine\\ORM\\Mapping\\Entity', []);

        self::assertSame('Doctrine\\ORM\\Mapping\\Entity', $attribute->render());
        self::assertSame('Entity', $attribute->shortName());
    }

    public function testRenderWithPositionalAndNamedArguments(): void
    {
        $attribute = new AttributeInfo('Route', [
            new AttributeArgumentInfo(null, "'/blog'"),
            new AttributeArgumentInfo('name', "'home'"),
        ]);

        self::assertSame("Route('/blog', name: 'home')", $attribute->render());
        self::assertSame('Route', $attribute->shortName());
    }

    public function testToArrayFromArrayRoundTrip(): void
    {
        $attribute = new AttributeInfo('ORM\\Column', [
            new AttributeArgumentInfo('type', '"string"'),
            new AttributeArgumentInfo('length', '180'),
        ]);

        $restored = AttributeInfo::fromArray($attribute->toArray());

        self::assertSame($attribute->name, $restored->name);
        self::assertSame($attribute->render(), $restored->render());
        self::assertCount(2, $restored->arguments);
        self::assertSame('type', $restored->arguments[0]->name);
        self::assertSame('"string"', $restored->arguments[0]->value);
    }

    public function testFromArrayToleratesMissingKeys(): void
    {
        $attribute = AttributeInfo::fromArray([]);

        self::assertSame('', $attribute->name);
        self::assertSame([], $attribute->arguments);
    }

    public function testListFromArrayFiltersNonArrays(): void
    {
        $list = AttributeInfo::listFromArray([
            ['name' => 'Override', 'arguments' => []],
            'not-an-array',
        ]);

        self::assertCount(2, $list);
        self::assertSame('Override', $list[0]->name);
        self::assertSame('', $list[1]->name);
    }
}
