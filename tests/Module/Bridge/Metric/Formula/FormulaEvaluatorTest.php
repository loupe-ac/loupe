<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Metric\Formula;

use App\Module\Bridge\Metric\Formula\FormulaEvaluator;
use App\Module\Bridge\Metric\Formula\FormulaParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormulaEvaluatorTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, int|float|null>, int|float|null}> */
    public static function formulas(): iterable
    {
        yield 'a ratio' => ['A / B', ['A' => 300, 'B' => 100], 3];
        yield 'precedence' => ['A + B * 2', ['A' => 1, 'B' => 3], 7];
        yield 'parentheses' => ['(A + B) * 2', ['A' => 1, 'B' => 3], 8];
        yield 'a percent written in the formula' => ['A / B * 100', ['A' => 1, 'B' => 4], 25.0];
        yield 'a sign' => ['-A + 5', ['A' => 2], 3];
        yield 'a division by zero has no value' => ['A / B', ['A' => 1, 'B' => 0], null];
        yield 'a division by zero in a sum has no value' => ['A + 1 / B', ['A' => 1, 'B' => 0.0], null];
        yield 'an unknown letter has no value' => ['A / B', ['A' => 1, 'B' => null], null];
        yield 'zero over a value is zero' => ['A / B', ['A' => 0, 'B' => 5], 0];
        yield 'a number alone' => ['2.5', [], 2.5];
    }

    /**
     * @param array<string, int|float|null> $values
     */
    #[DataProvider('formulas')]
    public function test_it_applies_the_formula(string $formula, array $values, int|float|null $expected): void
    {
        self::assertEquals($expected, FormulaEvaluator::evaluate(FormulaParser::parse($formula), $values));
    }

    public function test_it_lists_each_used_letter_once_in_order_of_first_use(): void
    {
        self::assertSame(['C', 'A'], FormulaEvaluator::lettersOf(FormulaParser::parse('C / (A + C) * 2')));
    }
}
