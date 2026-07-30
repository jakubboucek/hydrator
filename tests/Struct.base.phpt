<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\BaseStruct;
use JakubBoucek\Hydrator\DynamicStruct;
use JakubBoucek\Hydrator\Exception\ValueException;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

class AddressTestStruct extends BaseStruct
{
    public ?string $city = null;
    public ?string $street = null;
    public ?string $zip = null;
}

test('declared-fields struct: JSON roundtrip, null filtering, emptiness', function (): void {
    $address = AddressTestStruct::fromJson('{"city":"Plzeň","street":null,"unknown":"dropped"}');

    Assert::same('Plzeň', $address->city);
    Assert::null($address->street);
    Assert::false($address->isEmpty());

    // nulls filtered, unknown keys dropped (documented lossy behavior)
    Assert::same(['city' => 'Plzeň'], $address->toArray());
    Assert::same('{"city":"Plzeň"}', $address->toJson());
});

test('empty struct: null in, null out', function (): void {
    $empty = AddressTestStruct::fromJson(null);

    Assert::true($empty->isEmpty());
    Assert::null($empty->toJson());
    Assert::same([], $empty->toArray());

    // all-null fields count as empty as well
    $allNull = AddressTestStruct::fromArray(['city' => null]);
    Assert::true($allNull->isEmpty());
    Assert::null($allNull->toJson());
});

test('invalid JSON is rejected with ValueException', function (): void {
    Assert::exception(
        fn() => AddressTestStruct::fromJson('{broken'),
        ValueException::class,
        '~Invalid JSON for .*AddressTestStruct~',
    );
    Assert::exception(
        fn() => AddressTestStruct::fromJson('"scalar"'),
        ValueException::class,
        '~Expected JSON object or array~',
    );
});

test('DynamicStruct keeps every key (lossless free-form)', function (): void {
    $meta = DynamicStruct::fromJson('{"anything":1,"nested":{"a":[1,2]},"flag":true}');

    Assert::same(1, $meta->anything);
    Assert::same(['a' => [1, 2]], $meta->nested);
    Assert::true(isset($meta->flag));
    Assert::false(isset($meta->missing));

    Assert::same(
        ['anything' => 1, 'nested' => ['a' => [1, 2]], 'flag' => true],
        $meta->toArray(),
    );
    Assert::same('{"anything":1,"nested":{"a":[1,2]},"flag":true}', $meta->toJson());
});

test('utf-8 stays unescaped in the JSON rendering', function (): void {
    $address = AddressTestStruct::fromArray(['city' => 'Železná Ruda', 'street' => 'U trati 7/9']);

    Assert::same('{"city":"Železná Ruda","street":"U trati 7/9"}', $address->toJson());
});
