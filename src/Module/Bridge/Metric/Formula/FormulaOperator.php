<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric\Formula;

enum FormulaOperator: string
{
    case Add = 'add';
    case Subtract = 'subtract';
    case Multiply = 'multiply';
    case Divide = 'divide';

    public static function fromSymbol(string $symbol): self
    {
        return match ($symbol) {
            '+' => self::Add,
            '-' => self::Subtract,
            '*' => self::Multiply,
            '/' => self::Divide,
            default => throw new \InvalidArgumentException(\sprintf('"%s" is not a formula operator.', $symbol)),
        };
    }
}
