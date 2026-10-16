<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Service\FeedbackCardTitle;
use PHPUnit\Framework\TestCase;

final class FeedbackCardTitleTest extends TestCase
{
    public function test_a_short_note_is_the_title(): void
    {
        self::assertSame('Footer overlaps the launcher', FeedbackCardTitle::of("  Footer overlaps the launcher\n"));
    }

    public function test_line_breaks_and_runs_of_spaces_become_one_space(): void
    {
        self::assertSame('Footer overlaps the launcher. Seen at 1280px.', FeedbackCardTitle::of("Footer overlaps  the launcher.\n\n  Seen at 1280px."));
    }

    public function test_a_note_of_exactly_80_characters_has_no_ellipsis(): void
    {
        $note = str_repeat('a', 80);

        self::assertSame($note, FeedbackCardTitle::of($note));
    }

    public function test_a_long_note_is_cut_at_a_word_and_ends_with_an_ellipsis(): void
    {
        $title = FeedbackCardTitle::of(str_repeat('Footer overlaps the launcher ', 5));

        self::assertSame('Footer overlaps the launcher Footer overlaps the launcher Footer overlaps the…', $title);
        self::assertLessThanOrEqual(80, mb_strlen($title));
    }

    public function test_a_long_word_with_no_space_is_cut_hard(): void
    {
        $title = FeedbackCardTitle::of(str_repeat('é', 200));

        self::assertSame(80, mb_strlen($title));
        self::assertSame(str_repeat('é', 79).'…', $title);
    }
}
