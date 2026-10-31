<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Condition;

use App\Module\Board\Workflow\Condition\CardDocument;
use App\Module\Board\Workflow\Condition\CardFollowsFinished;
use App\Module\Workflow\Condition\Conditions;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Contract\MarkerSubjectType;
use App\Module\Workflow\Contract\NamesMarkerSubject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ConditionCatalogueTest extends KernelTestCase
{
    private const array SOURCES = [
        'agent_review.due' => 'workflow.source.agent_review',
        'agent_review.failed' => 'workflow.source.agent_review',
        'agent_review.overridden' => 'workflow.source.agent_review',
        'agent_review.passed' => 'workflow.source.agent_review',
        'agent_review.uncommented' => 'workflow.source.agent_review',
        'agent_review.unposted' => 'workflow.source.agent_review',
        'bridge.account.usage' => 'workflow.source.bridge',
        'card.blocker.closed' => 'workflow.source.board',
        'card.blocker.open' => 'workflow.source.board',
        'card.children.exist' => 'workflow.source.board',
        'card.children.finished' => 'workflow.source.board',
        'card.children.marked' => 'workflow.source.board',
        'card.children.merged_into_epic_branch' => 'workflow.source.board',
        'card.discovery.approved' => 'workflow.source.readiness',
        'card.discovery.requested' => 'workflow.source.readiness',
        'card.discovery.revising' => 'workflow.source.readiness',
        'card.document.agent_review_open' => 'workflow.source.inbox',
        'card.document.approval_withdrawn' => 'workflow.source.board',
        'card.document.approved' => 'workflow.source.board',
        'card.document.changes_requested' => 'workflow.source.board',
        'card.document.entry_decisions_open' => 'workflow.source.board',
        'card.document.entry_threads_open' => 'workflow.source.board',
        'card.document.in_review' => 'workflow.source.board',
        'card.document.linked' => 'workflow.source.board',
        'card.document.published_in_column' => 'workflow.source.board',
        'card.document.rejected_before' => 'workflow.source.board',
        'card.fix_run.uncommented' => 'workflow.source.board',
        'card.follows.finished' => 'workflow.source.board',
        'card.held' => 'workflow.source.bridge',
        'card.in_slot' => 'workflow.source.board',
        'card.inbox.open_question' => 'workflow.source.inbox',
        'card.parent.document.approved' => 'workflow.source.board',
        'card.parent.epic_branch.exists' => 'workflow.source.board',
        'card.parent.epic_branch.synced' => 'workflow.source.board',
        'card.parent.exists' => 'workflow.source.board',
        'card.parent.in_slot' => 'workflow.source.board',
        'card.parent.run.active' => 'workflow.source.bridge',
        'card.paused' => 'workflow.source.board',
        'card.pr.all_closed_unmerged' => 'workflow.source.forge',
        'card.pr.all_finished_one_merged' => 'workflow.source.forge',
        'card.pr.approval_stale' => 'workflow.source.board',
        'card.pr.draft_open' => 'workflow.source.forge',
        'card.pr.linked' => 'workflow.source.forge',
        'card.quiet_for' => 'workflow.source.board',
        'card.review.needs_person' => 'workflow.source.board',
        'card.review.unseen_artifact' => 'workflow.source.board',
        'card.rule.held_by_blocker' => 'workflow.source.board',
        'card.rule.held_by_work_hold' => 'workflow.source.board',
        'card.rule.retry_scheduled' => 'workflow.source.board',
        'card.run.asking' => 'workflow.source.inbox',
        'card.run.done_at_head' => 'workflow.source.board',
        'card.run.finished_with' => 'workflow.source.bridge',
        'card.run.last_refusal' => 'workflow.source.bridge',
        'card.run.needs_person' => 'workflow.source.bridge',
        'card.run.session_open' => 'workflow.source.bridge',
        'card.run.stopped' => 'workflow.source.bridge',
        'card.run.waits_on_answer' => 'workflow.source.inbox',
        'card.run.work_active' => 'workflow.source.bridge',
        'card.run.work_no_capable_taker' => 'workflow.source.bridge',
        'card.run.work_no_taker' => 'workflow.source.bridge',
        'card.run.work_open' => 'workflow.source.bridge',
        'card.run.work_taker_paused' => 'workflow.source.bridge',
        'card.run.worker_active' => 'workflow.source.bridge',
        'card.site_review.check_stale' => 'workflow.source.board',
        'card.site_review.verdict_unsent' => 'workflow.source.board',
        'card.type' => 'workflow.source.board',
        'card.value' => 'workflow.source.board',
        'design_review.due' => 'workflow.source.agent_review',
        'design_review.finished' => 'workflow.source.agent_review',
        'design_review.fix_due' => 'workflow.source.agent_review',
        'design_review.passed' => 'workflow.source.agent_review',
        'design_review.running' => 'workflow.source.agent_review',
        'pr.approval_check_passed' => 'workflow.source.forge',
        'pr.approval_covers_head' => 'workflow.source.forge',
        'pr.base_is_default' => 'workflow.source.forge',
        'pr.base_is_epic_branch' => 'workflow.source.forge',
        'pr.base_is_merge_target' => 'workflow.source.forge',
        'pr.behind' => 'workflow.source.forge',
        'pr.can_merge' => 'workflow.source.forge',
        'pr.can_post_check' => 'workflow.source.forge',
        'pr.changes_requested' => 'workflow.source.forge',
        'pr.checks_failed' => 'workflow.source.forge',
        'pr.checks_on_head' => 'workflow.source.forge',
        'pr.checks_passed' => 'workflow.source.forge',
        'pr.checks_pending' => 'workflow.source.forge',
        'pr.conflicting' => 'workflow.source.forge',
        'pr.draft' => 'workflow.source.forge',
        'pr.forge_request_pending' => 'workflow.source.forge',
        'pr.merge_blocked' => 'workflow.source.forge',
        'pr.open' => 'workflow.source.forge',
        'pr.parent_merged' => 'workflow.source.forge',
        'pr.ready_for' => 'workflow.source.forge',
        'pr.stack_parent' => 'workflow.source.forge',
        'pr.stacked' => 'workflow.source.forge',
    ];

    /** The keys whose waiting sentence id is not the key with underscores. */
    private const array WAITING_SUFFIXES = [
        'card.blocker.closed' => 'card_has_closed_blocker',
        'card.blocker.open' => 'card_has_open_blocker',
        'card.children.exist' => 'card_has_children',
        'card.children.finished' => 'card_children_finished',
        'card.children.merged_into_epic_branch' => 'card_child_merged_into_epic_branch',
        'card.children.marked' => 'card_children_marked',
        'card.discovery.approved' => 'card_discovery_approved',
        'card.discovery.requested' => 'card_discovery_requested',
        'card.discovery.revising' => 'card_discovery_revising',
        'card.follows.finished' => 'card_follows_finished',
        'card.document.approved' => 'card_document_approved',
        'card.document.changes_requested' => 'card_document_changes_requested',
        'card.document.linked' => 'card_document',
        'card.parent.document.approved' => 'parent_document_approved',
        'card.parent.exists' => 'card_is_child',
        'card.parent.in_slot' => 'parent_in_slot',
        'card.parent.run.active' => 'parent_work_active',
        'card.pr.all_closed_unmerged' => 'pr_all_closed_unmerged',
        'card.pr.all_finished_one_merged' => 'pr_all_finished_one_merged',
        'card.pr.draft_open' => 'pr_draft_open',
        'card.pr.linked' => 'pr_linked',
        'card.run.done_at_head' => 'run_done_at_head',
        'card.run.finished_with' => 'run_finished_with',
        'card.run.last_refusal' => 'run_last_refusal',
        'card.run.work_active' => 'run_work_active',
        'card.run.work_no_capable_taker' => 'run_work_no_capable_taker',
        'card.run.work_no_taker' => 'run_work_no_taker',
        'card.run.work_open' => 'run_work_open',
        'card.run.work_taker_paused' => 'run_work_taker_paused',
        'card.run.worker_active' => 'run_worker_active',
        'card.site_review.check_stale' => 'site_review_check_stale',
        'card.site_review.verdict_unsent' => 'site_review_verdict_unsent',
    ];

    public function test_the_container_registers_exactly_the_catalogue(): void
    {
        $keys = self::catalogueKeys(static::getContainer()->get(Conditions::class));
        sort($keys);

        self::assertSame(array_keys(self::SOURCES), $keys);
    }

    public function test_each_condition_has_a_plain_and_a_negated_english_waiting_sentence(): void
    {
        $conditions = static::getContainer()->get(Conditions::class);
        $translator = static::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue('en');

        foreach (self::catalogueKeys($conditions) as $key) {
            $suffix = self::WAITING_SUFFIXES[$key] ?? str_replace('.', '_', $key);
            foreach (['workflow.waiting.'.$suffix, 'workflow.waiting.not.'.$suffix] as $message) {
                self::assertTrue($catalogue->defines($message), \sprintf('"%s" has no English string.', $message));
            }
        }
    }

    public function test_each_condition_names_the_module_whose_data_it_reads(): void
    {
        $conditions = static::getContainer()->get(Conditions::class);
        $translator = static::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue('en');

        foreach (self::catalogueKeys($conditions) as $key) {
            $source = $conditions->get($key)::source();
            self::assertSame(self::SOURCES[$key], $source, $key);
            self::assertTrue($catalogue->defines($source), \sprintf('"%s" has no English string.', $source));
        }
    }

    public function test_the_workflows_guide_names_each_condition_that_a_marker_reads(): void
    {
        $conditions = static::getContainer()->get(Conditions::class);
        $guide = (string) file_get_contents(\dirname(__DIR__, 4).'/docs/using/workflows.md');

        foreach (self::catalogueKeys($conditions) as $key) {
            if ($conditions->get($key) instanceof MarkerEvidence) {
                self::assertStringContainsString('`'.$key.'`', $guide, \sprintf('docs/using/workflows.md does not name "%s".', $key));
            }
        }
    }

    public function test_each_marker_condition_that_reads_the_pull_request_names_the_pull_request_as_its_subject(): void
    {
        $conditions = static::getContainer()->get(Conditions::class);

        foreach (self::catalogueKeys($conditions) as $key) {
            $condition = $conditions->get($key);
            if ($condition instanceof MarkerEvidence && array_any($condition->reads([]), static fn (EngineFact|string $key): bool => $key instanceof EngineFact && $key->bindsPullRequest())) {
                self::assertInstanceOf(NamesMarkerSubject::class, $condition, \sprintf('"%s" gives pull request evidence and names no subject type.', $key));
                self::assertSame(MarkerSubjectType::PullRequest, $condition->subjectType(), $key);
            }
        }
    }

    public function test_a_document_status_reads_as_its_label_in_the_waiting_sentence(): void
    {
        $translator = static::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        $params = ['tag' => 'design', 'status' => 'in-review'];

        self::assertSame('Waiting: the card has no design document with the status "In review".', new CardDocument()->waitingFor($params)->trans($translator));
        self::assertSame('Waiting: the card has a design document with the status "In review".', new CardDocument()->waitingFor($params, negated: true)->trans($translator));
    }

    public function test_the_follow_up_waiting_sentences_name_the_cases_in_which_each_side_waits(): void
    {
        $translator = static::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        self::assertSame('Waiting: the card follows no card, or a card it follows is still open.', new CardFollowsFinished()->waitingFor([])->trans($translator));
        self::assertSame('Waiting: every card that this card follows is finished.', new CardFollowsFinished()->waitingFor([], negated: true)->trans($translator));
    }

    /** @return list<string> the keys of the shipped conditions, without the ones the test container adds */
    private static function catalogueKeys(Conditions $conditions): array
    {
        return array_values(array_filter($conditions->keys(), static fn (string $key): bool => !str_starts_with($key, 'test.')));
    }
}
