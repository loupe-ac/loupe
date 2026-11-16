<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Workflow\Service\SweepRowMemo;
use App\Observability\HandledMessage;
use App\Observability\HandledMessageContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SweepRowMemoTest extends TestCase
{
    public function test_a_sweep_message_reads_a_row_once_for_the_next_sixty_seconds(): void
    {
        $clock = new MockClock('2026-11-16 12:00:00');
        $memo = new SweepRowMemo($this->handling('card_sweep'), $clock);
        $reads = 0;
        $read = static function () use (&$reads): int {
            return ++$reads;
        };

        self::assertSame(1, $memo->remember('row', $read));
        $clock->sleep(59);
        self::assertSame(1, $memo->remember('row', $read), 'A fresh row is kept.');
        $clock->sleep(2);
        self::assertSame(2, $memo->remember('row', $read), 'A row older than sixty seconds is read again.');
        self::assertSame(2, $reads);
    }

    public function test_a_message_of_another_transport_reads_every_time_and_keeps_nothing(): void
    {
        $handled = $this->handling('card_eval');
        $memo = new SweepRowMemo($handled, new MockClock('2026-11-16 12:00:00'));
        $reads = 0;
        $read = static function () use (&$reads): int {
            return ++$reads;
        };

        self::assertSame(1, $memo->remember('row', $read));
        self::assertSame(2, $memo->remember('row', $read));

        // Guard: a later sweep message finds nothing that the other transport could have stored.
        $handled->current = new HandledMessageContext(null, 'card_sweep');
        self::assertSame(3, $memo->remember('row', $read));
    }

    public function test_a_request_with_no_message_reads_every_time(): void
    {
        $memo = new SweepRowMemo(new HandledMessage(), new MockClock('2026-11-16 12:00:00'));
        $reads = 0;
        $read = static function () use (&$reads): int {
            return ++$reads;
        };

        self::assertSame(1, $memo->remember('row', $read));
        self::assertSame(2, $memo->remember('row', $read));
    }

    public function test_a_kept_row_outlives_the_reset_of_the_handled_message(): void
    {
        $handled = $this->handling('card_sweep');
        $memo = new SweepRowMemo($handled, new MockClock('2026-11-16 12:00:00'));
        $memo->remember('row', static fn (): string => 'first');

        $handled->reset();
        $handled->current = new HandledMessageContext(null, 'card_sweep');

        self::assertSame('first', $memo->remember('row', static fn (): string => 'second'), 'The second sweep message gets the kept row.');
    }

    private function handling(string $transport): HandledMessage
    {
        $handled = new HandledMessage();
        $handled->current = new HandledMessageContext(null, $transport);

        return $handled;
    }
}
