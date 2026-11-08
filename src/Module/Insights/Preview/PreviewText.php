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
     * When even a three character name overflows, the names keep the shortest cut that tells them apart, and the whole legend shrinks until every label has a place.
     * The first row stops before the "Made with Loupe" line, and the second row spans the image.
     *
     * @param list<string> $names
     *
     * @return list<array{x: float, row: int, name: string, scale: float}>
     */
    public static function legend(array $names): array
    {
        $placed = [];
        foreach ([24, 16, 12, 8, 6, 4, 3] as $budget) {
            $placed = self::place($names, $budget, 1.0);
            if (\count($placed) === \count($names)) {
                return $placed;
            }
        }
        $budget = self::distinctBudget($names);
        for ($tenths = 90; $tenths >= 1; $tenths -= 2) {
            $placed = self::place($names, $budget, $tenths / 100);
            if (\count($placed) === \count($names)) {
                return $placed;
            }
        }

        return $placed;
    }

    /**
     * The shortest budget, from three characters up, that keeps the cut names as distinct as the full names.
     *
     * @param list<string> $names
     */
    private static function distinctBudget(array $names): int
    {
        $longest = max(3, ...array_map(static fn (string $name): int => mb_strlen(trim($name)), $names));
        $wanted = \count(array_unique(array_map(trim(...), $names)));
        for ($budget = 3; $budget < $longest; ++$budget) {
            if (\count(array_unique(array_map(static fn (string $name): string => self::fit($name, $budget), $names))) === $wanted) {
                return $budget;
            }
        }

        return $longest;
    }

    /**
     * @param list<string> $names
     *
     * @return list<array{x: float, row: int, name: string, scale: float}>
     */
    private static function place(array $names, int $budget, float $scale): array
    {
        $placed = [];
        $row = 0;
        $x = (float) self::LEGEND_LEFT;
        foreach ($names as $name) {
            $fit = self::fit($name, $budget);
            $width = (14 + 10 + mb_strlen($fit) * 10 + 28) * $scale;
            if ($x + $width > self::LEGEND_RIGHT[$row]) {
                ++$row;
                $x = (float) self::LEGEND_LEFT;
                if (!isset(self::LEGEND_RIGHT[$row])) {
                    break;
                }
            }
            $placed[] = ['x' => $x, 'row' => $row, 'name' => $fit, 'scale' => $scale];
            $x += $width;
        }

        return $placed;
    }
}
