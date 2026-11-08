<?php

declare(strict_types=1);

namespace App\Module\Insights\Preview;

/** SVG text does not wrap, so each text gets a budget of characters. */
final class PreviewText
{
    private const string ELLIPSIS = '…';

    private const int LEGEND_LEFT = 56;

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
     * Places every legend label on at most two rows of the image footer, and cuts the names to the widest budget at which all of them fit.
     * The first row stops before the "Made with Loupe" line, and the second row spans the image.
     *
     * @param list<string> $names
     *
     * @return list<array{x: int, row: int, name: string}>
     */
    public static function legend(array $names): array
    {
        $placed = [];
        foreach ([24, 16, 12, 8, 6, 4, 3, 2, 1] as $budget) {
            $placed = self::place($names, $budget);
            if (\count($placed) === \count($names)) {
                return $placed;
            }
        }

        return $placed;
    }

    /**
     * @param list<string> $names
     *
     * @return list<array{x: int, row: int, name: string}>
     */
    private static function place(array $names, int $budget): array
    {
        $placed = [];
        $row = 0;
        $x = self::LEGEND_LEFT;
        foreach ($names as $name) {
            $fit = self::fit($name, $budget);
            $width = 14 + 10 + mb_strlen($fit) * 10 + 28;
            if ($x + $width > self::LEGEND_RIGHT[$row]) {
                ++$row;
                $x = self::LEGEND_LEFT;
                if (!isset(self::LEGEND_RIGHT[$row])) {
                    break;
                }
            }
            $placed[] = ['x' => $x, 'row' => $row, 'name' => $fit];
            $x += $width;
        }

        return $placed;
    }
}
