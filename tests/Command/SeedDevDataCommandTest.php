<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SeedDevDataCommand;
use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\DevSeedIds;
use App\DevSeed\SeedContext;
use App\Module\Account\Repository\UserRepository;
use App\Module\Project\Repository\ProjectRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class SeedDevDataCommandTest extends KernelTestCase
{
    public function test_it_runs_every_seeder_twice_and_prints_the_dev_project(): void
    {
        $first = $this->runSeeder([]);
        $second = $this->runSeeder([]);

        $expected = 'SITE_REVIEW_WIDGET_PROJECT='.DevSeedId::of('project/project/dev-project');
        self::assertStringContainsString($expected, $first);
        self::assertStringContainsString($expected, $second);
        foreach (['accounts', 'feature-flags', 'projects'] as $key) {
            self::assertStringContainsString($key, $first);
        }
        self::assertNotNull(self::getContainer()->get(ProjectRepository::class)->find(DevSeedId::of('project/project/dev-project')));
        self::assertTrue(self::getContainer()->get(FeatureFlagRepository::class)->findOneBy(['name' => 'inbox.enabled'])?->value);
    }

    public function test_it_fails_when_a_seeder_leaves_a_queued_id_behind(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $leaky = new class implements DevSeederInterface {
            #[\Override]
            public function key(): string
            {
                return 'leaky';
            }

            #[\Override]
            public function dependsOn(): array
            {
                return [];
            }

            #[\Override]
            public function seed(SeedContext $context): void
            {
                $context->ids->queue(\stdClass::class, Uuid::v4());
            }
        };
        $command = new SeedDevDataCommand([$leaky], $container->get(ClockInterface::class), $container->get(DevSeedIds::class), $container->get(UserRepository::class), $container->get(ProjectRepository::class));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"leaky" queued a fixed id');

        new CommandTester($command)->execute([]);
    }

    /** @param array<string, mixed> $options */
    private function runSeeder(array $options): string
    {
        $application = new Application(self::bootKernel());
        $tester = new CommandTester($application->find('app:dev:seed'));
        $tester->execute($options);
        $tester->assertCommandIsSuccessful();

        return $tester->getDisplay();
    }
}
