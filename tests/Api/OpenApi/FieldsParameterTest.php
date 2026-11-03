<?php

declare(strict_types=1);

namespace App\Tests\Api\OpenApi;

use App\Api\OpenApi\FieldsParameter;
use PHPUnit\Framework\TestCase;

final class FieldsParameterTest extends TestCase
{
    public function test_it_declares_a_comma_list_of_the_names_and_all(): void
    {
        $spec = json_decode(new FieldsParameter(['cardId', 'title'])->toJson(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('fields', $spec['name']);
        self::assertSame('query', $spec['in']);
        self::assertSame('form', $spec['style']);
        self::assertFalse($spec['explode']);
        self::assertSame('array', $spec['schema']['type']);
        self::assertSame('string', $spec['schema']['items']['type']);
        self::assertSame(['cardId', 'title', 'all'], $spec['schema']['items']['enum']);
    }

    public function test_a_refused_name_leaves_the_enum(): void
    {
        $spec = json_decode(new FieldsParameter(['documentId', 'markdown'], refused: ['markdown', 'all'])->toJson(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(['documentId'], $spec['schema']['items']['enum']);
    }
}
