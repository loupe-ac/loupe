<?php

declare(strict_types=1);

namespace App\Tests\DevSeed;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\SeedContext;
use App\DevSeed\SeederOrder;
use PHPUnit\Framework\TestCase;

final class SeederOrderTest extends TestCase
{
    public function test_a_seeder_runs_after_the_seeders_it_needs_and_ties_go_by_key(): void
    {
        $sorted = SeederOrder::sort([
            $this->seeder('d', ['b']),
            $this->seeder('c', []),
            $this->seeder('b', ['a']),
            $this->seeder('a', []),
        ]);

        self::assertSame(['a', 'b', 'c', 'd'], array_map(static fn (DevSeederInterface $s): string => $s->key(), $sorted));
    }

    public function test_a_needed_seeder_comes_first_even_with_a_later_key(): void
    {
        $sorted = SeederOrder::sort([$this->seeder('a', ['z']), $this->seeder('z', [])]);

        self::assertSame(['z', 'a'], array_map(static fn (DevSeederInterface $s): string => $s->key(), $sorted));
    }

    public function test_it_fails_on_a_loop(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('loop');

        SeederOrder::sort([$this->seeder('a', ['b']), $this->seeder('b', ['a'])]);
    }

    public function test_it_fails_on_an_unknown_key(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('unknown key "nope"');

        SeederOrder::sort([$this->seeder('a', ['nope'])]);
    }

    public function test_it_fails_on_a_repeated_key(): void
    {
        $this->expectException(\LogicException::class);

        SeederOrder::sort([$this->seeder('a', []), $this->seeder('a', [])]);
    }

    /** @param list<string> $needs */
    private function seeder(string $key, array $needs): DevSeederInterface
    {
        return new readonly class($key, $needs) implements DevSeederInterface {
            /** @param list<string> $needs */
            public function __construct(
                private string $key,
                private array $needs,
            ) {
            }

            #[\Override]
            public function key(): string
            {
                return $this->key;
            }

            #[\Override]
            public function dependsOn(): array
            {
                return $this->needs;
            }

            #[\Override]
            public function seed(SeedContext $context): void
            {
            }
        };
    }
}
