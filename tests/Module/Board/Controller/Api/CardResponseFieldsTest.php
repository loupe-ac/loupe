<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller\Api;

use App\Module\Board\Controller\Api\CardResponse;
use PHPUnit\Framework\TestCase;

final class CardResponseFieldsTest extends TestCase
{
    public function test_the_field_names_are_the_properties_of_the_card_in_order(): void
    {
        $properties = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            new \ReflectionMethod(CardResponse::class, '__construct')->getParameters(),
        );

        self::assertSame($properties, CardResponse::FIELDS);
    }
}
