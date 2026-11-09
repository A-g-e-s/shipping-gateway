<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Tests\Common;

use Ages\ShippingGateway\Common\Data;
use PHPUnit\Framework\TestCase;

final class DataTest extends TestCase
{
    public function testReadsNestedArraysAndObjects(): void
    {
        $object = json_decode('{"a":{"b":[{"c":"x"},{"c":5}]},"n":"1.5","e":"","list":[1,{"k":1},[2],"s"]}');
        $array = json_decode('{"a":{"b":[{"c":"x"}]},"zero":0}', true);

        self::assertSame('x', Data::string($object, ['a', 'b', 0, 'c']));
        self::assertSame('5', Data::string($object, ['a', 'b', 1, 'c']));
        self::assertSame('x', Data::string($array, ['a', 'b', 0, 'c']));
        self::assertSame('def', Data::string($object, ['a', 'missing', 'c'], 'def'));
        self::assertSame('', Data::string($object, ['a', 'b']));
        self::assertSame('0', Data::string($array, 'zero'));

        self::assertNull(Data::nonEmptyString($object, 'e'));
        self::assertSame('', Data::nullableString($object, 'e'));
        self::assertNull(Data::nullableString($object, 'missing'));

        self::assertSame(1.5, Data::float($object, 'n'));
        self::assertSame(0.0, Data::float($object, 'e'));
        self::assertNull(Data::get('scalar', 'x'));
        self::assertSame($array, Data::get($array, []));

        self::assertCount(1, Data::objects($object, 'list'));
        self::assertSame([[2]], Data::arrays(json_decode('{"list":[1,{"k":1},[2]]}'), 'list'));
        self::assertSame([], Data::array($object, 'n'));
    }

    public function testDate(): void
    {
        self::assertNull(Data::date(''));
        self::assertNull(Data::date('not a date'));
        self::assertNull(Data::date(123));
        self::assertSame('2024-01-01 11:00 Europe/Prague', Data::date('2024-01-01T10:00:00Z', new \DateTimeZone('Europe/Prague'))?->format('Y-m-d H:i e'));
    }
}
