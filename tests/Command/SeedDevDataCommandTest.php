<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SeedDevDataCommand;
use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedIds;
use App\DevSeed\SeedContext;
use App\Module\Account\Repository\UserRepository;
use App\Module\Project\Repository\ProjectRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class SeedDevDataCommandTest extends KernelTestCase
{
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
}
