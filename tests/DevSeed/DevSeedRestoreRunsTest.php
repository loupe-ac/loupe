<?php

declare(strict_types=1);

namespace App\Tests\DevSeed;

use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Entity\User;
use App\Module\Account\Service\Dev\DevAccountSeeder;
use App\Module\Admin\Service\Dev\DevFeatureFlagSeeder;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Board\Command\DeleteCardCommand;
use App\Module\Board\Command\DeleteCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Command\TimeOutQuietWorkerRunsCommand;
use App\Module\Bridge\Command\TimeOutQuietWorkerRunsHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Service\AccountLimits;
use App\Module\Bridge\Service\Dev\DevBridgeSeeder;
use App\Module\Bridge\ValueObject\WindowLimitState;
use App\Module\Project\Entity\Project;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Workflow\Contract\Actor;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

#[Group('dev-seed-restore')]
final class DevSeedRestoreRunsTest extends DevSeedTestCase
{
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

        self::assertFalse($this->flag('inbox.enabled')->value, 'A second run switched a flag back on.');
    }

    public function test_keep_flag_defaults_writes_no_flag_row_and_seeds_every_other_record(): void
    {
        $before = $this->flagRows();

        $this->seed(self::DAY, ['--keep-flag-defaults' => true]);

        self::assertSame($before, $this->flagRows());
        self::assertGreaterThan(0, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM billing_profiles'));
        $counts = $this->rowCounts();

        $this->seed(self::DAY);

        self::assertSame(array_diff_key($counts, ['feature_flag' => 0]), array_diff_key($this->rowCounts(), ['feature_flag' => 0]), 'The option left out a record that the full seed writes.');
        self::assertInstanceOf(Project::class, $this->em()->find(Project::class, DevProjectSeeder::devProjectId()));
    }
}
