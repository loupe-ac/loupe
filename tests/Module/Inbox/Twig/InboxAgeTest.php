<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Twig;

use App\Module\Inbox\Twig\InboxExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InboxAgeTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function ages(): iterable
    {
        yield 'under a minute' => ['-30 seconds', 'now'];
        yield 'minutes' => ['-5 minutes', '5m'];
        yield 'hours' => ['-11 hours', '11h'];
        yield 'days' => ['-2 days', '2d'];
        yield 'weeks' => ['-3 weeks', '3w'];
        yield 'months' => ['-70 days', '2mo'];
        yield 'years' => ['-400 days', '1y'];
    }

    #[DataProvider('ages')]
    public function test_a_row_age_is_one_number_and_one_unit_with_no_ago(string $offset, string $expected): void
    {
        $now = new \DateTimeImmutable('2026-10-13 12:00:00');

        self::assertSame($expected, InboxExtension::age($now->modify($offset), $now));
    }

    public function test_a_future_time_reads_as_now(): void
    {
        $now = new \DateTimeImmutable('2026-10-13 12:00:00');

        self::assertSame('now', InboxExtension::age($now->modify('+1 hour'), $now));
    }
}
