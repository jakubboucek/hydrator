<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\BaseStruct;
use JakubBoucek\Hydrator\CustomValue;
use JakubBoucek\Hydrator\Entity;
use JakubBoucek\Hydrator\Exception\ExtractionException;
use JakubBoucek\Hydrator\Exception\HydrationException;
use JakubBoucek\Hydrator\Exception\MetadataException;
use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\IntValue;
use JakubBoucek\Hydrator\NativeType;
use JakubBoucek\Hydrator\Tests\Fixtures\MoneyValue;
use JakubBoucek\Hydrator\Tests\Fixtures\ThirdParty\Ulid;
use JakubBoucek\Hydrator\Tests\Fixtures\UlidAdapter;
use JakubBoucek\Hydrator\Tests\Fixtures\Wallet;
use JakubBoucek\Hydrator\TypeAdapter;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

function walletRow(): array
{
    return [
        'id' => '1',
        'balance' => '1990',
        'bonus' => null,
        'public_id' => '01arz3ndektsv4rrffq69g5fav',
        'token' => 'tok_secret',
        'active' => '1',
    ];
}

function walletHydrator(array $adapters): Hydrator
{
    return new Hydrator(Wallet::class, Mysql::class, adapters: $adapters);
}

test('custom values and adapters hydrate through the native intermediate', function (): void {
    $hydrator = walletHydrator([UlidAdapter::class]);
    $wallet = $hydrator->fromData(walletRow());

    Assert::type(MoneyValue::class, $wallet->balance);
    Assert::same(1990, $wallet->balance->cents);   // '1990' cast to int first
    Assert::same('19.90', $wallet->balance->format());
    Assert::null($wallet->bonus);                  // NULL field = plain null
    Assert::type(Ulid::class, $wallet->publicId);
    Assert::same('01arz3ndektsv4rrffq69g5fav', $wallet->publicId->toString());
    Assert::same('tok_secret', $wallet->token->secret);
    Assert::true($wallet->active->on);             // '1' through importBool

    $data = $hydrator->toData($wallet);
    Assert::same(1990, $data['balance']);
    Assert::null($data['bonus']);
    Assert::same('01arz3ndektsv4rrffq69g5fav', $data['public_id']);
    Assert::same(1, $data['active']);              // bool through Mysql exportBool
});

test('inner nullness: exported as NULL, collapses to plain null on rehydration', function (): void {
    $hydrator = walletHydrator([UlidAdapter::class]);
    $wallet = $hydrator->fromData(walletRow());

    $wallet->token->secret = null;                 // object exists, inner value gone
    $data = $hydrator->toData($wallet);
    Assert::null($data['token']);

    $reloaded = $hydrator->fromData(['token' => null] + walletRow());
    Assert::null($reloaded->token);                // collapsed: no object anymore
});

test('a registered instance is the configured one and wins over the class-string', function (): void {
    $configured = new UlidAdapter(uppercase: true);

    $hydrator = walletHydrator([UlidAdapter::class, $configured]);
    $wallet = $hydrator->fromData(walletRow());

    Assert::same('01ARZ3NDEKTSV4RRFFQ69G5FAV', $wallet->publicId->toString());
});

test('first-win: the earliest registered adapter declaring a class keeps it', function (): void {
    $upper = new class extends UlidAdapter {
        public function __construct()
        {
            parent::__construct(uppercase: true);
        }
    };

    // both declare Ulid; the anonymous uppercase one is registered first
    $hydrator = walletHydrator([$upper, UlidAdapter::class]);
    $wallet = $hydrator->fromData(walletRow());

    Assert::same('01ARZ3NDEKTSV4RRFFQ69G5FAV', $wallet->publicId->toString());
});

class ShadowAdapter implements TypeAdapter
{
    public static function provides(): array
    {
        return [DateTimeImmutable::class => NativeType::String];
    }

    public function import(mixed $value, string $targetClass): object
    {
        throw new LogicException('Never called.');
    }

    public function export(object $value): int|float|string|bool|null
    {
        throw new LogicException('Never called.');
    }
}

test('an adapter must not shadow a natively handled class', function (): void {
    $hydrator = new Hydrator(
        JakubBoucek\Hydrator\Tests\Fixtures\Article::class,
        Mysql::class,
        adapters: [ShadowAdapter::class],
    );

    Assert::exception(
        fn() => $hydrator->fromData([]),
        MetadataException::class,
        "~Adapter 'ShadowAdapter' claims class 'DateTimeImmutable', which the hydrator handles natively~",
    );
});

class MarkerOnlyValue implements CustomValue
{
    public function toNative(): int|float|string|bool|null
    {
        return null;
    }
}

class MarkerOnlyEntity implements Entity
{
    public MarkerOnlyValue $value;
}

class StructAndValue extends BaseStruct implements IntValue
{
    public static function fromNative(int $value): static
    {
        return new static();
    }

    public function toNative(): ?int
    {
        return null;
    }
}

class StructAndValueEntity implements Entity
{
    public StructAndValue $value;
}

test('metadata errors: marker-only implementation and Struct/CustomValue ambiguity', function (): void {
    Assert::exception(
        fn() => new Hydrator(MarkerOnlyEntity::class, Mysql::class)->fromData([]),
        MetadataException::class,
        '~implements CustomValue directly — implement exactly one typed interface~',
    );
    Assert::exception(
        fn() => new Hydrator(StructAndValueEntity::class, Mysql::class)->fromData([]),
        MetadataException::class,
        '~implements both Struct and CustomValue~',
    );
});

class BadReturnAdapter implements TypeAdapter
{
    public static function provides(): array
    {
        return [Ulid::class => NativeType::String];
    }

    public function import(mixed $value, string $targetClass): object
    {
        return new stdClass();      // wrong class
    }

    public function export(object $value): int|float|string|bool|null
    {
        return 123;                 // wrong native type (declared String)
    }
}

test('a misbehaving adapter fails loudly on both directions', function (): void {
    $hydrator = walletHydrator([BadReturnAdapter::class]);

    Assert::exception(
        fn() => $hydrator->fromData(walletRow()),
        HydrationException::class,
        "~Adapter 'BadReturnAdapter' returned stdClass instead of~",
    );

    $good = walletHydrator([UlidAdapter::class]);
    $wallet = $good->fromData(walletRow());
    $bad = walletHydrator([BadReturnAdapter::class]);

    Assert::exception(
        fn() => $bad->toData($wallet),
        ExtractionException::class,
        "~Adapter 'BadReturnAdapter' must return string, got int~",
    );
});

test('registering two different instances of one adapter class is ambiguous', function (): void {
    $hydrator = walletHydrator([new UlidAdapter(), new UlidAdapter(uppercase: true)]);

    Assert::exception(
        fn() => $hydrator->fromData(walletRow()),
        MetadataException::class,
        '~Two different instances of adapter~',
    );
});
