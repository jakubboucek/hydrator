<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Entity;
use JakubBoucek\Hydrator\Exception\HydrationException;
use JakubBoucek\Hydrator\Exception\MetadataException;
use JakubBoucek\Hydrator\Format\Json;
use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\Format\NetteDatabase;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Struct;
use JakubBoucek\Hydrator\Tests\Fixtures\ContactStruct;
use JakubBoucek\Hydrator\Tests\Fixtures\Customer;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

class BrokenStructInterface implements Entity
{
    public Struct $data;
}

test('database formats: JSON string in, JSON string out, NULL ⇄ empty struct', function (): void {
    $hydrator = new Hydrator(Customer::class, NetteDatabase::class);

    $customer = $hydrator->fromData([
        'id' => 1,
        'name' => 'Ada',
        'contact' => '{"email":"ada@example.com"}',
        'fallback' => null,
    ]);

    Assert::same('ada@example.com', $customer->contact->email);
    // NULL column hydrated into an existing, writable empty struct
    Assert::type(ContactStruct::class, $customer->fallback);
    Assert::null($customer->fallback->email);
    $customer->fallback->phone = '+420123456789'; // writable at any time

    $data = $hydrator->toData($customer);
    Assert::same('{"email":"ada@example.com"}', $data['contact']);
    Assert::same('{"phone":"+420123456789"}', $data['fallback']);

    // an emptied struct is stored as NULL, never as '{}'
    $customer->fallback->phone = null;
    Assert::null($hydrator->toData($customer)['fallback']);
});

test('Json format: nested array in, nested array out, emptiness explicit', function (): void {
    $hydrator = new Hydrator(Customer::class, Json::class);

    $customer = $hydrator->fromData([
        'id' => 1,
        'name' => 'Ada',
        'contact' => ['email' => 'ada@example.com', 'phone' => '+420111222333'],
        'fallback' => null,
    ]);

    Assert::same('+420111222333', $customer->contact->phone);
    Assert::type(ContactStruct::class, $customer->fallback);

    $data = $hydrator->toData($customer);
    Assert::same(['email' => 'ada@example.com', 'phone' => '+420111222333'], $data['contact']);
    Assert::same([], $data['fallback']); // [] in the API, unlike NULL in the database
});

test('invalid values are rejected with context', function (): void {
    $database = new Hydrator(Customer::class, Mysql::class);
    $valid = ['id' => 1, 'name' => 'Ada', 'contact' => '{}', 'fallback' => null];

    Assert::exception(
        fn() => $database->fromData(['contact' => '{broken'] + $valid),
        HydrationException::class,
        '~Cannot hydrate property .*::\\$contact.*Invalid JSON~',
    );
    Assert::exception(
        fn() => $database->fromData(['contact' => ['nested' => 'array']] + $valid),
        HydrationException::class,
        '~Expected JSON string, got array~',
    );
    Assert::exception(
        fn() => new Hydrator(Customer::class, Json::class)->fromData(['contact' => '{"a":1}'] + $valid),
        HydrationException::class,
        '~Expected decoded JSON array, got string~',
    );
});

test('a struct property must be typed with a concrete implementation', function (): void {
    Assert::exception(
        fn() => new Hydrator(BrokenStructInterface::class, Mysql::class)->fromData([]),
        MetadataException::class,
        '~concrete Struct implementation~',
    );
});
