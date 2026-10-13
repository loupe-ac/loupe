<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Contract;

use App\Module\Workflow\Contract\ParameterValue;
use PHPUnit\Framework\TestCase;

final class ParameterValueTest extends TestCase
{
    public function test_an_optional_int_is_null_when_absent_and_the_value_when_given(): void
    {
        self::assertNull(ParameterValue::optionalInt([], 'delay'));
        self::assertNull(ParameterValue::optionalInt(['delay' => null], 'delay'));
        self::assertSame(15, ParameterValue::optionalInt(['delay' => 15], 'delay'));
    }

    public function test_an_optional_int_of_another_type_is_a_bug(): void
    {
        $this->expectException(\LogicException::class);

        ParameterValue::optionalInt(['delay' => '15'], 'delay');
    }
}
