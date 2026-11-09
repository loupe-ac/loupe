<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric\Formula;

use App\Module\Bridge\Metric\MetricValueType;

/**
 * The value type that a formula gives. A plain number has no type of its own, so it is neutral and the type is
 * null. A row on Count is Count, and any other row takes the type of its metric.
 */
final readonly class FormulaType
{
    public function __construct(
        public ?MetricValueType $type,
    ) {
    }

    /**
     * @param array<string, MetricValueType> $rowTypes the type of each row, by letter
     *
     * @throws FormulaException for a letter without a row, and for a sum or a difference of different types
     */
    public static function of(FormulaNode $node, array $rowTypes): self
    {
        return new self(self::typeOf($node, $rowTypes));
    }

    /** What Auto shows: Money and Duration stay, and every other result is a Number. */
    public function auto(): MetricValueType
    {
        return \in_array($this->type, [MetricValueType::Money, MetricValueType::Duration], true) ? $this->type : MetricValueType::Number;
    }

    /** @param array<string, MetricValueType> $rowTypes */
    private static function typeOf(FormulaNode $node, array $rowTypes): ?MetricValueType
    {
        return match (true) {
            $node instanceof NumberNode => null,
            $node instanceof LetterNode => $rowTypes[$node->letter] ?? throw new FormulaException(new FormulaProblem(FormulaProblem::UNKNOWN_LETTER, $node->position, ['%letter%' => $node->letter])),
            $node instanceof SignNode => self::typeOf($node->operand, $rowTypes),
            $node instanceof BinaryNode => self::combine($node, self::typeOf($node->left, $rowTypes), self::typeOf($node->right, $rowTypes)),
            default => throw new \LogicException('Unknown formula node.'),
        };
    }

    private static function combine(BinaryNode $node, ?MetricValueType $left, ?MetricValueType $right): ?MetricValueType
    {
        return match ($node->operator) {
            FormulaOperator::Add, FormulaOperator::Subtract => self::sum($node, $left, $right),
            FormulaOperator::Multiply => match (true) {
                null === $left || MetricValueType::Count === $left => $right ?? $left,
                null === $right || MetricValueType::Count === $right => $left,
                default => MetricValueType::Number,
            },
            FormulaOperator::Divide => match (true) {
                null !== $left && $left === $right => MetricValueType::Number,
                null === $right || MetricValueType::Count === $right => $left,
                default => MetricValueType::Number,
            },
        };
    }

    private static function sum(BinaryNode $node, ?MetricValueType $left, ?MetricValueType $right): ?MetricValueType
    {
        if (null === $left || $left === $right) {
            return $right;
        }
        if (null === $right) {
            return $left;
        }

        throw new FormulaException(new FormulaProblem(FormulaProblem::MIXED_TYPES, $node->position, ['%left%' => $left->value, '%right%' => $right->value]));
    }
}
