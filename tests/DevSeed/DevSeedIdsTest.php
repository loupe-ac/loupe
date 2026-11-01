<?php

declare(strict_types=1);

namespace App\Tests\DevSeed;

use App\DevSeed\DevSeedIds;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DevSeedIdsTest extends TestCase
{
    protected function setUp(): void
    {
        new DevSeedIds()->clearQueue();
    }

    public function test_it_returns_the_id_assigned_to_an_entity(): void
    {
        $ids = new DevSeedIds();
        $entity = new \stdClass();
        $id = Uuid::v4();
        $ids->assign($entity, $id);

        self::assertSame($id, $ids->take($entity));
        self::assertNull($ids->take(new \stdClass()));
    }

    public function test_a_queued_id_goes_to_the_next_new_entity_of_the_class_once(): void
    {
        $ids = new DevSeedIds();
        $first = Uuid::v4();
        $second = Uuid::v4();
        $ids->queue(\stdClass::class, $first);
        $ids->queue(\stdClass::class, $second);

        self::assertSame($first, $ids->take(new \stdClass()));
        self::assertSame($second, $ids->take(new \stdClass()));
        self::assertNull($ids->take(new \stdClass()));
        self::assertSame([], $ids->clearQueue());
    }

    public function test_it_reports_and_clears_the_classes_that_still_have_a_queued_id(): void
    {
        $ids = new DevSeedIds();
        $ids->queue(\stdClass::class, Uuid::v4());

        self::assertSame([\stdClass::class], $ids->clearQueue());
        self::assertNull($ids->take(new \stdClass()));
    }

    public function test_it_ignores_a_missing_entity(): void
    {
        self::assertNull(new DevSeedIds()->take(null));
    }
}
