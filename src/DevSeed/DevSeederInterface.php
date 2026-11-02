<?php

declare(strict_types=1);

namespace App\DevSeed;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.dev_seeder')]
interface DevSeederInterface
{
    /** A stable name that other seeders use in dependsOn(). */
    public function key(): string;

    /** @return list<string> the keys of the seeders that must run before this one */
    public function dependsOn(): array;

    public function seed(SeedContext $context): void;
}
