<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric\Formula;

/** Applies a formula tree to one value for each letter. */
final readonly class FormulaEvaluator
{
    /**
     * Null when a letter that the formula uses has no value, and when a division by zero happens.
     *
     * @param array<string, int|float|null> $values by letter
     */
    public static function evaluate(FormulaNode $node, array $values): int|float|null
    {
        $result = match (true) {
            $node instanceof NumberNode => $node->value,
            $node instanceof LetterNode => $values[$node->letter] ?? null,
            $node instanceof SignNode => self::sign($node, $values),
            $node instanceof BinaryNode => self::binary($node, $values),
            default => throw new \LogicException('Unknown formula node.'),
        };

        return \is_float($result) && !is_finite($result) ? null : $result;
    }

    /** @return list<string> the letters the formula uses, each once, in order of first use */
    public static function lettersOf(FormulaNode $node): array
    {
        return array_values(array_unique(match (true) {
            $node instanceof NumberNode => [],
            $node instanceof LetterNode => [$node->letter],
            $node instanceof SignNode => self::lettersOf($node->operand),
            $node instanceof BinaryNode => [...self::lettersOf($node->left), ...self::lettersOf($node->right)],
            default => throw new \LogicException('Unknown formula node.'),
        }));
    }

    /** @param array<string, int|float|null> $values */
    private static function sign(SignNode $node, array $values): int|float|null
    {
        $operand = self::evaluate($node->operand, $values);

        return null === $operand || !$node->negative ? $operand : -$operand;
    }

    /** @param array<string, int|float|null> $values */
    private static function binary(BinaryNode $node, array $values): int|float|null
    {
        $left = self::evaluate($node->left, $values);
        $right = self::evaluate($node->right, $values);
        if (null === $left || null === $right) {
            return null;
        }

        return match ($node->operator) {
            FormulaOperator::Add => $left + $right,
            FormulaOperator::Subtract => $left - $right,
            FormulaOperator::Multiply => $left * $right,
            FormulaOperator::Divide => 0 == $right ? null : $left / $right,
        };
    }
}
