<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Metric\Formula;

use App\Module\Bridge\Metric\Formula\BinaryNode;
use App\Module\Bridge\Metric\Formula\FormulaException;
use App\Module\Bridge\Metric\Formula\FormulaOperator;
use App\Module\Bridge\Metric\Formula\FormulaParser;
use App\Module\Bridge\Metric\Formula\FormulaProblem;
use App\Module\Bridge\Metric\Formula\LetterNode;
use App\Module\Bridge\Metric\Formula\NumberNode;
use App\Module\Bridge\Metric\Formula\SignNode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormulaParserTest extends TestCase
{
    public function test_a_division_of_two_letters(): void
    {
        $node = FormulaParser::parse('A / B');

        self::assertInstanceOf(BinaryNode::class, $node);
        self::assertSame(FormulaOperator::Divide, $node->operator);
        self::assertInstanceOf(LetterNode::class, $node->left);
        self::assertSame('A', $node->left->letter);
        self::assertSame('B', $node->right instanceof LetterNode ? $node->right->letter : null);
        self::assertSame(3, $node->position);
    }

    public function test_multiplication_binds_tighter_than_addition(): void
    {
        $node = FormulaParser::parse('A + B * 2');

        self::assertInstanceOf(BinaryNode::class, $node);
        self::assertSame(FormulaOperator::Add, $node->operator);
        self::assertInstanceOf(BinaryNode::class, $node->right);
        self::assertSame(FormulaOperator::Multiply, $node->right->operator);
        self::assertInstanceOf(NumberNode::class, $node->right->right);
        self::assertSame(2.0, $node->right->right->value);
    }

    public function test_parentheses_override_the_order(): void
    {
        $node = FormulaParser::parse('(A + B) * C');

        self::assertInstanceOf(BinaryNode::class, $node);
        self::assertSame(FormulaOperator::Multiply, $node->operator);
        self::assertInstanceOf(BinaryNode::class, $node->left);
        self::assertSame(FormulaOperator::Add, $node->left->operator);
    }

    public function test_subtraction_is_left_associative(): void
    {
        $node = FormulaParser::parse('A - B - C');

        self::assertInstanceOf(BinaryNode::class, $node);
        self::assertInstanceOf(BinaryNode::class, $node->left);
        self::assertInstanceOf(LetterNode::class, $node->right);
        self::assertSame('C', $node->right->letter);
    }

    public function test_a_leading_sign_and_a_decimal_number(): void
    {
        $node = FormulaParser::parse('-A * 0.5');

        self::assertInstanceOf(BinaryNode::class, $node);
        self::assertInstanceOf(SignNode::class, $node->left);
        self::assertTrue($node->left->negative);
        self::assertInstanceOf(NumberNode::class, $node->right);
        self::assertSame(0.5, $node->right->value);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function problems(): iterable
    {
        yield 'unclosed parenthesis points at the opening one' => ['A / (B', FormulaProblem::UNCLOSED_PARENTHESIS, 5];
        yield 'empty' => ['  ', FormulaProblem::EMPTY_FORMULA, 0];
        yield 'ends after an operator' => ['A /', FormulaProblem::UNEXPECTED_END, 4];
        yield 'two operators' => ['A / * B', FormulaProblem::UNEXPECTED_TOKEN, 5];
        yield 'closing parenthesis alone' => ['A )', FormulaProblem::UNEXPECTED_TOKEN, 3];
        yield 'two letters side by side' => ['AB', FormulaProblem::UNEXPECTED_TOKEN, 2];
        yield 'power operator' => ['A ** B', FormulaProblem::UNEXPECTED_TOKEN, 4];
        yield 'modulo' => ['A % B', FormulaProblem::UNEXPECTED_CHARACTER, 3];
        yield 'function call' => ['constant("X")', FormulaProblem::UNEXPECTED_CHARACTER, 1];
        yield 'property access' => ['A.foo', FormulaProblem::UNEXPECTED_CHARACTER, 2];
        yield 'comparison' => ['A == B', FormulaProblem::UNEXPECTED_CHARACTER, 3];
        yield 'number with a bare point' => ['A / 2.', FormulaProblem::INVALID_NUMBER, 5];
        yield 'lower case letter' => ['a / b', FormulaProblem::UNEXPECTED_CHARACTER, 1];
    }

    #[DataProvider('problems')]
    public function test_a_mistake_names_its_problem_and_place(string $formula, string $key, int $position): void
    {
        try {
            FormulaParser::parse($formula);
            self::fail('The formula should not parse.');
        } catch (FormulaException $exception) {
            self::assertSame($key, $exception->problem->key);
            self::assertSame($position, $exception->problem->position);
        }
    }

    public function test_a_formula_over_200_characters_is_refused(): void
    {
        $this->expectException(FormulaException::class);

        FormulaParser::parse(str_repeat('A+', 100).'A');
    }

    public function test_a_formula_of_exactly_200_characters_is_read(): void
    {
        self::assertInstanceOf(BinaryNode::class, FormulaParser::parse(str_repeat('A+', 99).'A '));
    }
}
