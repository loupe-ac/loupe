<?php

declare(strict_types=1);

namespace App\Tests\DevSeed;

use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Admin\Service\Dev\DevFeatureFlagSeeder;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\ExperimentDefinition;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class FullDevSeedTest extends KernelTestCase
{
    private const string DAY = '2026-11-01 12:00:00';

    /** The tables whose rows a later day adds: the daily demo runs, their card and the buckets of their tool time. */
    private const array TIME_SERIES_TABLES = [
        'board_cards', 'board_card_events', 'bridge_worker_runs', 'bridge_worker_run_facts',
        'bridge_worker_run_tool_calls', 'bridge_worker_run_usage', 'bridge_worker_run_bucket_times',
    ];

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

    public function test_a_second_run_on_the_same_day_writes_no_row_and_a_later_day_only_adds_time_series(): void
    {
        $this->seed(self::DAY);
        $afterFirst = $this->rowCounts();
        self::assertGreaterThan(0, $afterFirst['bridge_worker_runs']);

        $this->seed(self::DAY);
        self::assertSame($afterFirst, $this->rowCounts(), 'The second run wrote or removed a row.');

        $this->seed('2026-11-04 12:00:00');
        $changed = array_keys(array_diff_assoc($this->rowCounts(), $afterFirst));
        self::assertContains('bridge_worker_runs', $changed, 'The later day added no run.');
        self::assertSame([], array_diff($changed, self::TIME_SERIES_TABLES));
        foreach ($changed as $table) {
            self::assertGreaterThan($afterFirst[$table], $this->rowCounts()[$table], $table.' lost rows.');
        }
    }

    public function test_the_pages_a_preview_names_exist_with_their_fixed_ids(): void
    {
        $this->seed(self::DAY);

        $em = $this->em();
        $project = $em->find(Project::class, DevProjectSeeder::devProjectId());
        self::assertInstanceOf(Project::class, $project);
        self::assertSame('Dev Project', $project->name);
        self::assertInstanceOf(User::class, $em->find(User::class, DevSeedId::of('account/user/member')));
        self::assertInstanceOf(User::class, $em->find(User::class, DevSeedId::of('account/user/suspended')));
        self::assertInstanceOf(Artifact::class, $em->find(Artifact::class, DevSeedId::of('artifact/artifact/pricing-page-prototype')));
        self::assertInstanceOf(ExperimentDefinition::class, $em->find(ExperimentDefinition::class, DevSeedId::of('bridge/experiment/implement-merge-rate')));
        self::assertInstanceOf(Card::class, $em->find(Card::class, DevSeedId::of('board/card/codex-run')));
        $title = $em->getConnection()->fetchOne("SELECT title FROM board_cards WHERE title LIKE 'Demo runs from %' ORDER BY title LIMIT 1");
        self::assertIsString($title);
        self::assertInstanceOf(Card::class, $em->find(Card::class, DevSeedId::of('board/card/demo-runs-'.substr($title, -10))));
    }

    public function test_the_page_flags_are_on_and_tagged_and_dev_project_is_the_newest(): void
    {
        $this->seed(self::DAY);

        foreach (DevFeatureFlagSeeder::FLAGS as $name) {
            $flag = $this->flag($name) ?? throw new \LogicException($name.' is missing.');
            self::assertTrue($flag->value, $name);
            self::assertContains(DevFeatureFlagSeeder::TAG, $flag->tags, $name);
        }

        $owner = $this->users()->findOneByEmail(SeedContext::DEV_EMAIL) ?? throw new \LogicException('The dev user is missing.');
        $projects = $this->projects()->findNewestByOwner($owner, 100);
        $times = array_map(static fn (Project $project): string => $project->createdAt->format('Y-m-d H:i:s.u'), $projects);
        self::assertSame($times, array_values(array_unique($times)), 'Two projects share a creation time.');
        self::assertSame('Dev Project', $projects[0]->name);
    }

    public function test_an_old_database_keeps_its_accounts_and_renames_its_old_project(): void
    {
        $em = $this->em();
        $dev = new User(fullName: 'Dev User', email: SeedContext::DEV_EMAIL, password: 'x');
        $admin = new User(fullName: 'Admin User', email: SeedContext::ADMIN_EMAIL, password: 'x');
        $admin->roles = ['ROLE_ADMIN'];
        $em->persist($dev);
        $em->persist($admin);
        $old = new Project($dev, 'Dev Project');
        $em->persist($old);
        foreach (DevFeatureFlagSeeder::FLAGS as $name) {
            $flag = $this->flag($name) ?? new FeatureFlag($name, FeatureFlagType::Bool, false);
            $flag->value = false;
            $flag->tags = [];
            $em->persist($flag);
        }
        $em->flush();
        $ids = [$dev->id, $admin->id, $old->id];
        $em->clear();

        $this->seed(self::DAY);

        $em = $this->em();
        self::assertEquals($ids[0], $this->users()->findOneByEmail(SeedContext::DEV_EMAIL)?->id);
        self::assertEquals($ids[1], $this->users()->findOneByEmail(SeedContext::ADMIN_EMAIL)?->id);
        self::assertInstanceOf(User::class, $em->find(User::class, DevSeedId::of('account/user/member')));
        self::assertInstanceOf(User::class, $em->find(User::class, DevSeedId::of('account/user/suspended')));
        $renamed = $em->find(Project::class, $ids[2]);
        self::assertSame(\sprintf('Dev Project (old %s)', substr((string) $ids[2], 0, 8)), $renamed?->name);
        self::assertSame('Dev Project', $em->find(Project::class, DevProjectSeeder::devProjectId())?->name);
        foreach (DevFeatureFlagSeeder::FLAGS as $name) {
            $flag = $this->flag($name) ?? throw new \LogicException($name.' is missing.');
            self::assertTrue($flag->value, $name);
            self::assertContains(DevFeatureFlagSeeder::TAG, $flag->tags, $name);
        }

        $inbox = $this->flag('inbox.enabled') ?? throw new \LogicException('The inbox flag is missing.');
        $inbox->value = false;
        $this->em()->flush();

        $this->seed(self::DAY);

        self::assertFalse($this->flag('inbox.enabled')?->value, 'A second run switched a flag back on.');
    }

    public function test_keep_flag_defaults_writes_no_flag_row(): void
    {
        $before = $this->flagRows();

        $this->seed(self::DAY, ['--keep-flag-defaults' => true]);

        self::assertSame($before, $this->flagRows());
        self::assertInstanceOf(Project::class, $this->em()->find(Project::class, DevProjectSeeder::devProjectId()));
    }

    /** @param array<string, mixed> $options */
    private function seed(string $now, array $options = []): void
    {
        $kernel = self::bootKernel();
        self::getContainer()->set('clock', new MockClock($now));
        $tester = new CommandTester(new Application($kernel)->find('app:dev:seed'));
        $tester->execute($options);
        $tester->assertCommandIsSuccessful();
    }

    /** @return array<string, int> */
    private function rowCounts(): array
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
    private function flagRows(): array
    {
        $rows = array_map(static fn (FeatureFlag $flag): array => [$flag->name, (bool) $flag->value, $flag->tags], self::getContainer()->get(FeatureFlagRepository::class)->findAll());
        sort($rows);

        return $rows;
    }

    private function flag(string $name): ?FeatureFlag
    {
        return self::getContainer()->get(FeatureFlagRepository::class)->findOneBy(['name' => $name]);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function users(): UserRepository
    {
        return self::getContainer()->get(UserRepository::class);
    }

    private function projects(): ProjectRepository
    {
        return self::getContainer()->get(ProjectRepository::class);
    }
}
