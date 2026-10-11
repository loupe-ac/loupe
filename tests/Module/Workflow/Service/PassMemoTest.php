<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Workflow\Service\PassMemo;
use PHPUnit\Framework\TestCase;

final class PassMemoTest extends TestCase
{
    public function test_a_read_outside_a_pass_runs_every_time(): void
    {
        $memo = new PassMemo();
        $reads = 0;

        $memo->remember('rows', static function () use (&$reads): int {
            return ++$reads;
        });
        $memo->remember('rows', static function () use (&$reads): int {
            return ++$reads;
        });

        self::assertSame(2, $reads);
    }

    public function test_a_read_inside_a_pass_runs_once_per_key(): void
    {
        $memo = new PassMemo();
        $reads = 0;

        $results = $memo->during(static function () use ($memo, &$reads): array {
            $read = static function () use (&$reads): int {
                return ++$reads;
            };

            return [$memo->remember('a', $read), $memo->remember('a', $read), $memo->remember('b', $read)];
        });

        self::assertSame([1, 1, 2], $results);
    }

    public function test_a_null_result_counts_as_read(): void
    {
        $memo = new PassMemo();
        $reads = 0;

        $memo->during(static function () use ($memo, &$reads): void {
            $read = static function () use (&$reads): ?int {
                ++$reads;

                return null;
            };
            $memo->remember('none', $read);
            $memo->remember('none', $read);
        });

        self::assertSame(1, $reads);
    }

    public function test_a_new_pass_starts_empty(): void
    {
        $memo = new PassMemo();
        $reads = 0;
        $read = static function () use (&$reads): int {
            return ++$reads;
        };

        $first = $memo->during(static fn (): int => $memo->remember('rows', $read));
        $second = $memo->during(static fn (): int => $memo->remember('rows', $read));

        self::assertSame([1, 2], [$first, $second]);
    }

    public function test_a_pass_that_throws_is_closed(): void
    {
        $memo = new PassMemo();

        try {
            $memo->during(static function (): never {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $reads = 0;
        $memo->remember('rows', static function () use (&$reads): int {
            return ++$reads;
        });
        $memo->remember('rows', static function () use (&$reads): int {
            return ++$reads;
        });
        self::assertSame(2, $reads);
    }
}
