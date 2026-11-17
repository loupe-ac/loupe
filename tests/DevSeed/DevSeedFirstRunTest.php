<?php

declare(strict_types=1);

namespace App\Tests\DevSeed;

use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Entity\User;
use App\Module\Admin\Service\Dev\DevFeatureFlagSeeder;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\ExperimentDefinition;
use App\Module\Bridge\Service\Dev\DevBridgeSeeder;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Service\Dev\DevAgentRunSeeder;
use App\Module\Inbox\Service\Dev\DevExperimentSeeder;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Service\Dev\DevEdgeProjectSeeder;
use App\Module\Insights\Service\SavedGraphCap;
use App\Module\Insights\Service\SavedGraphHealth;
use App\Module\Insights\View\BrokenGraphReason;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Project\Workshop\WorkshopConnection;
use App\Module\Project\Workshop\WorkshopConnectionsProviderInterface;
use App\Module\Review\Entity\Document;
use App\Module\Review\Service\Dev\DevDocumentSeeder;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Service\Dev\DevSiteFeedbackSeeder;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final class DevSeedFirstRunTest extends DevSeedTestCase
{
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

    public function test_the_comment_panel_and_the_experiment_table_each_have_a_seeded_state_and_an_empty_twin(): void
    {
        $this->seed(self::DAY);

        $connection = $this->em()->getConnection();
        $comments = static fn (string $slug): int => (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM comments c JOIN document_versions v ON v.id = c.version_id WHERE v.document_id = ?',
            [DevDocumentSeeder::documentId($slug)->toRfc4122()],
        );
        self::assertSame(1, $comments('worker-run-history'));
        self::assertSame(0, $comments('column-rules'));

        $experiment = static fn (string $table): int => (int) $connection->fetchOne(
            \sprintf('SELECT COUNT(*) FROM %s WHERE project_id = ? AND experiment = ?', $table),
            [DevProjectSeeder::devProjectId()->toRfc4122(), DevExperimentSeeder::EMPTY_EXPERIMENT],
        );
        self::assertSame(1, $experiment('bridge_experiment_definitions'));
        self::assertSame(1, $experiment('bridge_experiment_pins'));
        self::assertSame(0, $experiment('bridge_worker_runs'));
    }

    public function test_the_connect_page_has_a_quiet_machine_and_an_owner_with_no_machine_as_its_empty_twin(): void
    {
        $this->seed(self::DAY);

        $connections = self::getContainer()->get(WorkshopConnectionsProviderInterface::class);
        $projects = self::getContainer()->get(ProjectRepository::class);
        $quiet = static fn (Uuid $projectId): int => \count(array_filter(
            $connections->forOwnerOf($projects->find($projectId) ?? throw new \LogicException('The project is seeded.')),
            static fn (WorkshopConnection $connection): bool => $connection->quiet,
        ));

        self::assertGreaterThan(0, $quiet(DevProjectSeeder::devProjectId()));
        self::assertSame(0, $quiet(DevProjectSeeder::keysProjectId()));
    }

    public function test_the_showcase_exists_with_fixed_ids_and_its_requests_do_not_need_the_inbox_flag(): void
    {
        $this->seed(self::DAY, ['--keep-flag-defaults' => true]);

        $em = $this->em();
        self::assertNotTrue($this->flag('inbox.enabled')?->value);
        foreach (['checkout', 'history', 'column-rules', 'onboarding', 'project-export', 'export-documents', 'sync-forge-state', 'outdated-approval', 'empty-basket'] as $slug) {
            self::assertInstanceOf(Card::class, $em->find(Card::class, DevSeedId::of('board/card/'.$slug)), $slug);
        }
        foreach (['worker-run-history', 'column-rules', 'first-handoff'] as $slug) {
            self::assertInstanceOf(Document::class, $em->find(Document::class, DevSeedId::of('review/document/'.$slug)), $slug);
        }
        foreach (['retries', 'run-history', 'pull-request', 'column-rules', 'handoff', 'answered', 'declined', 'reviewed', 'left-open'] as $slug) {
            self::assertInstanceOf(InboxItem::class, $em->find(InboxItem::class, DevSeedId::of('inbox/item/'.$slug)), $slug);
        }
        foreach (DevSiteFeedbackSeeder::SLUGS as $slug) {
            self::assertInstanceOf(SiteReviewComment::class, $em->find(SiteReviewComment::class, DevSiteFeedbackSeeder::commentId($slug)), $slug);
        }

        $connection = $em->getConnection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM card_pauses'));
        self::assertSame(4, (int) $connection->fetchOne('SELECT COUNT(*) FROM board_card_site_review_comments'));
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM board_cards WHERE parent_card_id IS NOT NULL AND title = 'Export the documents'"));
        $cards = array_map(static fn (string $slug): string => (string) DevSeedId::of('board/card/'.$slug), ['checkout', 'history', 'column-rules', 'onboarding', 'project-export', 'export-documents', 'sync-forge-state', 'outdated-approval', 'empty-basket']);
        $lists = [
            'board_cards' => $connection->fetchFirstColumn('SELECT created_at FROM board_cards WHERE id IN (?)', [$cards], [ArrayParameterType::STRING]),
            'inbox_items' => $connection->fetchFirstColumn('SELECT created_at FROM inbox_items'),
            'documents' => $connection->fetchFirstColumn('SELECT created_at FROM documents WHERE id IN (?)', [array_map(static fn (string $slug): string => (string) DevSeedId::of('review/document/'.$slug), ['worker-run-history', 'column-rules', 'first-handoff'])], [ArrayParameterType::STRING]),
        ];
        foreach ($lists as $table => $column) {
            $times = array_map(strval(...), $column);
            self::assertSame($times, array_values(array_unique($times)), $table.' holds two records with one creation time.');
        }
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
}
