<?php

declare(strict_types=1);

namespace App\Tests\Cache\Scheduler;

use App\Cache\Scheduler\PruneAppCacheTask;
use App\Tests\Support\ScheduledTasks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

final class PruneAppCacheTaskTest extends KernelTestCase
{
    public function test_it_runs_every_hour_on_the_default_schedule(): void
    {
        self::bootKernel();

        self::assertSame(
            '23 * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[PruneAppCacheTask::class] ?? null,
        );
    }

    public function test_it_prunes_the_pool(): void
    {
        $pool = new class extends FilesystemAdapter {
            public int $pruned = 0;

            #[\Override]
            public function prune(): bool
            {
                ++$this->pruned;

                return true;
            }
        };

        (new PruneAppCacheTask($pool))();

        self::assertSame(1, $pool->pruned);
    }

    public function test_a_pool_that_cannot_prune_is_left_alone(): void
    {
        $pool = new ArrayAdapter();
        $pool->save($pool->getItem('k')->set('v'));

        (new PruneAppCacheTask($pool))();

        self::assertTrue($pool->hasItem('k'));
    }
}
