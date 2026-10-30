<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

/** Which way a change went. The page paints every direction in one neutral colour. */
enum MetricChartDirection: string
{
    case Up = 'up';
    case Down = 'down';
    case Flat = 'flat';

    public static function of(float $change): self
    {
        return match (true) {
            $change > 0 => self::Up,
            $change < 0 => self::Down,
            default => self::Flat,
        };
    }

    public function arrow(): string
    {
        return match ($this) {
            self::Up => '↑',
            self::Down => '↓',
            self::Flat => '→',
        };
    }
}
