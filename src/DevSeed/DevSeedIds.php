<?php

declare(strict_types=1);

namespace App\DevSeed;

use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * The state is static because Doctrine keeps the id generator in its metadata,
 * and the metadata outlives a rebooted test kernel. Every instance must reach
 * the generator that the metadata holds.
 */
#[When('dev')]
#[When('test')]
final class DevSeedIds
{
    /** @var \WeakMap<object, Uuid>|null */
    private static ?\WeakMap $assigned = null;

    /** @var array<class-string, list<Uuid>> */
    private static array $queued = [];

    public function assign(object $entity, Uuid $id): void
    {
        self::assigned()[$entity] = $id;
    }

    /** @param class-string $class */
    public function queue(string $class, Uuid $id): void
    {
        self::$queued[$class][] = $id;
    }

    public function take(?object $entity): ?Uuid
    {
        if (null === $entity) {
            return null;
        }

        if (isset(self::assigned()[$entity])) {
            return self::assigned()[$entity];
        }

        $class = $entity::class;
        $id = isset(self::$queued[$class]) ? array_shift(self::$queued[$class]) : null;
        if ([] === (self::$queued[$class] ?? [])) {
            unset(self::$queued[$class]);
        }

        return $id;
    }

    /**
     * Empties the queue.
     *
     * @return list<class-string> the classes that still had a queued id
     */
    public function clearQueue(): array
    {
        $left = array_keys(self::$queued);
        self::$queued = [];

        return $left;
    }

    /** @return \WeakMap<object, Uuid> */
    private static function assigned(): \WeakMap
    {
        return self::$assigned ??= new \WeakMap();
    }
}
