<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Service\Dev;

use App\DevSeed\DevSeedId;
use App\Module\Account\Service\Dev\DevAccountSeeder;
use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Repository\ApiKeyRepository;
use App\Module\OAuth\Service\Dev\DevApiKeySeeder;
use App\Tests\DevSeed\SeedContextFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DevApiKeySeederTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_writes_the_states_of_the_keys_page_once(): void
    {
        $context = SeedContextFactory::create(self::getContainer());
        self::getContainer()->get(DevAccountSeeder::class)->seed($context);
        $seeder = self::getContainer()->get(DevApiKeySeeder::class);

        $seeder->seed($context);
        $seeder->seed($context);

        $keys = self::getContainer()->get(ApiKeyRepository::class);
        self::assertCount(3, $keys->findBy(['owner' => DevSeedId::of(DevAccountSeeder::KEYS_USER)]));
        self::assertCount(0, $keys->findBy(['owner' => DevSeedId::of(DevAccountSeeder::KEYS_EMPTY_USER)]));

        $ended = $keys->find(DevApiKeySeeder::keyId('ended-script'));
        self::assertInstanceOf(ApiKey::class, $ended);
        self::assertSame(DevSeedId::of(DevAccountSeeder::KEYS_EXPIRED_USER)->toRfc4122(), $ended->owner->id?->toRfc4122());
        self::assertTrue($ended->isExpiredAt($context->now));

        $live = $keys->find(DevApiKeySeeder::keyId('old-script'));
        self::assertInstanceOf(ApiKey::class, $live);
        self::assertFalse($live->isExpiredAt($context->now));
    }
}
