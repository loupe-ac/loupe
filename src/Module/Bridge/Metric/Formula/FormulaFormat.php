<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric\Formula;

use App\Module\Bridge\Metric\MetricValueType;

/** How the owner asks a formula result to be shown. Auto lets the types of the rows decide. */
enum FormulaFormat: string
{
    case Auto = 'auto';
    case Number = 'number';
    case Percent = 'percent';
    case Money = 'money';
    case Duration = 'duration';

    /** Null for Auto. Percent prints a fraction as a percentage. */
    public function valueType(): ?MetricValueType
    {
        return match ($this) {
            self::Auto => null,
            self::Number => MetricValueType::Number,
            self::Percent => MetricValueType::Fraction,
            self::Money => MetricValueType::Money,
            self::Duration => MetricValueType::Duration,
        };
    }
}
