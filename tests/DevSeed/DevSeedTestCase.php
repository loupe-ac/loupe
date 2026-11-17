<?php

declare(strict_types=1);

namespace App\Tests\DevSeed;

use App\DevSeed\SeedContext;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Board\Entity\Card;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

abstract class DevSeedTestCase extends KernelTestCase
{
    protected const string DAY = '2026-11-01 12:00:00';

    /** The tables whose rows a later day adds: the daily demo runs, their card and the buckets of their tool time. */
    protected const array TIME_SERIES_TABLES = [
        'board_cards', 'board_card_events', 'bridge_worker_runs', 'bridge_worker_run_facts',
        'bridge_worker_run_tool_calls', 'bridge_worker_run_usage', 'bridge_worker_run_bucket_times',
        'bridge_account_readings',
    ];

    protected const string SPEND_QUERY = 'SELECT SUM(cost_usd) AS cost, SUM(input_tokens) AS input, SUM(output_tokens) AS output FROM bridge_worker_run_usage';

    protected function setUp(): void
    {
        self::bootKernel();
        $users = self::getContainer()->get(UserRepository::class);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach ([SeedContext::DEV_EMAIL, SeedContext::ADMIN_EMAIL] as $email) {
            $user = $users->findOneByEmail($email);
            if ($user instanceof User) {
                $em->remove($user);
            }
        }
        $em->flush();
    }

    /** @param array<string, mixed> $options */
    protected function seed(string $now, array $options = []): void
    {
        $kernel = self::bootKernel();
        self::getContainer()->set('clock', new MockClock($now));
        $tester = new CommandTester(new Application($kernel)->find('app:dev:seed'));
        $tester->execute($options);
        $tester->assertCommandIsSuccessful();
    }

    /** @return array<string, int> */
    protected function rowCounts(): array
    {
        $connection = self::getContainer()->get(Connection::class);
        $counts = [];
        foreach ($connection->createSchemaManager()->listTableNames() as $table) {
            $counts[$table] = (int) $connection->fetchOne(\sprintf('SELECT COUNT(*) FROM "%s"', $table));
        }
        ksort($counts);

        return $counts;
    }

    /** @return list<array{string, bool, list<string>}> */
    protected function flagRows(): array
    {
        $rows = array_map(static fn (FeatureFlag $flag): array => [$flag->name, (bool) $flag->value, $flag->tags], self::getContainer()->get(FeatureFlagRepository::class)->findAll());
        sort($rows);

        return $rows;
    }

    protected function flag(string $name): ?FeatureFlag
    {
        return self::getContainer()->get(FeatureFlagRepository::class)->findOneBy(['name' => $name]);
    }

    protected function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function users(): UserRepository
    {
        return self::getContainer()->get(UserRepository::class);
    }

    protected function projects(): ProjectRepository
    {
        return self::getContainer()->get(ProjectRepository::class);
    }
}
