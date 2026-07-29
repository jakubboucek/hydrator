<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Exception\MetadataException;
use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\Format\NetteDatabase;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Tests\Fixtures\BrokenDateOnString;
use JakubBoucek\Hydrator\Tests\Fixtures\BrokenMutableDate;
use JakubBoucek\Hydrator\Tests\Fixtures\BrokenUnionType;
use JakubBoucek\Hydrator\Tests\Fixtures\BrokenUnknownScope;
use JakubBoucek\Hydrator\Tests\Fixtures\BrokenUnreachableName;
use JakubBoucek\Hydrator\Tests\Fixtures\NamedEntity;
use JakubBoucek\Hydrator\Tests\Fixtures\UpperSnakeFormat;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Fixtures/Broken.php';

function namedData(Hydrator $hydrator, array $fields): array
{
    $entity = new NamedEntity();
    $entity->id = 1;
    $entity->someName = 'a';
    $entity->scoped = 'b';
    $entity->family = 'c';

    return $hydrator->toData($entity);
}

test('#[Name] attribute scoping: NetteDatabase format', function (): void {
    $data = namedData(new Hydrator(NamedEntity::class, NetteDatabase::class), []);

    Assert::same(['id' => 1, 'some__name' => 'a', 'nette_col' => 'b', 'db__only' => 'c'], $data);
});

test('#[Name] attribute scoping: Mysql format is not affected by the NetteDatabase override', function (): void {
    $data = namedData(new Hydrator(NamedEntity::class, Mysql::class), []);

    Assert::same(['id' => 1, 'some__name' => 'a', 'mysql__col' => 'b', 'db__only' => 'c'], $data);
});

test('a custom format subclass inherits attribute scopes of its parents', function (): void {
    $data = namedData(new Hydrator(NamedEntity::class, UpperSnakeFormat::class), []);

    // convention fields are upper-cased, #[Name] overrides matched via Mysql ancestry
    Assert::same(['ID' => 1, 'some__name' => 'a', 'mysql__col' => 'b', 'db__only' => 'c'], $data);
});

test('field names are honored on hydration as well', function (): void {
    $hydrator = new Hydrator(NamedEntity::class, NetteDatabase::class);
    $entity = $hydrator->fromData(['id' => 2, 'some__name' => 'x', 'nette_col' => 'y', 'db__only' => 'z']);

    Assert::same('x', $entity->someName);
    Assert::same('y', $entity->scoped);
    Assert::same('z', $entity->family);
});

test('metadata errors are rejected with clear messages', function (): void {
    Assert::exception(
        fn() => new Hydrator(BrokenUnionType::class, Mysql::class)->fromData([]),
        MetadataException::class,
        '~union or intersection~',
    );
    Assert::exception(
        fn() => new Hydrator(BrokenMutableDate::class, Mysql::class)->fromData([]),
        MetadataException::class,
        '~use DateTimeImmutable~',
    );
    Assert::exception(
        fn() => new Hydrator(BrokenDateOnString::class, Mysql::class)->fromData([]),
        MetadataException::class,
        '~only allowed on DateTimeImmutable~',
    );
    Assert::exception(
        fn() => new Hydrator(BrokenUnreachableName::class, Mysql::class)->fromData([]),
        MetadataException::class,
        '~Unreachable #\[Name\]~',
    );
    Assert::exception(
        fn() => new Hydrator(BrokenUnknownScope::class, Mysql::class)->fromData([]),
        MetadataException::class,
        '~Unknown format scope~',
    );
});
