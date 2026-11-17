<?php

declare(strict_types=1);

namespace App\Tests\Mercure;

use App\Mercure\CountChangeGate;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class CountChangeGateTest extends TestCase
{
    private const string KEY = 'sidebar-counts.test';

    private TestHandler $log;

    protected function setUp(): void
    {
        $this->log = new TestHandler();
    }

    public function test_the_first_check_signals_and_an_equal_one_after_it_does_not(): void
    {
        $gate = $this->gate(new ArrayAdapter());

        self::assertTrue($gate->changed(self::KEY, static fn (): string => '3/8|5'));
        self::assertFalse($gate->changed(self::KEY, static fn (): string => '3/8|5'));
        self::assertFalse($gate->changed(self::KEY, static fn (): string => '3/8|5'));
    }

    public function test_a_change_signals_and_moves_the_version(): void
    {
        $gate = $this->gate(new ArrayAdapter());
        $gate->changed(self::KEY, static fn (): string => 'a');
        $before = $gate->storedVersion(self::KEY);

        self::assertTrue($gate->changed(self::KEY, static fn (): string => 'b'));

        self::assertNotNull($before);
        self::assertNotNull($gate->storedVersion(self::KEY));
        self::assertNotSame($before, $gate->storedVersion(self::KEY));
    }

    public function test_a_return_to_an_earlier_value_signals_again(): void
    {
        $gate = $this->gate(new ArrayAdapter());
        $gate->changed(self::KEY, static fn (): string => 'a');
        $gate->changed(self::KEY, static fn (): string => 'b');

        self::assertTrue($gate->changed(self::KEY, static fn (): string => 'a'));
    }

    public function test_no_change_keeps_the_version(): void
    {
        $gate = $this->gate(new ArrayAdapter());
        $gate->changed(self::KEY, static fn (): string => 'a');
        $before = $gate->storedVersion(self::KEY);

        $gate->changed(self::KEY, static fn (): string => 'a');

        self::assertSame($before, $gate->storedVersion(self::KEY));
    }

    public function test_a_failed_read_signals_logs_and_moves_the_version(): void
    {
        $gate = $this->gate(new ArrayAdapter());
        $gate->changed(self::KEY, static fn (): string => 'a');
        $before = $gate->storedVersion(self::KEY);

        $changed = $gate->changed(self::KEY, static fn (): string => throw new \RuntimeException('database gone'));

        self::assertTrue($changed);
        self::assertNotNull($gate->storedVersion(self::KEY));
        self::assertNotSame($before, $gate->storedVersion(self::KEY));
        self::assertTrue($this->log->hasWarningThatContains('live_updates.count_check_failed'));
        self::assertTrue($gate->changed(self::KEY, static fn (): string => 'a'), 'The next check signals again, because the failure dropped the fingerprint.');
    }

    public function test_a_failed_read_with_a_failing_write_deletes_the_version(): void
    {
        $pool = new class extends ArrayAdapter {
            public bool $refuseWrites = false;

            #[\Override]
            public function save(CacheItemInterface $item): bool
            {
                return !$this->refuseWrites && parent::save($item);
            }
        };
        $gate = $this->gate($pool);
        $gate->changed(self::KEY, static fn (): string => 'a');
        self::assertNotNull($gate->storedVersion(self::KEY));
        $pool->refuseWrites = true;

        self::assertTrue($gate->changed(self::KEY, static fn (): string => throw new \RuntimeException('database gone')));

        self::assertNull($gate->storedVersion(self::KEY));
        self::assertFalse($this->log->hasErrorThatContains('live_updates.version_not_moved'));
    }

    public function test_a_version_that_neither_moves_nor_goes_is_logged_as_an_error(): void
    {
        $pool = new class extends ArrayAdapter {
            #[\Override]
            public function save(CacheItemInterface $item): bool
            {
                return false;
            }

            #[\Override]
            public function deleteItem(mixed $key): bool
            {
                return false;
            }
        };

        self::assertTrue($this->gate($pool)->changed(self::KEY, static fn (): string => 'a'));

        self::assertTrue($this->log->hasErrorThatContains('live_updates.version_not_moved'));
    }

    public function test_a_missing_version_is_created_with_no_fingerprint_and_the_next_check_signals(): void
    {
        $gate = $this->gate(new ArrayAdapter());
        self::assertNull($gate->storedVersion(self::KEY));

        $version = $gate->currentVersion(self::KEY);

        self::assertNotNull($version);
        self::assertSame($version, $gate->currentVersion(self::KEY));
        self::assertSame($version, $gate->storedVersion(self::KEY));
        self::assertTrue($gate->changed(self::KEY, static fn (): string => 'a'));
        self::assertNotSame($version, $gate->storedVersion(self::KEY));
        self::assertFalse($gate->changed(self::KEY, static fn (): string => 'a'));
    }

    public function test_a_version_that_cannot_be_created_reads_as_null(): void
    {
        $pool = new class extends ArrayAdapter {
            #[\Override]
            public function save(CacheItemInterface $item): bool
            {
                return false;
            }
        };

        self::assertNull($this->gate($pool)->currentVersion(self::KEY));
    }

    public function test_a_replaced_version_is_new_each_time(): void
    {
        $gate = $this->gate(new ArrayAdapter());

        $gate->replaceVersion('owner');
        $first = $gate->storedVersion('owner');
        $gate->replaceVersion('owner');

        self::assertNotNull($first);
        self::assertNotSame($first, $gate->storedVersion('owner'));
    }

    private function gate(ArrayAdapter $pool): CountChangeGate
    {
        return new CountChangeGate($pool, new LockFactory(new InMemoryStore()), new Logger('test', [$this->log]));
    }
}
