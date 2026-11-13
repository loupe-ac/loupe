<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\SeedContext;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Project\Service\InitInstallWaits;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Writes the wait mark of one init session with no end, so the hand-off page of the preview shows for as long as
 * the seed lives. A real mark ends after a few minutes.
 */
#[When('dev')]
#[When('test')]
final readonly class DevInitHandoffSeeder implements DevSeederInterface
{
    public const string KEY = 'github-init-handoff';
    public const string SESSION_ID = '3f1c2a64-8b7d-4e11-9a52-0c6d1e7b9a10';

    public function __construct(
        private CacheItemPoolInterface $cache,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevRepositoryPickerSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $this->cache->save(
            $this->cache->getItem(InitInstallWaits::key(self::SESSION_ID))
                ->set((string) DevProjectSeeder::lifecycleProjectId())
                ->expiresAfter(null),
        );
    }
}
