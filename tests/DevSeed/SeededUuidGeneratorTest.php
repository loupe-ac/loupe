<?php

declare(strict_types=1);

namespace App\Tests\DevSeed;

use App\DevSeed\DevSeedIds;
use App\DevSeed\SeededUuidGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AbstractIdGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class SeededUuidGeneratorTest extends TestCase
{
    public function test_it_returns_the_assigned_id(): void
    {
        $ids = new DevSeedIds();
        $entity = new \stdClass();
        $fixed = Uuid::v4();
        $ids->assign($entity, $fixed);

        self::assertSame($fixed, $this->generator($ids, Uuid::v4())->generateId($this->createStub(EntityManagerInterface::class), $entity));
    }

    public function test_it_asks_the_inner_generator_when_no_id_is_set(): void
    {
        $inner = Uuid::v4();

        self::assertSame($inner, $this->generator(new DevSeedIds(), $inner)->generateId($this->createStub(EntityManagerInterface::class), new \stdClass()));
    }

    private function generator(DevSeedIds $ids, Uuid $innerResult): SeededUuidGenerator
    {
        $inner = new class($innerResult) extends AbstractIdGenerator {
            public function __construct(
                private readonly Uuid $result,
            ) {
            }

            #[\Override]
            public function generateId(EntityManagerInterface $em, ?object $entity): mixed
            {
                return $this->result;
            }
        };

        return new SeededUuidGenerator($inner, $ids);
    }
}
