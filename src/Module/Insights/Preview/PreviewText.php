<?php

declare(strict_types=1);

namespace App\Module\Insights\Preview;

/** SVG text does not wrap, so each text gets a budget of characters. */
final class PreviewText
{
    private const string ELLIPSIS = '…';

    private const int LEGEND_LEFT = 56;

    /** The narrowest name budget that still reads well, and the width reserved for the "more" key. */
    private const int READABLE_BUDGET = 9;

    private const int MORE_WIDTH = 9;

    /** The right edge of each legend row. */
    private const array LEGEND_RIGHT = [900, 1144];

    /** Cuts the text to the budget, and ends a cut text with an ellipsis that counts in the budget. */
    public static function fit(string $text, int $budget): string
    {
        $text = trim($text);
        if ($budget < 1) {
            return '';
        }
        if (mb_strlen($text) <= $budget) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $budget - 1)).self::ELLIPSIS;
    }

    /**
     * Places the legend labels on at most two rows of the image footer, and cuts the names to the widest budget at which all of them fit.
     * When even the narrowest readable budget cannot hold every name, the legend keeps the names that fit and ends with one "more" key that counts the rest.
     * The text never shrinks, so every label stays readable.
     * The first row stops before the "Made with Loupe" line, and the second row spans the image.
     *
     * @param list<string> $names
     *
     * @return list<array{x: float, row: int, name: string, scale: float, more: int}>
     */
    public static function legend(array $names): array
    {
        foreach ([24, 16, 12, self::READABLE_BUDGET] as $budget) {
            $placed = self::place($names, $budget);
            if (\count($placed) === \count($names)) {
                return $placed;
            }
        }

        for ($keep = \count($placed); $keep >= 0; --$keep) {
            $hidden = \count($names) - $keep;
            $withMore = self::place([...\array_slice($names, 0, $keep), str_repeat('0', self::MORE_WIDTH)], self::READABLE_BUDGET);
            if (\count($withMore) === $keep + 1) {
                $withMore[$keep] = ['x' => $withMore[$keep]['x'], 'row' => $withMore[$keep]['row'], 'name' => '', 'scale' => 1.0, 'more' => $hidden];

                return $withMore;
            }
        }

        return [];
    }

    /**
     * @param list<string> $names
     *
     * @return list<array{x: float, row: int, name: string, scale: float, more: int}>
     */
    private static function place(array $names, int $budget): array
    {
        $placed = [];
        $row = 0;
        $x = (float) self::LEGEND_LEFT;
        foreach ($names as $name) {
            $fit = self::fit($name, $budget);
            $width = (float) (14 + 10 + mb_strlen($fit) * 10 + 28);
            if ($x + $width > self::LEGEND_RIGHT[$row]) {
                ++$row;
                $x = (float) self::LEGEND_LEFT;
                if (!isset(self::LEGEND_RIGHT[$row])) {
                    break;
                }
            }
            $placed[] = ['x' => $x, 'row' => $row, 'name' => $fit, 'scale' => 1.0, 'more' => 0];
            $x += $width;
        }

        return $placed;
    }
}
