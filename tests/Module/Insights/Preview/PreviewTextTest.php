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
}
