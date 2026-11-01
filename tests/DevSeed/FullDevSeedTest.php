<?php

declare(strict_types=1);

namespace App\Tests\DevSeed;

use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\Dev\DevAccountSeeder;
use App\Module\Admin\Service\Dev\DevFeatureFlagSeeder;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Board\Command\DeleteCardCommand;
use App\Module\Board\Command\DeleteCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Command\TimeOutQuietWorkerRunsCommand;
use App\Module\Bridge\Command\TimeOutQuietWorkerRunsHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\ExperimentDefinition;
use App\Module\Bridge\Service\AccountLimits;
use App\Module\Bridge\Service\Dev\DevBridgeSeeder;
use App\Module\Bridge\ValueObject\WindowLimitState;
use App\Module\Inbox\Service\Dev\DevAgentRunSeeder;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Service\Dev\DevEdgeProjectSeeder;
use App\Module\Insights\Service\SavedGraphCap;
use App\Module\Insights\Service\SavedGraphHealth;
use App\Module\Insights\View\BrokenGraphReason;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Workflow\Contract\Actor;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;
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
        'bridge_account_readings',
    ];

    private const string SPEND_QUERY = 'SELECT SUM(cost_usd) AS cost, SUM(input_tokens) AS input, SUM(output_tokens) AS output FROM bridge_worker_run_usage';

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

    public function test_a_second_run_at_the_same_time_writes_no_row_and_a_later_day_only_adds_time_series(): void
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

    public function test_a_later_run_restores_a_deleted_card_and_keeps_its_runs_linked(): void
    {
        $this->seed(self::DAY);
        $em = $this->em();
        $em->clear();
        $project = $em->find(Project::class, DevProjectSeeder::devProjectId()) ?? throw new \LogicException('The dev project is missing.');
        $handler = self::getContainer()->get(DeleteCardHandler::class);
        $deleted = [];
        foreach (self::getContainer()->get(CardRepository::class)->findBy(['project' => $project->id]) as $card) {
            if (0 === $card->number % 2) {
                $deleted[(string) $card->id] = $card->number;
                $handler(new DeleteCardCommand($card, Actor::Human));
            }
        }
        self::assertNotEmpty($deleted);
        $runs = $this->rowCounts()['bridge_worker_runs'];

        $this->seed(self::DAY);

        $em = $this->em();
        foreach ($deleted as $id => $number) {
            $card = $em->find(Card::class, Uuid::fromString($id));
            self::assertInstanceOf(Card::class, $card, 'The seed did not restore a deleted card.');
            self::assertNotSame($number, $card->number, 'The test must give the restored card a new number.');
        }
        self::assertSame($runs, $this->rowCounts()['bridge_worker_runs'], 'The seed wrote or removed a run.');
        $connection = $em->getConnection();
        foreach (['bridge_worker_runs', 'bridge_worker_run_facts'] as $table) {
            self::assertGreaterThan(0, (int) $connection->fetchOne(\sprintf("SELECT COUNT(*) FROM %s WHERE subject_type = 'card'", $table)));
            self::assertSame(0, (int) $connection->fetchOne(\sprintf(
                "SELECT COUNT(*) FROM %s r LEFT JOIN board_cards c ON c.id = r.subject_id AND c.number = r.card_number WHERE r.subject_type = 'card' AND c.id IS NULL",
                $table,
            )), $table.' names a card that does not exist.');
        }
    }

    public function test_a_later_run_restores_deleted_parents_and_writes_no_second_child(): void
    {
        $this->seed(self::DAY);
        $counts = $this->rowCounts();
        self::assertGreaterThan(0, $counts['bridge_experiment_definitions']);
        self::assertGreaterThan(0, $counts['insights_analyses']);
        $connection = $this->em()->getConnection();
        $connection->executeStatement('DELETE FROM bridge_experiment_definitions');
        $connection->executeStatement('DELETE FROM insights_analyses');
        self::assertNotSame($counts, $this->rowCounts());

        $this->seed(self::DAY);

        self::assertSame($counts, $this->rowCounts());
    }

    public function test_a_later_run_restores_each_deleted_run_on_its_own(): void
    {
        $this->seed(self::DAY);
        $runs = $this->rowCounts()['bridge_worker_runs'];
        $connection = $this->em()->getConnection();
        $deleted = array_values(array_filter(
            array_map(strval(...), $connection->fetchFirstColumn('SELECT id FROM bridge_worker_runs ORDER BY id')),
            static fn (string $id): bool => 0 === hexdec(substr($id, -1)) % 4,
        ));
        self::assertNotEmpty($deleted);
        $connection->executeStatement('DELETE FROM bridge_worker_runs WHERE id IN (?)', [$deleted], [ArrayParameterType::STRING]);
        self::assertLessThan($runs, $this->rowCounts()['bridge_worker_runs']);

        $this->seed(self::DAY);

        self::assertSame($runs, $this->rowCounts()['bridge_worker_runs']);
        self::assertSame([], array_values(array_diff($deleted, array_map(strval(...), $connection->fetchFirstColumn('SELECT id FROM bridge_worker_runs')))));
    }

    public function test_a_restored_run_links_its_stored_usage_and_the_spend_stays_the_same(): void
    {
        $this->seed(self::DAY);
        $counts = $this->rowCounts();
        $connection = $this->em()->getConnection();
        self::assertGreaterThan(0, $counts['bridge_worker_run_usage']);
        $spend = $connection->fetchAssociative(self::SPEND_QUERY);
        $connection->executeStatement('DELETE FROM bridge_worker_runs');
        self::assertSame($counts['bridge_worker_run_usage'], (int) $connection->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_usage WHERE run_id IS NULL'));

        $this->seed(self::DAY);

        self::assertSame($counts['bridge_worker_run_usage'], $this->rowCounts()['bridge_worker_run_usage'], 'A restored run wrote a second usage row.');
        self::assertSame($spend, $connection->fetchAssociative(self::SPEND_QUERY), 'A restored run changed the spend.');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_usage WHERE run_id IS NULL'), 'A restored run left its usage row with no link.');
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM bridge_worker_run_usage u LEFT JOIN bridge_worker_run_facts f ON f.run_id = u.run_id WHERE f.tokens_in IS DISTINCT FROM u.input_tokens',
        ), 'The fact row of a restored run does not hold its usage.');
    }

    public function test_a_later_run_restores_a_deleted_usage_row_of_a_stored_run(): void
    {
        $this->seed(self::DAY);
        $counts = $this->rowCounts();
        $connection = $this->em()->getConnection();
        $spend = $connection->fetchAssociative(self::SPEND_QUERY);
        $connection->executeStatement('DELETE FROM bridge_worker_run_usage');

        $this->seed(self::DAY);

        self::assertSame($counts, $this->rowCounts(), 'The seed did not restore each usage row, or it wrote another row.');
        self::assertSame($spend, $connection->fetchAssociative(self::SPEND_QUERY), 'A restored usage row changed the spend.');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_usage WHERE run_id IS NULL'), 'A restored usage row has no link to its run.');
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM bridge_worker_run_usage u LEFT JOIN bridge_worker_run_facts f ON f.run_id = u.run_id WHERE f.tokens_in IS DISTINCT FROM u.input_tokens',
        ), 'The fact row of the run does not hold its restored usage.');
    }

    public function test_a_later_run_on_the_same_day_keeps_every_usage_window_known(): void
    {
        $this->seed(self::DAY);
        $this->seed('2026-11-01 16:00:00');

        $bridge = $this->em()->find(Bridge::class, ['owner' => $this->users()->findOneByEmail(SeedContext::DEV_EMAIL)?->id, 'id' => Uuid::fromString(DevBridgeSeeder::MAIN_BRIDGE)]);
        self::assertInstanceOf(Bridge::class, $bridge);
        $limits = self::getContainer()->get(AccountLimits::class)->forBridge($bridge);
        self::assertCount(2, $limits);
        foreach ($limits as $account => $limit) {
            self::assertCount(2, $limit->windows, $account);
            foreach ($limit->windows as $window) {
                self::assertNotSame(WindowLimitState::Unknown, $window->state, $account.' '.$window->reading->window);
            }
        }
    }

    public function test_a_later_run_renews_the_stripe_grant_of_the_member(): void
    {
        $this->seed(self::DAY);
        $counts = $this->rowCounts()['subscriptions'];

        $this->seed('2026-11-20 12:00:00');

        $member = $this->users()->findOneByEmail(DevAccountSeeder::MEMBER_EMAIL) ?? throw new \LogicException('The member is missing.');
        $profile = self::getContainer()->get(BillingProfileRepository::class)->findOneByUser($member);
        self::assertNotNull($profile?->currentSubscriptionOfKind(SubscriptionKind::Stripe, new \DateTimeImmutable('2026-11-20 12:00:00')));
        self::assertSame($counts, $this->rowCounts()['subscriptions']);
    }

    public function test_a_later_run_reopens_the_open_runs_that_the_quiet_sweep_timed_out(): void
    {
        $this->seed(self::DAY);
        $clock = self::getContainer()->get('clock');
        self::assertInstanceOf(MockClock::class, $clock);
        $clock->modify('+1 hour');
        self::getContainer()->get(TimeOutQuietWorkerRunsHandler::class)(new TimeOutQuietWorkerRunsCommand());
        $runRow = static fn (string $sql, string $key): mixed => self::getContainer()->get(Connection::class)->fetchOne($sql, [DevSeedId::of('bridge/run/agent-runs/'.$key)->toRfc4122()]);
        $stateOf = static fn (string $key): mixed => $runRow('SELECT state FROM bridge_worker_runs WHERE id = ?', $key);
        self::assertSame('timed-out', $stateOf('running'));

        $this->seed('2026-11-01 13:00:00');

        self::assertSame('running', $stateOf('running'));
        self::assertSame('running', $runRow('SELECT outcome FROM bridge_worker_run_facts WHERE run_id = ?', 'running'));
        self::assertSame('succeeded', $stateOf('succeeded'));
        self::assertStringStartsWith('2026-11-01 12:46:00', (string) $runRow('SELECT started_at FROM bridge_worker_runs WHERE id = ?', 'running'));
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

    public function test_the_runs_bridges_billing_and_notifications_of_the_pages_exist(): void
    {
        $this->seed(self::DAY);

        $connection = self::getContainer()->get(Connection::class);
        self::assertGreaterThanOrEqual(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM bridges'));
        self::assertGreaterThanOrEqual(2, (int) $connection->fetchOne("SELECT COUNT(DISTINCT account) FROM bridge_account_readings WHERE bridge_id = '".DevBridgeSeeder::MAIN_BRIDGE."'"));
        $states = $connection->fetchFirstColumn("SELECT DISTINCT state FROM bridge_worker_runs WHERE bridge_id = '".DevBridgeSeeder::MAIN_BRIDGE."'");
        foreach (['running', 'waiting-for-person', 'succeeded', 'failed', 'usage-limit', 'stopped', 'timed-out'] as $state) {
            self::assertContains($state, $states, $state);
        }
        self::assertInstanceOf(Card::class, $this->em()->find(Card::class, DevAgentRunSeeder::cardId()));

        $now = new \DateTimeImmutable(self::DAY);
        foreach ([SeedContext::DEV_EMAIL, SeedContext::ADMIN_EMAIL] as $email) {
            $user = $this->users()->findOneByEmail($email) ?? throw new \LogicException($email.' is missing.');
            $profile = self::getContainer()->get(BillingProfileRepository::class)->findOneByUser($user);
            self::assertTrue($profile?->hasCurrentSubscription($now), $email);
        }
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM beta_invites'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM push_devices'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM notification_preferences'));
        self::assertTrue($this->flag('billing.enabled')?->value);
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

    public function test_the_edge_projects_show_no_runs_the_graph_cap_and_a_broken_graph(): void
    {
        $this->seed(self::DAY);
        $em = $this->em();
        $em->clear();
        $connection = $em->getConnection();

        $noRuns = $em->find(Project::class, DevEdgeProjectSeeder::noRunsProjectId()) ?? throw new \LogicException('The project with no runs is missing.');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM bridge_worker_runs WHERE project_id = ?', [$noRuns->id]));
        self::assertGreaterThan(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM bridge_worker_runs WHERE project_id = ?', [DevProjectSeeder::devProjectId()]));

        $atCap = $em->find(Project::class, DevEdgeProjectSeeder::atCapProjectId()) ?? throw new \LogicException('The project at the cap is missing.');
        $graphs = self::getContainer()->get(InsightsSavedGraphRepository::class)->findActive($atCap);
        self::assertCount(SavedGraphCap::DEFAULT, $graphs);
        self::assertTrue(self::getContainer()->get(SavedGraphCap::class)->isReached($atCap));
        self::assertSame([], self::getContainer()->get(SavedGraphHealth::class)->check($atCap, $graphs));

        $broken = $em->find(Project::class, DevEdgeProjectSeeder::brokenGraphProjectId()) ?? throw new \LogicException('The project with a broken graph is missing.');
        $problems = self::getContainer()->get(SavedGraphHealth::class)->check($broken, self::getContainer()->get(InsightsSavedGraphRepository::class)->findActive($broken));
        self::assertCount(1, $problems);
        self::assertSame(BrokenGraphReason::Bucket, array_first($problems)?->reason);

        $owner = $this->users()->findOneByEmail(SeedContext::DEV_EMAIL) ?? throw new \LogicException('The dev user is missing.');
        $names = array_map(static fn (Project $project): string => $project->name, $this->projects()->findNewestByOwner($owner, 100));
        self::assertSame(['Dev Project', DevEdgeProjectSeeder::NO_RUNS_NAME, DevEdgeProjectSeeder::AT_CAP_NAME, DevEdgeProjectSeeder::BROKEN_GRAPH_NAME], \array_slice($names, 0, 4));
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
        self::assertSame(0, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM billing_profiles'));
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
