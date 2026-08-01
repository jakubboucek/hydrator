<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Struct\NoteListStruct;
use JakubBoucek\Hydrator\Struct\TagListStruct;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

test('TagListStruct: unique tags, add/remove/has, text rendering', function (): void {
    $tags = new TagListStruct();
    $tags->add('vip')->add('legacy')->add('vip'); // duplicate ignored

    Assert::same(['vip', 'legacy'], $tags->toArray());
    Assert::true($tags->has('vip'));
    Assert::same(2, count($tags));
    Assert::same('vip, legacy', $tags->toText());

    $tags->remove('vip');
    Assert::same(['legacy'], iterator_to_array($tags));
    Assert::false($tags->has('vip'));

    Assert::same('["legacy"]', $tags->toJson());
    Assert::same(['a', 'b'], TagListStruct::fromJson('["a","b"]')->toArray());

    $tags->remove('legacy');
    Assert::true($tags->isEmpty());
    Assert::null($tags->toJson()); // empty list → NULL in the database
});

test('NoteListStruct: items stay plain, dates become strings at the boundary', function (): void {
    $notes = new NoteListStruct();
    $notes
        ->add('Zaplaceno převodem', 'admin', new DateTimeImmutable('2026-07-30 10:00:00'))
        ->add('Bez data i autora')
        ->add('Jen s autorem', 'jakub');

    Assert::same(3, count($notes));
    Assert::same(
        ['text' => 'Zaplaceno převodem', 'author' => 'admin', 'date' => '2026-07-30 10:00:00'],
        $notes->toArray()[0], // no objects inside the structure
    );

    Assert::same(
        "2026-07-30 10:00:00 (admin): Zaplaceno převodem\n"
        . "Bez data i autora\n"
        . '(jakub): Jen s autorem',
        $notes->toText(),
    );

    $notes->remove(1);
    Assert::same(2, count($notes));
    Assert::same('Jen s autorem', $notes->toArray()[1]['text']);

    // JSON roundtrip keeps the list shape
    $reloaded = NoteListStruct::fromJson($notes->toJson());
    Assert::equal($notes->toArray(), $reloaded->toArray());

    $reloaded->remove(0);
    $reloaded->remove(0);
    Assert::true($reloaded->isEmpty());
    Assert::null($reloaded->toJson());
});
