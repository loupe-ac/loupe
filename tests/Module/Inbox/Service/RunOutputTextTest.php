<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Inbox\Service\RunOutputText;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class RunOutputTextTest extends TestCase
{
    #[TestWith(['STAGE RESULT: blocked: flake counter not on main [reason: needs-person]', 'flake counter not on main'])]
    #[TestWith(['STAGE RESULT: waiting https://example.test/pull/1', 'waiting https://example.test/pull/1'])]
    #[TestWith(["  Needs the API key.\nSecond line  ", "Needs the API key.\nSecond line"])]
    #[TestWith(['', ''])]
    #[TestWith(['STAGE RESULT: blocked: GitHub access failed [reason: needs-person]. Restore access.', 'GitHub access failed. Restore access.'])]
    #[TestWith(['A [reason: a-b] and [reason: c] end', 'A and end'])]
    public function test_it_removes_the_protocol_text(string $output, string $expected): void
    {
        self::assertSame($expected, RunOutputText::clean($output));
    }

    #[TestWith(['STAGE RESULT: blocked: cannot reach github.com: DNS lookup failed [reason: needs-person]. Restore the network.', 'cannot reach github.com: DNS lookup failed'])]
    #[TestWith(["First line\nSecond line", 'First line'])]
    #[TestWith(['', ''])]
    public function test_the_headline_is_the_first_sentence(string $output, string $expected): void
    {
        self::assertSame($expected, RunOutputText::headline($output));
    }

    public function test_a_long_headline_is_cut(): void
    {
        $headline = RunOutputText::headline(str_repeat('word ', 60));

        self::assertSame(120, mb_strlen($headline));
        self::assertStringEndsWith('…', $headline);
    }

    #[TestWith(['STAGE RESULT: blocked: cannot reach github.com [reason: needs-person]. Restore the network.', 'Restore the network.'])]
    #[TestWith(["First line\nSecond line", 'Second line'])]
    #[TestWith(['Only one sentence.', ''])]
    #[TestWith(['', ''])]
    public function test_the_text_after_the_headline_leaves_the_headline_out(string $output, string $expected): void
    {
        self::assertSame($expected, RunOutputText::afterHeadline($output));
    }

    public function test_the_text_after_a_cut_headline_is_all_of_it(): void
    {
        $output = str_repeat('word ', 60).'. Second sentence.';

        self::assertSame(RunOutputText::clean($output), RunOutputText::afterHeadline($output));
    }
}
