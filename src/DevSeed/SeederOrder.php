<?php

declare(strict_types=1);

namespace App\DevSeed;

final class SeederOrder
{
    /**
     * Orders seeders so each runs after the seeders it needs. Ready seeders run in key order.
     *
     * @param iterable<DevSeederInterface> $seeders
     *
     * @return list<DevSeederInterface>
     */
    public static function sort(iterable $seeders): array
    {
        $byKey = [];
        foreach ($seeders as $seeder) {
            if (isset($byKey[$seeder->key()])) {
                throw new \LogicException(\sprintf('Two dev seeders use the key "%s".', $seeder->key()));
            }
            $byKey[$seeder->key()] = $seeder;
        }
        ksort($byKey);

        foreach ($byKey as $seeder) {
            foreach ($seeder->dependsOn() as $needed) {
                if (!isset($byKey[$needed])) {
                    throw new \LogicException(\sprintf('The dev seeder "%s" depends on the unknown key "%s".', $seeder->key(), $needed));
                }
            }
        }

        $sorted = [];
        $done = [];
        while ([] !== $byKey) {
            $next = array_find(
                $byKey,
                static fn (DevSeederInterface $seeder): bool => [] === array_diff($seeder->dependsOn(), array_keys($done)),
            ) ?? throw new \LogicException(\sprintf('The dev seeders form a dependency loop among: %s.', implode(', ', array_keys($byKey))));
            $sorted[] = $next;
            $done[$next->key()] = true;
            unset($byKey[$next->key()]);
        }

        return $sorted;
    }
}
