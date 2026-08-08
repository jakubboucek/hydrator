<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Entity;
use JakubBoucek\Hydrator\Exception\ValueException;
use JakubBoucek\Hydrator\Format\Json;
use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Struct\RawJsonStruct;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

/** Realistic subclass: maps the fields of interest, everything else stays intact. */
class WebhookTestStruct extends RawJsonStruct
{
    public function getEventName(): string
    {
        return $this->getString('event');
    }

    public function getRepositoryName(): ?string
    {
        return $this->tryGetString(['repository', 'full_name']);
    }
}

/** Test proxy: widens the protected read API to public for direct assertions. */
class RawProxyStruct extends RawJsonStruct
{
    public function hasValue(string|int|array $key): bool
    {
        return parent::hasValue($key);
    }

    public function getValue(string|int|array $key): mixed
    {
        return parent::getValue($key);
    }

    public function tryGetValue(string|int|array $key): mixed
    {
        return parent::tryGetValue($key);
    }

    public function getString(string|int|array $key): string
    {
        return parent::getString($key);
    }

    public function tryGetString(string|int|array $key): ?string
    {
        return parent::tryGetString($key);
    }

    public function getInt(string|int|array $key): int
    {
        return parent::getInt($key);
    }

    public function tryGetInt(string|int|array $key): ?int
    {
        return parent::tryGetInt($key);
    }

    public function getFloat(string|int|array $key): float
    {
        return parent::getFloat($key);
    }

    public function tryGetFloat(string|int|array $key): ?float
    {
        return parent::tryGetFloat($key);
    }

    public function getBool(string|int|array $key): bool
    {
        return parent::getBool($key);
    }

    public function tryGetBool(string|int|array $key): ?bool
    {
        return parent::tryGetBool($key);
    }

    public function getArray(string|int|array $key): array
    {
        return parent::getArray($key);
    }

    public function tryGetArray(string|int|array $key): ?array
    {
        return parent::tryGetArray($key);
    }
}

class WebhookEventEntity implements Entity
{
    public int $id;
    public WebhookTestStruct $payload;
}

test('verbatim roundtrip: the string is never re-encoded', function (): void {
    // everything a decode/encode roundtrip would destroy: key order,
    // formatted numbers, big ints beyond 2^53, escaped unicode, duplicate
    // keys, inner whitespace
    $json = '{"z": 1e5, "a": 1.0,  "big": 92233720368547758079, "e": "é", "dup": 1, "dup": 2}';

    $struct = RawProxyStruct::fromJson($json);

    Assert::same($json, $struct->toJson());
    Assert::false($struct->isEmpty());

    // reads work on the decoded view (last duplicate wins — json_decode semantics)
    Assert::same(100000.0, $struct->getFloat('z'));
    Assert::same('é', $struct->getString('e'));
    Assert::same(2, $struct->getInt('dup'));

    // reading did not disturb the stored document
    Assert::same($json, $struct->toJson());
});

test('leading whitespace before the object root is legal JSON and preserved', function (): void {
    $json = "\n\t {\"a\":1}";

    $struct = RawProxyStruct::fromJson($json);

    Assert::same($json, $struct->toJson());
    Assert::same(1, $struct->getInt('a'));
});

test('emptiness is presence of the document, not its content', function (): void {
    $empty = RawProxyStruct::fromJson(null);
    Assert::true($empty->isEmpty());
    Assert::null($empty->toJson());
    Assert::same([], $empty->toArray());

    // a present empty object is a value: preserved verbatim, never normalized to NULL
    $present = RawProxyStruct::fromJson('{}');
    Assert::false($present->isEmpty());
    Assert::same('{}', $present->toJson());
    Assert::same([], $present->toArray());

    // fromArray follows the established rule: an empty array means NULL
    $fromArray = RawProxyStruct::fromArray([]);
    Assert::true($fromArray->isEmpty());
    Assert::null($fromArray->toJson());
});

test('the empty instance is fully readable', function (): void {
    $empty = RawProxyStruct::fromJson(null);

    Assert::false($empty->hasValue('a'));
    Assert::null($empty->tryGetValue(['a', 'b']));
    Assert::null($empty->tryGetString('a'));
    Assert::exception(
        fn() => $empty->getValue('a'),
        ValueException::class,
        "~Missing field 'a' in .*RawProxyStruct~",
    );
});

test('invalid JSON and non-object roots are rejected with ValueException', function (): void {
    Assert::exception(
        fn() => RawProxyStruct::fromJson('{broken'),
        ValueException::class,
        '~Invalid JSON for .*RawProxyStruct~',
    );
    Assert::exception(
        fn() => RawProxyStruct::fromJson(''),
        ValueException::class,
        '~Invalid JSON for .*RawProxyStruct~',
    );
    Assert::exception(
        fn() => RawProxyStruct::fromJson('"scalar"'),
        ValueException::class,
        '~Expected JSON object root~',
    );
    Assert::exception(
        fn() => RawProxyStruct::fromJson('[1,2]'),
        ValueException::class,
        '~Expected JSON object root~',
    );
});

test('fromArray encodes eagerly, toArray decodes lazily, lists are rejected', function (): void {
    $struct = RawProxyStruct::fromArray(['city' => 'Železná Ruda', 'tags' => ['a', 'b']]);

    Assert::same('{"city":"Železná Ruda","tags":["a","b"]}', $struct->toJson());
    Assert::same(['city' => 'Železná Ruda', 'tags' => ['a', 'b']], $struct->toArray());

    // a list would encode as [...] and break the object-root invariant
    Assert::exception(
        fn() => RawProxyStruct::fromArray(['a', 'b']),
        ValueException::class,
        '~Expected associative array \(a JSON object\) for .*RawProxyStruct, list given~',
    );
});

test('tri-state: hasValue tells missing from explicit null', function (): void {
    $struct = RawProxyStruct::fromJson('{"present":1,"explicitNull":null,"nested":{"deep":null}}');

    Assert::true($struct->hasValue('present'));
    Assert::true($struct->hasValue('explicitNull'));
    Assert::true($struct->hasValue(['nested', 'deep']));
    Assert::false($struct->hasValue('missing'));
    Assert::false($struct->hasValue(['nested', 'missing']));
    Assert::false($struct->hasValue(['present', 'tooDeep'])); // scalar intermediate

    // tryGet*: missing and explicit null are both null
    Assert::null($struct->tryGetValue('missing'));
    Assert::null($struct->tryGetValue('explicitNull'));

    // strict get*: missing and null are distinct loud errors
    Assert::exception(
        fn() => $struct->getValue('missing'),
        ValueException::class,
        "~Missing field 'missing' in .*RawProxyStruct~",
    );
    Assert::exception(
        fn() => $struct->getValue('explicitNull'),
        ValueException::class,
        "~Field 'explicitNull' in .*RawProxyStruct is null~",
    );
});

test('array paths address nested fields, including list indexes', function (): void {
    $struct = RawProxyStruct::fromJson('{"user":{"address":{"city":"Plzeň"}},"items":[{"sku":"A1"},{"sku":"B2"}]}');

    Assert::same('Plzeň', $struct->getString(['user', 'address', 'city']));
    Assert::same('B2', $struct->getString(['items', 1, 'sku']));
    Assert::same(['city' => 'Plzeň'], $struct->getArray(['user', 'address']));

    Assert::exception(
        fn() => $struct->getString(['user', 'address', 'zip']),
        ValueException::class,
        "~Missing field 'user.address.zip' in .*RawProxyStruct~",
    );
});

test('empty path is a caller bug', function (): void {
    $struct = RawProxyStruct::fromJson('{"a":1}');

    Assert::exception(
        fn() => $struct->getValue([]),
        InvalidArgumentException::class,
        '~Field path for .*RawProxyStruct must not be empty~',
    );
});

test('typed getters: strict non-null, tryGet twins tolerate missing/null only', function (): void {
    $struct = RawProxyStruct::fromJson(
        '{"s":"text","i":42,"f":3.5,"whole":3,"frac":3.0,"b":true,"a":{"k":1},"n":null}',
    );

    Assert::same('text', $struct->getString('s'));
    Assert::same(42, $struct->getInt('i'));
    Assert::same(3.5, $struct->getFloat('f'));
    Assert::same(3.0, $struct->getFloat('whole')); // JSON has a single number type
    Assert::true($struct->getBool('b'));
    Assert::same(['k' => 1], $struct->getArray('a'));

    Assert::same('text', $struct->tryGetString('s'));
    Assert::null($struct->tryGetString('missing'));
    Assert::null($struct->tryGetInt('n'));
    Assert::same(3.0, $struct->tryGetFloat('whole'));
    Assert::null($struct->tryGetBool('missing'));
    Assert::null($struct->tryGetArray('n'));

    // getInt is strictly int: a JSON fraction is rejected, never truncated
    Assert::exception(
        fn() => $struct->getInt('frac'),
        ValueException::class,
        "~Field 'frac' in .*RawProxyStruct expected int, got float~",
    );

    // wrong type is loud even on the tolerant path
    Assert::exception(
        fn() => $struct->tryGetString('i'),
        ValueException::class,
        "~Field 'i' in .*RawProxyStruct expected string, got int~",
    );
    Assert::exception(
        fn() => $struct->getBool('s'),
        ValueException::class,
        "~Field 's' in .*RawProxyStruct expected bool, got string~",
    );
});

test('database format: the foreign document survives a hydrate-extract roundtrip untouched', function (): void {
    $hydrator = new Hydrator(WebhookEventEntity::class, Mysql::class);
    $payload = '{"event": "push", "repository": {"full_name": "jakubboucek/hydrator", "stars": 1e3}, "unmapped": [1.0, 2.50]}';

    $entity = $hydrator->fromData(['id' => 7, 'payload' => $payload]);

    Assert::same('push', $entity->payload->getEventName());
    Assert::same('jakubboucek/hydrator', $entity->payload->getRepositoryName());

    // byte-exact extraction — mapped reads did not re-encode anything
    Assert::same($payload, $hydrator->toData($entity)['payload']);

    // NULL column: empty instance in, NULL out
    $empty = $hydrator->fromData(['id' => 8, 'payload' => null]);
    Assert::true($empty->payload->isEmpty());
    Assert::null($empty->payload->getRepositoryName());
    Assert::null($hydrator->toData($empty)['payload']);
});

test('Json format: nested array in, nested array out', function (): void {
    $hydrator = new Hydrator(WebhookEventEntity::class, Json::class);

    $entity = $hydrator->fromData([
        'id' => 7,
        'payload' => ['event' => 'push', 'repository' => ['full_name' => 'jakubboucek/hydrator']],
    ]);

    Assert::same('push', $entity->payload->getEventName());
    Assert::same(
        ['event' => 'push', 'repository' => ['full_name' => 'jakubboucek/hydrator']],
        $hydrator->toData($entity)['payload'],
    );
});
