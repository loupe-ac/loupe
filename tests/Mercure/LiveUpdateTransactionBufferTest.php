<?php

declare(strict_types=1);

namespace App\Tests\Mercure;

use App\Mercure\LiveUpdateTransactionBuffer;
use PHPUnit\Framework\TestCase;

final class LiveUpdateTransactionBufferTest extends TestCase
{
    private const array SIGNAL = ['topic' => 'https://loupe.test/a', 'data' => '{"type":"a"}'];

    public function test_it_is_closed_until_a_transaction_begins(): void
    {
        $buffer = new LiveUpdateTransactionBuffer();
        self::assertFalse($buffer->isOpen());

        $buffer->begin();
        self::assertTrue($buffer->isOpen());

        $buffer->commit();
        self::assertFalse($buffer->isOpen());
    }

    public function test_a_commit_releases_the_held_signals(): void
    {
        $buffer = new LiveUpdateTransactionBuffer();
        $buffer->begin();
        $buffer->hold(self::SIGNAL['topic'], self::SIGNAL['data']);
        self::assertSame([], $buffer->takeCommitted());

        $buffer->commit();

        self::assertSame([self::SIGNAL], $buffer->takeCommitted());
        self::assertSame([], $buffer->takeCommitted());
    }

    public function test_a_rollback_drops_the_held_signals(): void
    {
        $buffer = new LiveUpdateTransactionBuffer();
        $buffer->begin();
        $buffer->hold(self::SIGNAL['topic'], self::SIGNAL['data']);

        $buffer->rollBack();

        self::assertFalse($buffer->isOpen());
        self::assertSame([], $buffer->takeCommitted());
    }

    public function test_a_rolled_back_transaction_does_not_drop_an_earlier_commit(): void
    {
        $buffer = new LiveUpdateTransactionBuffer();
        $buffer->begin();
        $buffer->hold(self::SIGNAL['topic'], self::SIGNAL['data']);
        $buffer->commit();
        $buffer->begin();
        $buffer->hold('https://loupe.test/b', '{"type":"b"}');
        $buffer->rollBack();

        self::assertSame([self::SIGNAL], $buffer->takeCommitted());
    }

    public function test_a_commit_or_rollback_with_no_transaction_changes_nothing(): void
    {
        $buffer = new LiveUpdateTransactionBuffer();

        $buffer->commit();
        $buffer->rollBack();

        self::assertFalse($buffer->isOpen());
        $buffer->begin();
        self::assertTrue($buffer->isOpen());
    }

    public function test_a_reset_forgets_the_open_transaction_and_every_signal(): void
    {
        $buffer = new LiveUpdateTransactionBuffer();
        $buffer->begin();
        $buffer->hold(self::SIGNAL['topic'], self::SIGNAL['data']);
        $buffer->commit();
        $buffer->begin();
        $buffer->hold('https://loupe.test/b', '{"type":"b"}');

        $buffer->reset();

        self::assertFalse($buffer->isOpen());
        self::assertSame([], $buffer->takeCommitted());
        $buffer->begin();
        $buffer->commit();
        self::assertSame([], $buffer->takeCommitted());
    }
}
