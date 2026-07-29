<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\SnakeCaseConverter;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

test('camelCase and PascalCase convert to snake_case', function (): void {
    $converter = new SnakeCaseConverter();

    Assert::same('id', $converter->toFieldName('id'));
    Assert::same('created_at', $converter->toFieldName('createdAt'));
    Assert::same('view_count_total', $converter->toFieldName('viewCountTotal'));
    Assert::same('pascal_case', $converter->toFieldName('PascalCase'));
});
