<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service\Dev;

use App\DevSeed\DevSeedIds;
use App\DevSeed\SeedContext;
use App\Module\Account\Repository\UserRepository;
use App\Module\GitHub\Service\Dev\DevInitHandoffSeeder;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Project\Service\InitInstallWaits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(DevInitHandoffSeeder::class)]
final class DevInitHandoffSeederTest extends TestCase
{
    public function test_the_seeded_mark_waits_for_the_lifecycle_project_and_never_ends(): void
    {
        $cache = new ArrayAdapter();
        $waits = new InitInstallWaits($cache);
        $project = (string) DevProjectSeeder::lifecycleProjectId();

        new DevInitHandoffSeeder($cache)->seed(new SeedContext(
            new \DateTimeImmutable(),
            new DevSeedIds(),
            $this->createStub(UserRepository::class),
        ));

        self::assertTrue($waits->isWaiting(DevInitHandoffSeeder::SESSION_ID, $project));
        self::assertFalse($waits->isWaiting(DevInitHandoffSeeder::SESSION_ID, 'another-project'));
        self::assertNull($cache->getItem(InitInstallWaits::key(DevInitHandoffSeeder::SESSION_ID))->getMetadata()['expiry'] ?? null);
    }
}
