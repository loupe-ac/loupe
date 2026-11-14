<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\View;

use App\Module\Board\View\LiveCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LiveCursorTest extends TestCase
{
    public function test_a_formatted_moment_parses_back_with_its_microseconds(): void
    {
        $moment = new \DateTimeImmutable('2026-11-14 10:20:30.123456', new \DateTimeZone('UTC'));

        $parsed = LiveCursor::parse(LiveCursor::format($moment));

        self::assertNotNull($parsed);
        self::assertSame('123456', $parsed->format('u'));
        self::assertSame($moment->getTimestamp(), $parsed->getTimestamp());
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_value_reads_as_no_cursor(?string $value): void
    {
        self::assertNull(LiveCursor::parse($value));
    }

    /** @return iterable<string, array{?string}> */
    public static function malformed(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'text' => ['yesterday'];
        yield 'date only' => ['2026-11-14'];
        yield 'rolled over' => ['2026-02-31T10:00:00.000000+00:00'];
        yield 'null byte' => ["2026-11-14T10:00:00.000000+00:00\0"];
    }
}
