<?php

declare(strict_types=1);

namespace App\Tests\DevSeed;

use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Entity\User;
use App\Module\Account\Entity\WaitlistBlockPattern;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\Dev\DevWaitlistSeeder;
use App\Module\Admin\Service\Dev\DevFeatureFlagSeeder;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Billing\Repository\BetaInviteRepository;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardValue;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardValueRepository;
use App\Module\Board\Service\CardValueReader;
use App\Module\Board\Service\Dev\DevBoardSeeder;
use App\Module\Board\Service\Dev\DevInternalValueSeeder;
use App\Module\Board\Service\Dev\DevMergeQuestionMarkerSeeder;
use App\Module\Board\Service\Dev\DevSessionStartSeeder;
use App\Module\Board\Service\StoredCardMarkers;
use App\Module\Board\View\CardValueView;
use App\Module\Bridge\Entity\ExperimentDefinition;
use App\Module\Bridge\Service\Dev\DevBridgeSeeder;
use App\Module\Inbox\Entity\InboxAskOrigin;
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
use App\Module\Review\Command\ListDocumentsHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Repository\ReviewRepository;
use App\Module\Review\Service\Dev\DevDocumentSeeder;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Service\Dev\DevSiteFeedbackSeeder;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\MarkerLink;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DevSeedFirstRunTest extends DevSeedTestCase
{
    public function test_the_seed_writes_once_shows_every_page_state_and_a_later_day_only_adds_time_series(): void
    {
        $first = $this->seed(self::DAY);
        $afterFirst = $this->rowCounts();
        self::assertGreaterThan(0, $afterFirst['bridge_worker_runs']);

        $second = $this->seed(self::DAY);
        self::assertSame($afterFirst, $this->rowCounts(), 'The second run wrote or removed a row.');

        $this->checkAll([
            'command output' => fn () => $this->the_command_prints_the_dev_project($first, $second),
            'beta invite' => $this->a_seeded_beta_invite_is_found_by_its_deterministic_token(...),
            'preview pages' => $this->the_pages_a_preview_names_exist_with_their_fixed_ids(...),
            'comment panel' => $this->the_comment_panel_and_the_experiment_table_each_have_a_seeded_state_and_an_empty_twin(...),
            'connect page' => $this->the_connect_page_has_a_quiet_machine_and_an_owner_with_no_machine_as_its_empty_twin(...),
            'runs and billing' => $this->the_runs_bridges_billing_and_notifications_of_the_pages_exist(...),
            'page flags' => $this->the_page_flags_are_on_and_tagged_and_dev_project_is_the_newest(...),
            'edge projects' => $this->the_edge_projects_show_no_runs_the_graph_cap_and_a_broken_graph(...),
            'internal value' => $this->the_seed_writes_an_internal_value_on_one_card_and_none_on_its_twins_once(...),
            'merge question marker' => $this->the_seed_writes_one_card_with_the_merge_question_marker_that_the_minute_task_leaves_alone(...),
            'waitlist' => $this->the_seed_writes_visible_and_hidden_entries_and_their_patterns_once(...),
            'api key records' => $this->the_seed_writes_each_record_of_an_api_key_and_its_twin_once(...),
            'document live' => $this->the_seed_writes_the_states_of_the_document_live_previews_once(...),
            'session start cards' => $this->the_seed_writes_the_two_session_start_cards_once(...),
            'session start documents' => $this->the_documents_belong_to_the_lifecycle_project(...),
        ]);

        $this->seed('2026-11-04 12:00:00');
        $changed = array_keys(array_diff_assoc($this->rowCounts(), $afterFirst));
        self::assertContains('bridge_worker_runs', $changed, 'The later day added no run.');
        self::assertSame([], array_diff($changed, self::TIME_SERIES_TABLES));
        foreach ($changed as $table) {
            self::assertGreaterThan($afterFirst[$table], $this->rowCounts()[$table], $table.' lost rows.');
        }
    }

    private function the_pages_a_preview_names_exist_with_their_fixed_ids(): void
    {
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

    private function the_comment_panel_and_the_experiment_table_each_have_a_seeded_state_and_an_empty_twin(): void
    {
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

    private function the_connect_page_has_a_quiet_machine_and_an_owner_with_no_machine_as_its_empty_twin(): void
    {
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

    private function the_runs_bridges_billing_and_notifications_of_the_pages_exist(): void
    {
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

    private function the_page_flags_are_on_and_tagged_and_dev_project_is_the_newest(): void
    {
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

    private function the_edge_projects_show_no_runs_the_graph_cap_and_a_broken_graph(): void
    {
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

    private function the_command_prints_the_dev_project(string $first, string $second): void
    {
        $expected = 'SITE_REVIEW_WIDGET_PROJECT='.DevSeedId::of('project/project/dev-project');
        self::assertStringContainsString($expected, $first);
        self::assertStringContainsString($expected, $second);
        foreach (['accounts', 'feature-flags', 'projects'] as $key) {
            self::assertStringContainsString($key, $first);
        }
        self::assertNotNull($this->projects()->find(DevSeedId::of('project/project/dev-project')));
        self::assertTrue($this->flag('inbox.enabled')?->value);
    }

    private function a_seeded_beta_invite_is_found_by_its_deterministic_token(): void
    {
        $invite = self::getContainer()->get(BetaInviteRepository::class)->findOneByToken(hash('sha256', 'dev-seed-beta-invite-open'));

        self::assertNotNull($invite);
        self::assertTrue($invite->isUsable());
    }

    private function the_seed_writes_an_internal_value_on_one_card_and_none_on_its_twins_once(): void
    {
        $em = $this->em();
        $em->clear();
        $reader = self::getContainer()->get(CardValueReader::class);

        $on = $this->internalViews($em, $reader, DevInternalValueSeeder::cardId('on'));
        self::assertCount(1, $on);
        self::assertTrue($on[0]->value);
        self::assertSame(Actor::System, $on[0]->writerKind);
        self::assertCount(1, self::getContainer()->get(CardEventRepository::class)->findBy(['card' => DevInternalValueSeeder::cardId('on'), 'kind' => CardEventKind::ValueChanged]));

        $unset = $this->internalViews($em, $reader, DevInternalValueSeeder::cardId('unset'));
        self::assertCount(1, $unset);
        self::assertNull($unset[0]->value);
        self::assertNull($unset[0]->writerKind);

        self::assertSame([], $this->internalViews($em, $reader, DevBoardSeeder::cardId('lifecycle-values')));
    }

    /** @return list<CardValueView> */
    private function internalViews(EntityManagerInterface $em, CardValueReader $reader, Uuid $id): array
    {
        $card = $em->find(Card::class, $id) ?? throw new \LogicException('The card is missing.');

        return array_values(array_filter($reader->forCard($card), static fn (CardValueView $view): bool => $view->isInternal() && DevInternalValueSeeder::VALUE_KEY === $view->key));
    }

    private function the_seed_writes_one_card_with_the_merge_question_marker_that_the_minute_task_leaves_alone(): void
    {
        $em = $this->em();
        $em->clear();

        $card = $em->find(Card::class, DevMergeQuestionMarkerSeeder::cardId()) ?? throw new \LogicException('The card is missing.');
        self::assertSame('implementation', $card->column->slug);
        self::assertSame([], $card->inboxMarkers);
        self::assertNull($card->markersDueAt);

        $marker = StoredCardMarkers::of($card)->primary();
        self::assertNotNull($marker);
        self::assertSame('waits-for-merge-approval-answer', $marker->key);
        self::assertSame(MarkerLink::Inbox, $marker->link);
    }

    private function the_seed_writes_visible_and_hidden_entries_and_their_patterns_once(): void
    {
        $em = $this->em();
        $em->clear();
        $entries = self::getContainer()->get(WaitlistEntryRepository::class);

        foreach (['ada', 'grace', 'linus'] as $key) {
            self::assertInstanceOf(WaitlistEntry::class, $entries->findOneVisible(DevWaitlistSeeder::entryId($key)), $key);
        }
        foreach (['blocked-domain', 'blocked-prefix'] as $key) {
            self::assertInstanceOf(WaitlistEntry::class, $entries->find(DevWaitlistSeeder::entryId($key)), $key);
            self::assertNull($entries->findOneVisible(DevWaitlistSeeder::entryId($key)), $key);
        }
        foreach (['mailinator', 'spam-gmail'] as $key) {
            self::assertInstanceOf(WaitlistBlockPattern::class, $em->find(WaitlistBlockPattern::class, DevWaitlistSeeder::patternId($key)), $key);
        }
        self::assertCount(1, $entries->findBy(['email' => 'ada@waitlist.example']));
        self::assertGreaterThanOrEqual(2, $entries->countHidden());
    }

    private function the_seed_writes_each_record_of_an_api_key_and_its_twin_once(): void
    {
        $em = $this->em();
        $em->clear();

        $keyCard = self::getContainer()->get(CardEventRepository::class)->findBy(['card' => DevBoardSeeder::cardId('api-key-history')]);
        self::assertCount(1, $keyCard);
        self::assertSame(Actor::ApiKey, $keyCard[0]->actorKind);
        self::assertSame('CI', $keyCard[0]->apiKeyName);
        self::assertEquals(DevBoardSeeder::apiKeyId(), $keyCard[0]->apiKeyId);

        $personCard = self::getContainer()->get(CardEventRepository::class)->findBy(['card' => DevBoardSeeder::cardId('person-history')]);
        self::assertCount(1, $personCard);
        self::assertSame(Actor::Human, $personCard[0]->actorKind);
        self::assertNull($personCard[0]->apiKeyId);

        $keyedReviews = ['key-verdict' => true, 'key-comment' => false, 'person-twin' => false];
        foreach ($keyedReviews as $slug => $byKey) {
            $reviews = self::getContainer()->get(ReviewRepository::class)->findBy(['version' => $this->versionOf($em, $slug)]);
            self::assertCount(1, $reviews, $slug);
            self::assertSame($byKey, null !== $reviews[0]->apiKeyId, $slug);
        }

        $keyedComments = ['key-verdict' => false, 'key-comment' => true, 'person-twin' => false];
        foreach ($keyedComments as $slug => $byKey) {
            $comments = self::getContainer()->get(CommentRepository::class)->findBy(['version' => $this->versionOf($em, $slug)]);
            self::assertCount(1, $comments, $slug);
            self::assertSame($byKey, null !== $comments[0]->apiKeyId, $slug);
        }

        $keyedNotices = ['api-key-notice' => InboxAskOrigin::ApiKey, 'api-key-notice-twin' => InboxAskOrigin::Loupe];
        foreach ($keyedNotices as $slug => $origin) {
            $notice = $em->find(InboxItem::class, DevSeedId::of('inbox/item/'.$slug));
            self::assertInstanceOf(InboxItem::class, $notice, $slug);
            self::assertSame($origin, $notice->origin, $slug);
            self::assertSame(InboxAskOrigin::ApiKey === $origin, null !== $notice->apiKeyName, $slug);
        }
    }

    private function versionOf(EntityManagerInterface $em, string $slug): DocumentVersion
    {
        return ($em->find(Document::class, DevDocumentSeeder::documentId($slug)) ?? throw new \LogicException('The document '.$slug.' is missing.'))->currentVersion();
    }

    private function the_seed_writes_the_states_of_the_document_live_previews_once(): void
    {
        $em = $this->em();
        $em->clear();

        $archived = $em->find(Document::class, DevDocumentSeeder::documentId('live-archived'));
        self::assertInstanceOf(Document::class, $archived);
        self::assertNotNull($archived->archivedAt);

        $thread = $em->find(Document::class, DevDocumentSeeder::documentId('live-thread'));
        self::assertInstanceOf(Document::class, $thread);
        $comments = self::getContainer()->get(CommentRepository::class)->findBy(['version' => $thread->currentVersion()]);
        self::assertCount(2, $comments);
        $roots = array_values(array_filter($comments, static fn (Comment $comment): bool => !$comment->parent instanceof Comment));
        self::assertCount(1, $roots);
        self::assertSame(CommentStatus::Addressed, $roots[0]->status);

        $lifecycle = $em->find(Project::class, DevProjectSeeder::lifecycleProjectId()) ?? throw new \LogicException('The lifecycle project is missing.');
        $total = \count(self::getContainer()->get(DocumentRepository::class)->findPaginatedByProject($lifecycle, 2, ListDocumentsHandler::PER_PAGE));
        self::assertGreaterThan(ListDocumentsHandler::PER_PAGE, $total);
    }

    private function the_seed_writes_the_two_session_start_cards_once(): void
    {
        $em = $this->em();
        $em->clear();

        foreach (['ask', 'approved'] as $state) {
            $card = $em->find(Card::class, DevSessionStartSeeder::cardId($state)) ?? throw new \LogicException('The card is missing.');
            self::assertSame('tech-design', $card->column->slug);
            self::assertCount(1, $card->documents);

            $switch = self::getContainer()->get(CardValueRepository::class)->findOneBy(['card' => $card, 'key' => DevSessionStartSeeder::SWITCH_KEY]);
            self::assertInstanceOf(CardValue::class, $switch);
            self::assertTrue($switch->value);

            $link = $card->documents->first();
            self::assertNotFalse($link);
            $reviews = self::getContainer()->get(ReviewRepository::class)->findBy(['version' => $link->document->currentVersion()]);
            self::assertCount(1, $reviews);
            self::assertSame(Verdict::Approved, $reviews[0]->verdict);
            self::assertSame(User::WORKFLOW_ID, (string) $reviews[0]->reviewer->id);
        }

        $ask = $em->find(Card::class, DevSessionStartSeeder::cardId('ask')) ?? throw new \LogicException('The card is missing.');
        self::assertNotInstanceOf(CardValue::class, self::getContainer()->get(CardValueRepository::class)->findOneBy(['card' => $ask, 'key' => DevSessionStartSeeder::APPROVED_KEY]));

        $approved = $em->find(Card::class, DevSessionStartSeeder::cardId('approved')) ?? throw new \LogicException('The card is missing.');
        $value = self::getContainer()->get(CardValueRepository::class)->findOneBy(['card' => $approved, 'key' => DevSessionStartSeeder::APPROVED_KEY]);
        self::assertInstanceOf(CardValue::class, $value);
        self::assertTrue($value->value);
        self::assertSame(Actor::System, $value->writerKind);
    }

    private function the_documents_belong_to_the_lifecycle_project(): void
    {
        $em = $this->em();
        $em->clear();

        foreach (array_keys(DevDocumentSeeder::SESSION_START_SLUGS) as $slug) {
            $document = $em->find(Document::class, DevDocumentSeeder::documentId($slug));
            self::assertNotNull($document);
            self::assertSame(['product-design'], array_map(static fn ($tag): string => $tag->name, $document->tags->toArray()));
        }
    }

    /** @param array<string, \Closure(): void> $checks */
    private function checkAll(array $checks): void
    {
        $failures = [];
        foreach ($checks as $name => $check) {
            try {
                $check();
            } catch (\Throwable $e) {
                $failures[] = \sprintf('%s: %s: %s (%s:%d)', $name, $e::class, $e->getMessage(), basename($e->getFile()), $e->getLine());
            }
        }

        if ([] !== $failures) {
            self::fail(\sprintf("%d of %d checks failed:\n%s", \count($failures), \count($checks), implode("\n", $failures)));
        }
    }
}
