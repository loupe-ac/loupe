<?php

declare(strict_types=1);

namespace App\Tests\Api\Pagination;

use App\Api\Pagination\Cursor;
use App\Api\Problem\ApiProblemException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CursorTest extends TestCase
{
    public function test_a_cursor_survives_a_round_trip(): void
    {
        $cursor = new Cursor('updatedAt', '2026-11-01T10:00:00+00:00', 'abc', ['column' => 'next', 'q' => null]);

        $decoded = Cursor::decode($cursor->encode(), 'updatedAt', ['column' => 'next']);

        self::assertSame('2026-11-01T10:00:00+00:00', $decoded->sortValue);
        self::assertSame('abc', $decoded->id);
        self::assertSame(['column' => 'next'], $decoded->filters);
    }

    public function test_a_cursor_is_url_safe(): void
    {
        $token = new Cursor('number', '???>>>', 'id~~', [])->encode();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
    }

    public function test_the_order_of_the_filters_does_not_matter(): void
    {
        $token = new Cursor('number', '5', 'x', ['a' => '1', 'b' => '2'])->encode();

        self::assertSame('5', Cursor::decode($token, 'number', ['b' => '2', 'a' => '1'])->sortValue);
    }

    public function test_a_changed_filter_is_refused(): void
    {
        $token = new Cursor('number', '5', 'x', ['column' => 'next'])->encode();

        $this->assertInvalid(static fn () => Cursor::decode($token, 'number', ['column' => 'done']));
    }

    public function test_a_changed_sort_is_refused(): void
    {
        $token = new Cursor('number', '5', 'x')->encode();

        $this->assertInvalid(static fn () => Cursor::decode($token, 'updatedAt'));
    }

    public function test_a_broken_cursor_is_refused(): void
    {
        $this->assertInvalid(static fn () => Cursor::decode('not a cursor!', 'number'));
        $this->assertInvalid(static fn () => Cursor::decode(rtrim(strtr(base64_encode('[1,2]'), '+/', '-_'), '='), 'number'));
        $this->assertInvalid(static fn () => Cursor::decode(rtrim(strtr(base64_encode('{"s":"number"}'), '+/', '-_'), '='), 'number'));
    }

    public function test_a_time_position_keeps_the_microseconds_in_utc(): void
    {
        $at = new \DateTimeImmutable('2026-11-01 12:00:00.123456', new \DateTimeZone('Europe/Paris'));

        $position = Cursor::decode(Cursor::afterTime('sampledAt', $at)->encode(), 'sampledAt')->timePosition();

        self::assertSame('2026-11-01 11:00:00.123456', $position->format('Y-m-d H:i:s.u'));
    }

    public function test_a_number_position_and_an_id_position_round_trip(): void
    {
        $id = Uuid::v7();

        self::assertSame(42, Cursor::decode(Cursor::afterNumber('seq', 42)->encode(), 'seq')->numberPosition());
        self::assertTrue($id->equals(Cursor::decode(Cursor::afterId('id', $id)->encode(), 'id')->idPosition()));
    }

    public function test_a_position_that_does_not_parse_is_refused(): void
    {
        $this->assertInvalid(static fn () => new Cursor('seq', 'abc', '')->numberPosition());
        $this->assertInvalid(static fn () => new Cursor('seq', '99999999999999999999', '')->numberPosition());
        $this->assertInvalid(static fn () => new Cursor('id', 'abc', '')->idPosition());
        $this->assertInvalid(static fn () => new Cursor('at', 'abc', '')->timePosition());
    }

    /** @param \Closure(): mixed $call */
    private function assertInvalid(\Closure $call): void
    {
        try {
            $call();
            self::fail('The cursor was accepted.');
        } catch (ApiProblemException $exception) {
            self::assertSame(400, $exception->problem->status);
            self::assertSame('invalid_cursor', $exception->problem->code);
        }
    }
}
