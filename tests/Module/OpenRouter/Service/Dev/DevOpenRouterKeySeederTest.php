<?php

declare(strict_types=1);

namespace App\Tests\Module\OpenRouter\Service\Dev;

use App\Module\OpenRouter\Entity\OpenRouterKey;
use App\Module\OpenRouter\Repository\OpenRouterKeyRepository;
use App\Module\OpenRouter\Service\Dev\DevOpenRouterKeySeeder;
use App\Module\Project\Service\Dev\DevOpenRouterProjectSeeder;
use App\Tests\DevSeed\DevProjectFixture;
use App\Tests\DevSeed\SeedContextFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DevOpenRouterKeySeederTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_writes_one_project_key_with_the_fixed_id_and_no_user_key(): void
    {
        $context = SeedContextFactory::create(self::getContainer());
        DevProjectFixture::create(self::getContainer(), $context);
        self::getContainer()->get(DevOpenRouterProjectSeeder::class)->seed($context);
        $seeder = self::getContainer()->get(DevOpenRouterKeySeeder::class);

        $seeder->seed($context);
        $seeder->seed($context);

        $keys = self::getContainer()->get(OpenRouterKeyRepository::class);
        $key = $keys->find(DevOpenRouterKeySeeder::keyId());
        self::assertInstanceOf(OpenRouterKey::class, $key);
        self::assertSame(DevOpenRouterProjectSeeder::projectId()->toRfc4122(), $key->project?->id?->toRfc4122());
        self::assertNull($key->user);
        self::assertSame('a3f9', $key->lastFour);
        self::assertSame(1, $keys->count([]));
    }
}
