<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Preview;

use App\Module\Insights\Preview\PreviewText;
use PHPUnit\Framework\TestCase;

final class PreviewTextTest extends TestCase
{
    public function test_a_text_within_the_budget_stays(): void
    {
        self::assertSame('claude-opus', PreviewText::fit('claude-opus', 11));
        self::assertSame('', PreviewText::fit('', 5));
    }

    public function test_a_longer_text_is_cut_and_ends_with_an_ellipsis_inside_the_budget(): void
    {
        $fit = PreviewText::fit('claude-sonnet-long-name', 10);

        self::assertSame('claude-so…', $fit);
        self::assertSame(10, mb_strlen($fit));
    }

    public function test_it_counts_characters_not_bytes_and_drops_the_space_before_the_ellipsis(): void
    {
        self::assertSame('日本語の名前です', PreviewText::fit('日本語の名前です', 8));
        self::assertSame('日本語の…', PreviewText::fit('日本語の名前です', 5));
        self::assertSame('ab…', PreviewText::fit('ab cdef', 4));
    }

    public function test_a_budget_below_one_gives_an_empty_text(): void
    {
        self::assertSame('', PreviewText::fit('abc', 0));
    }

    public function test_a_legend_keeps_every_label_by_cutting_the_names_until_all_fit(): void
    {
        $names = array_map(static fn (int $i): string => 'claude-model-number-'.$i, range(1, 12));

        $legend = PreviewText::legend($names);

        self::assertCount(12, $legend);
        self::assertSame([], array_filter(array_column($legend, 'row'), static fn (int $row): bool => $row > 1));
        self::assertSame('claude-model-number-1', PreviewText::legend(['claude-model-number-1'])[0]['name']);
        foreach ($legend as $key) {
            self::assertLessThanOrEqual(24, mb_strlen($key['name']));
        }
    }

    public function test_a_legend_of_more_series_than_fit_at_full_size_shrinks_and_keeps_every_label(): void
    {
        $names = array_map(static fn (int $i): string => 'Series '.$i, range(1, 80));

        $legend = PreviewText::legend($names);

        self::assertCount(80, $legend);
        self::assertSame([0, 1], array_values(array_unique(array_column($legend, 'row'))));
        self::assertLessThan(1.0, $legend[0]['scale']);
        foreach ($legend as $key) {
            self::assertLessThanOrEqual(1144, $key['x']);
        }
        self::assertCount(80, array_unique(array_column($legend, 'name')));
        foreach ($legend as $key) {
            self::assertNotSame('…', $key['name']);
        }
    }
}
