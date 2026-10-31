<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Template;

use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Workflow\AgentReviewFacts;
use App\Module\AgentReview\Workflow\AgentReviewPostingFacts;
use App\Module\AgentReview\Workflow\DocumentReviewFacts;
use App\Module\AgentReview\Workflow\DocumentReviewState;
use App\Module\AgentReview\Workflow\ReviewedHead;
use App\Module\Board\Workflow\CardValueFacts;
use App\Module\Board\Workflow\EpicBranchSyncFacts;
use App\Module\Board\Workflow\FixRunFacts;
use App\Module\Board\Workflow\HumanReviewFacts;
use App\Module\Board\Workflow\StaleApprovalFacts;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\Workflow\SessionFacts;
use App\Module\Bridge\Workflow\WorkerRunFacts;
use App\Module\Bridge\Workflow\WorkRequestFacts;
use App\Module\Inbox\Workflow\WaitingAskFacts;
use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Contract\ChecksState;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerSubjectType;
use App\Module\Workflow\Contract\PullRequestState;
use App\Module\Workflow\Contract\RepositorySuggestion;
use App\Module\Workflow\Contract\RepositorySuggestionKey;
use App\Module\Workflow\Contract\SuggestionMark;
use App\Module\Workflow\Engine\RuleSubject;
use App\Module\Workflow\Template\ActionCall;
use App\Module\Workflow\Template\ManualMoveActor;
use App\Module\Workflow\Template\MarkerSubjectClassifier;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateParser;
use App\Module\Workflow\Template\UnknownTemplate;
use App\Tests\Module\Workflow\Fact\FactsMother;
use App\Tests\Module\Workflow\Fact\RunInputs;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Component\Uid\Uuid;

final class ShippedTemplatesTest extends KernelTestCase
{
    public function test_it_lists_the_shipped_templates(): void
    {
        self::assertSame(['lifecycle', 'simple'], $this->shipped()->keys());
    }

    #[DataProvider('unknownKeys')]
    public function test_it_refuses_a_key_that_is_not_shipped(string $key): void
    {
        try {
            $this->shipped()->source($key);
            self::fail('An unknown key must throw.');
        } catch (UnknownTemplate $e) {
            self::assertSame($key, $e->key);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unknownKeys(): iterable
    {
        yield 'unknown' => ['kanban'];
        yield 'path' => ['../workflows/lifecycle'];
    }

    public function test_both_templates_pass_the_parser(): void
    {
        foreach ($this->shipped()->keys() as $key) {
            self::assertSame($key, $this->template($key)->key);
        }
    }

    public function test_each_template_suggests_its_repository_settings(): void
    {
        self::assertEquals([
            new RepositorySuggestion(RepositorySuggestionKey::ApprovalGate, SuggestionMark::Blocking),
            new RepositorySuggestion(RepositorySuggestionKey::CiChecks, SuggestionMark::Warning),
            new RepositorySuggestion(RepositorySuggestionKey::BranchProtection, SuggestionMark::Warning),
            new RepositorySuggestion(RepositorySuggestionKey::MergeMethod, SuggestionMark::Blocking),
        ], $this->template('lifecycle')->repositorySettings);
        self::assertEquals([new RepositorySuggestion(RepositorySuggestionKey::BranchProtection, SuggestionMark::Warning)], $this->template('simple')->repositorySettings);
    }

    public function test_each_template_lists_its_inbox_markers_and_each_one_names_one_thing(): void
    {
        $expected = [
            'lifecycle' => [
                'waits-for-approval' => MarkerSubjectType::PullRequest,
                'document-in-review' => MarkerSubjectType::Document,
                'run-needs-you' => MarkerSubjectType::WorkerRun,
                'paused' => MarkerSubjectType::CardPause,
                'repository-misfit' => null,
            ],
            'simple' => [
                'waits-for-approval' => MarkerSubjectType::PullRequest,
                'run-needs-you' => MarkerSubjectType::WorkerRun,
                'paused' => MarkerSubjectType::CardPause,
            ],
        ];

        foreach ($expected as $key => $subjects) {
            $template = $this->template($key);
            self::assertSame(array_keys($subjects), $template->inbox, $key);
            foreach ($template->markers as $marker) {
                if (\array_key_exists($marker->key, $subjects)) {
                    self::assertSame($subjects[$marker->key], MarkerSubjectClassifier::of($marker->when), $key.' '.$marker->key);
                }
            }
        }
    }

    public function test_both_templates_declare_the_card_types_and_only_an_epic_has_children_and_a_lane(): void
    {
        foreach ($this->shipped()->keys() as $key) {
            $template = $this->template($key);
            self::assertSame(['feature', 'bug', 'security', 'tooling', 'docs', 'idea', 'epic'], array_map(static fn ($type) => $type->key, $template->types));
            self::assertSame('feature', $template->defaultType);
            foreach ($template->types as $type) {
                self::assertSame('epic' === $type->key, $type->children);
                self::assertSame('epic' === $type->key, $type->lane);
                self::assertSame('board.card.type.'.$type->key, $type->label);
            }
        }
    }

    public function test_simple_has_no_slot_and_allows_every_manual_move(): void
    {
        $simple = $this->template('simple');

        self::assertSame([], $simple->slots);
        self::assertCount(1, $simple->manualMoves);
        self::assertSame('*', $simple->manualMoves[0]->from);
        self::assertSame('*', $simple->manualMoves[0]->to);
        self::assertSame(['merged'], array_map(static fn ($rule) => $rule->id, $simple->rulesFor(null)));
    }

    public function test_the_merged_rule_of_each_shipped_template_moves_with_the_merged_outcome(): void
    {
        foreach (['lifecycle', 'simple'] as $key) {
            $rule = array_find($this->template($key)->rules, static fn ($rule): bool => 'merged' === $rule->id) ?? throw new \LogicException($key);

            self::assertSame('move', $rule->lead->key);
            self::assertSame(['to' => '@terminal', 'outcome' => 'merged'], $rule->lead->params, $key);
        }
    }

    public function test_the_lifecycle_design_review_tags_are_the_product_and_tech_design_tags(): void
    {
        self::assertSame(['product-design', 'tech-design'], $this->lifecycle()->designReviewTags());
        self::assertSame([], $this->template('simple')->designReviewTags());
    }

    public function test_every_slot_label_has_an_english_string(): void
    {
        $translator = static::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue('en');

        $labels = [];
        foreach ($this->shipped()->keys() as $key) {
            foreach ($this->template($key)->slots as $slot) {
                $labels[] = $slot->label;
            }
        }

        self::assertNotEmpty($labels);
        foreach ($labels as $label) {
            self::assertTrue($catalogue->defines($label), \sprintf('"%s" has no English string.', $label));
        }
    }

    public function test_an_approved_tech_design_with_no_blocker_moves_to_implementation(): void
    {
        $facts = FactsMother::facts(card: FactsMother::card(slot: 'tech-design', documents: [self::approved('tech-design')]));

        self::assertContainsEquals($this->call('move', ['to' => 'implementation']), $this->actions($facts));
    }

    public function test_an_approved_tech_design_with_an_open_blocker_does_not_move(): void
    {
        $facts = FactsMother::facts(card: FactsMother::card(slot: 'tech-design', hasOpenBlocker: true, documents: [self::approved('tech-design')]));

        $actions = $this->actions($facts);
        self::assertNotEmpty($this->lifecycle()->rulesFor('tech-design'));
        self::assertSame([], array_values(array_filter($actions, static fn (ActionCall $action): bool => 'move' === $action->key)));
    }

    public function test_an_approved_tech_design_with_a_closed_blocker_asks_for_a_recheck_and_does_not_move(): void
    {
        $facts = FactsMother::facts(card: FactsMother::card(slot: 'tech-design', documents: [self::approved('tech-design')], hasClosedBlocker: true));

        self::assertSame(['tech-design-recheck'], $this->firingRuleIds($facts));
        $requests = array_values(array_filter($this->actions($facts), static fn (ActionCall $action): bool => 'request' === $action->key));
        self::assertSame([['kind' => 'tech-design-recheck']], array_map(static fn (ActionCall $action): array => ['kind' => $action->params['kind']], $requests));
    }

    public function test_an_approved_tech_design_with_a_closed_blocker_moves_after_the_recheck_is_done(): void
    {
        $facts = FactsMother::facts(
            card: FactsMother::card(slot: 'tech-design', documents: [self::approved('tech-design')], hasClosedBlocker: true),
            run: FactsMother::run(latestWorkState: WorkRequestState::Done, latestWorkKind: 'tech-design-recheck', latestWorkReason: 'done'),
        );

        self::assertSame(['tech-design-approved'], $this->firingRuleIds($facts));
    }

    public function test_an_approved_tech_design_with_a_closed_and_an_open_blocker_neither_rechecks_nor_moves(): void
    {
        $facts = FactsMother::facts(card: FactsMother::card(slot: 'tech-design', hasOpenBlocker: true, documents: [self::approved('tech-design')], hasClosedBlocker: true));

        self::assertSame([], $this->firingRuleIds($facts));
    }

    public function test_the_product_design_session_waits_for_an_open_session_and_withdraws_only_an_open_request(): void
    {
        $rule = $this->ruleOf('product-design', 'product-design-session');
        $card = FactsMother::card(slot: 'product-design');
        $idle = FactsMother::facts(card: $card, provided: [SessionFacts::class => new SessionFacts(null, null)]);
        $open = FactsMother::facts(card: $card, provided: [SessionFacts::class => new SessionFacts(new \DateTimeImmutable('2026-10-01 11:00:00'), null)]);

        self::assertTrue($rule->when->evaluate($idle));
        self::assertFalse($rule->when->evaluate($open));
        self::assertNotNull($rule->lead->cancelWhen);
        self::assertFalse($rule->lead->cancelWhen->evaluate($idle));
        self::assertTrue($rule->lead->cancelWhen->evaluate($open));
        self::assertSame('open', $rule->lead->params['cancelOnly'] ?? null);
    }

    public function test_an_approved_product_design_asks_for_a_tech_design_and_does_not_move(): void
    {
        $facts = FactsMother::facts(card: FactsMother::card(slot: 'tech-design', documents: [self::approved('product-design')]));

        self::assertSame(['tech-design-write'], $this->firingRuleIds($facts));
        $requests = array_values(array_filter($this->actions($facts), static fn (ActionCall $action): bool => 'request' === $action->key));
        self::assertSame([['kind' => 'tech-design', 'cancelOnly' => 'open']], array_map(static fn (ActionCall $action): array => $action->params, $requests));
    }

    public function test_with_the_switch_on_and_no_design_the_workflow_asks_for_a_session_and_not_for_the_agent(): void
    {
        $facts = self::withSwitch(true);

        self::assertSame(['tech-design-session'], $this->firingRuleIds($facts));
        $requests = array_values(array_filter($this->actions($facts), static fn (ActionCall $action): bool => 'request' === $action->key));
        self::assertSame('tech-design-session', $requests[0]->params['kind']);
        self::assertSame('interactive', $requests[0]->params['capability']);
        self::assertSame('open', $requests[0]->params['cancelOnly']);
    }

    public function test_with_the_switch_off_the_workflow_asks_for_the_agent_and_not_for_a_session(): void
    {
        self::assertSame(['tech-design-write'], $this->firingRuleIds(self::withSwitch(false)));
        self::assertSame(['tech-design-write'], $this->firingRuleIds(self::withSwitch(null)));
    }

    public function test_the_switch_turning_on_while_a_rule_started_agent_works_stops_the_work_and_asks_for_no_session_yet(): void
    {
        foreach ([FactsMother::run(activeWorkKinds: ['tech-design']), FactsMother::run(activeWorkerKinds: ['tech-design'])] as $run) {
            $facts = self::withSwitch(true, run: $run);

            self::assertSame(['tech-design-handover'], $this->firingRuleIds($facts));
            self::assertSame(['stop-work'], array_map(static fn (ActionCall $action): string => $action->key, $this->actions($facts)));
        }
    }

    public function test_an_agent_that_a_person_sent_is_not_stopped_while_the_switch_is_on(): void
    {
        $facts = self::withSwitch(true, run: FactsMother::run(activeWorkKinds: ['tech-design'], activeWorkerKinds: ['tech-design']), provided: [
            WorkRequestFacts::class => new WorkRequestFacts(['tech-design'], [], [], []),
            WorkerRunFacts::class => new WorkerRunFacts(['tech-design'], [], [], []),
        ]);

        self::assertSame([], $this->firingRuleIds($facts));
    }

    public function test_an_open_session_stops_the_session_request_and_the_agent_request_and_withdraws_only_a_waiting_one(): void
    {
        $open = new SessionFacts(new \DateTimeImmutable('2026-10-01 11:00:00'), null);

        self::assertSame([], $this->firingRuleIds(self::withSwitch(true, provided: [SessionFacts::class => $open])));
        self::assertSame([], $this->firingRuleIds(self::withSwitch(false, provided: [SessionFacts::class => $open])));
        foreach (['tech-design-session', 'tech-design-write'] as $id) {
            $rule = $this->ruleOf('tech-design', $id);
            self::assertSame('open', $rule->lead->params['cancelOnly'] ?? null, $id);
            self::assertNotNull($rule->lead->cancelWhen, $id);
            self::assertTrue($rule->lead->cancelWhen->evaluate(self::withSwitch(true, provided: [SessionFacts::class => $open])), $id);
        }
    }

    public function test_turning_the_switch_off_withdraws_the_session_request(): void
    {
        $cancelWhen = $this->ruleOf('tech-design', 'tech-design-session')->lead->cancelWhen;

        self::assertNotNull($cancelWhen);
        self::assertFalse($cancelWhen->evaluate(self::withSwitch(true)));
        self::assertTrue($cancelWhen->evaluate(self::withSwitch(false)));
    }

    public function test_a_draft_design_with_the_switch_on_asks_for_neither_a_session_nor_the_agent(): void
    {
        $facts = FactsMother::facts(
            card: FactsMother::card(slot: 'tech-design', documents: [new DocumentFacts(['tech-design'], 'draft', 'doc')]),
            provided: [CardValueFacts::class => new CardValueFacts(['tech-design-interactive' => true])],
        );

        self::assertSame([], $this->firingRuleIds($facts));
    }

    /** @param array<class-string, object> $provided */
    private static function withSwitch(?bool $on, ?RunInputs $run = null, array $provided = []): Facts
    {
        return FactsMother::facts(
            card: FactsMother::card(slot: 'tech-design'),
            run: $run,
            provided: [CardValueFacts::class => new CardValueFacts(null === $on ? [] : ['tech-design-interactive' => $on]), ...$provided],
        );
    }

    public function test_an_approved_tech_design_next_to_an_approved_product_design_moves_to_implementation(): void
    {
        $facts = FactsMother::facts(card: FactsMother::card(slot: 'tech-design', documents: [self::approved('product-design'), self::approved('tech-design')]));

        self::assertSame(['tech-design-approved'], $this->firingRuleIds($facts));
        self::assertContainsEquals($this->call('move', ['to' => 'implementation']), $this->actions($facts));
    }

    public function test_a_reviewable_pull_request_moves_to_in_review(): void
    {
        $facts = FactsMother::facts(
            card: FactsMother::card(slot: 'implementation'),
            pullRequest: FactsMother::pullRequest(checks: ChecksState::Passed),
        );

        self::assertContainsEquals($this->call('move', ['to' => 'in-review']), $this->actions($facts));
    }

    public function test_a_card_with_a_draft_beside_a_ready_pull_request_does_not_move_to_in_review_in_implementation(): void
    {
        $ready = FactsMother::pullRequest(checks: ChecksState::Passed);
        $draft = FactsMother::pullRequest(draft: true);
        $facts = FactsMother::facts(card: FactsMother::card(slot: 'implementation'), pullRequest: $ready, pullRequests: [$ready, $draft]);

        self::assertNotContainsEquals($this->call('move', ['to' => 'in-review']), $this->actions($facts));
    }

    public function test_a_card_in_in_review_with_a_draft_beside_a_ready_pull_request_goes_back_to_implementation(): void
    {
        $ready = FactsMother::pullRequest(checks: ChecksState::Passed);
        $draft = FactsMother::pullRequest(draft: true);
        $facts = FactsMother::facts(card: FactsMother::card(slot: 'in-review'), pullRequest: $draft, pullRequests: [$ready, $draft]);

        self::assertContainsEquals($this->call('move', ['to' => 'implementation']), $this->actions($facts));
    }

    public function test_a_card_whose_remaining_pull_request_is_a_reviewed_draft_gets_it_marked_ready_in_implementation_and_moves_after(): void
    {
        $draft = FactsMother::pullRequest(draft: true, checks: ChecksState::Passed);
        $facts = FactsMother::facts(card: FactsMother::card(slot: 'implementation'), pullRequest: $draft, pullRequests: [$draft]);

        self::assertContains('review-ready', $this->firingRuleIds($facts));
        self::assertNotContainsEquals($this->call('move', ['to' => 'in-review']), $this->actions($facts));

        $ready = FactsMother::pullRequest(checks: ChecksState::Passed);
        $after = FactsMother::facts(card: FactsMother::card(slot: 'implementation'), pullRequest: $ready, pullRequests: [$ready]);
        self::assertContainsEquals($this->call('move', ['to' => 'in-review']), $this->actions($after));
    }

    /** @return iterable<string, array{string}> */
    public static function reviewSlots(): iterable
    {
        yield 'implementation' => ['implementation'];
        yield 'in review' => ['in-review'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function designSlots(): iterable
    {
        yield 'product design' => ['product-design', 'product-design'];
        yield 'tech design' => ['tech-design', 'tech-design'];
    }

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function publishedTags(): iterable
    {
        yield 'product design' => ['product-design', 'product-design', ['product-design']];
        yield 'tech design' => ['tech-design', 'tech-design', ['tech-design', 'deploy-notes']];
    }

    #[DataProvider('designSlots')]
    public function test_a_draft_design_with_no_review_asks_for_one_that_only_an_owner_action_cancels(string $slot, string $tag): void
    {
        $facts = self::withDesignReview($slot, $tag, new DocumentReviewState('doc', [$tag], 1, null, null, null, false, false, 0));
        $rule = $this->ruleOf($slot, $slot.'-review');

        self::assertTrue($rule->when->evaluate($facts));
        self::assertSame(['kind' => 'design-review', 'role' => 'design-review'], \array_slice($rule->lead->params, 0, 2));
        self::assertSame('retry', $rule->lead->params['stillTrue']);
        self::assertSame($tag, $rule->lead->params['document.tag']);
        self::assertSame('draft', $rule->lead->params['document.status']);
        self::assertNotNull($rule->lead->cancelWhen);
        self::assertFalse($rule->lead->cancelWhen->evaluate($facts));
        self::assertTrue($rule->lead->cancelWhen->evaluate(FactsMother::facts(card: FactsMother::card(slot: $slot, documents: [new DocumentFacts([$tag], 'in-review', 'doc')]))));
    }

    /** @return iterable<string, array{string, string, list<string>, bool}> */
    public static function reviewWaits(): iterable
    {
        yield 'product design, session open' => ['product-design', 'product-design', ['session'], false];
        yield 'tech design, session open' => ['tech-design', 'tech-design', ['session'], false];
        yield 'design work runs' => ['tech-design', 'tech-design', ['tech-design'], false];
        yield 'revise work runs' => ['tech-design', 'tech-design', ['tech-design-revise'], false];
        yield 'revise worker runs' => ['product-design', 'product-design', ['worker:product-design-revise'], false];
        yield 'review work runs' => ['product-design', 'product-design', ['design-review'], true];
    }

    /** @param list<string> $active */
    #[DataProvider('reviewWaits')]
    public function test_the_design_review_waits_while_the_writer_or_a_session_is_active(string $slot, string $tag, array $active, bool $asks): void
    {
        $work = [];
        $workers = [];
        foreach ($active as $kind) {
            str_starts_with($kind, 'worker:') ? $workers[] = substr($kind, 7) : $work[] = $kind;
        }
        $facts = self::withDesignReview($slot, $tag, new DocumentReviewState('doc', [$tag], 1, null, null, null, false, false, 0), $work, $workers, \in_array('session', $active, true));

        self::assertSame($asks, $this->ruleOf($slot, $slot.'-review')->when->evaluate($facts));
    }

    #[DataProvider('designSlots')]
    public function test_a_failed_design_review_asks_for_a_revision_with_its_reason_and_limit(string $slot, string $tag): void
    {
        $facts = self::withDesignReview($slot, $tag, new DocumentReviewState('doc', [$tag], 1, 'review', 1, AgentReviewConclusion::Failure, false, false, 1));
        $rule = $this->ruleOf($slot, $slot.'-review-fix');

        self::assertTrue($rule->when->evaluate($facts));
        self::assertFalse($this->ruleOf($slot, $slot.'-review')->when->evaluate($facts));
        self::assertSame(['kind' => $tag.'-revise', 'role' => 'design-revise', 'reason' => 'agent-review', 'limit' => 6], \array_slice($rule->lead->params, 0, 4));
        self::assertNotNull($rule->lead->cancelWhen);
    }

    /** @param list<string> $tags */
    #[DataProvider('publishedTags')]
    public function test_a_finished_design_review_publishes_the_drafts_once_nothing_runs(string $slot, string $tag, array $tags): void
    {
        $finished = new DocumentReviewState('doc', [$tag], 1, 'review', 1, AgentReviewConclusion::Success, false, false, 0);
        $rule = $this->ruleOf($slot, $slot.'-review-publish');

        self::assertTrue($rule->when->evaluate(self::withDesignReview($slot, $tag, $finished)));
        self::assertFalse($rule->when->evaluate(self::withDesignReview($slot, $tag, $finished, ['fix'])));
        self::assertFalse($rule->when->evaluate(self::withDesignReview($slot, $tag, $finished, [], ['fix'])));
        self::assertSame('publish-documents', $rule->lead->key);
        self::assertSame(['tags' => $tags], $rule->lead->lists);
    }

    public function test_the_design_review_rules_come_before_the_move_of_their_slot(): void
    {
        foreach (['product-design' => 'product-design-approved', 'tech-design' => 'tech-design-recheck'] as $slot => $next) {
            $ids = array_map(static fn ($rule): string => $rule->id, $this->lifecycle()->rulesFor($slot));

            foreach (['-review', '-review-fix', '-review-publish'] as $suffix) {
                self::assertLessThan(array_search($next, $ids, true), array_search($slot.$suffix, $ids, true));
            }
        }
    }

    #[DataProvider('designSlots')]
    public function test_the_auto_approve_rule_follows_the_publish_rule_and_precedes_the_rules_that_move_the_card(string $slot, string $tag): void
    {
        $ids = array_map(static fn ($rule): string => $rule->id, $this->lifecycle()->rulesFor($slot));
        $position = array_search($slot.'-auto-approve', $ids, true);

        self::assertSame(array_search($slot.'-review-publish', $ids, true) + 1, $position);
        self::assertLessThan(array_search($slot.'-approved', $ids, true), $position);
        $rule = $this->ruleOf($slot, $slot.'-auto-approve');
        self::assertSame('approve-document', $rule->lead->key);
        self::assertSame(['tag' => $tag], $rule->lead->params);
        $keys = array_map(static fn ($leaf): string => $leaf->condition::key(), $rule->when->leaves());
        self::assertSame(['card.document.in_review', 'card.document.published_in_column', 'card.document.entry_decisions_open', 'card.document.entry_threads_open', 'card.document.rejected_before', 'design_review.passed'], $keys);
    }

    /**
     * @param list<string> $activeWorkKinds
     * @param list<string> $activeWorkerKinds
     */
    private static function withDesignReview(string $slot, string $tag, DocumentReviewState $state, array $activeWorkKinds = [], array $activeWorkerKinds = [], bool $session = false): Facts
    {
        return FactsMother::facts(
            card: FactsMother::card(slot: $slot, documents: [new DocumentFacts([$tag], 'draft', 'doc')]),
            run: FactsMother::run(activeWorkKinds: $activeWorkKinds, activeWorkerKinds: $activeWorkerKinds),
            provided: [DocumentReviewFacts::class => new DocumentReviewFacts([$state]), SessionFacts::class => new SessionFacts($session ? new \DateTimeImmutable('2026-10-01 11:00:00') : null, null)],
        );
    }

    private function ruleOf(string $slot, string $id): Rule
    {
        return array_find($this->lifecycle()->rulesFor($slot), static fn ($rule): bool => $id === $rule->id) ?? throw new \LogicException($id);
    }

    #[DataProvider('reviewSlots')]
    public function test_a_head_with_no_agent_review_asks_for_one(string $slot): void
    {
        $facts = self::withAgentReview($slot, [null]);
        $suffix = 'in-review' === $slot ? '-in-review' : '';

        self::assertContains('agent-review'.$suffix, $this->firingRuleIds($facts));
        self::assertNotContains('fix-agent-review'.$suffix, $this->firingRuleIds($facts));
    }

    #[DataProvider('reviewSlots')]
    public function test_a_fix_rule_waits_while_a_session_of_the_card_waits_for_an_answer(string $slot): void
    {
        $suffix = 'in-review' === $slot ? '-in-review' : '';
        $waiting = ['0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7'];
        $checksRule = 'in-review' === $slot ? 'fix-in-review' : 'fix-in-implementation';

        self::assertContains($checksRule, $this->firingRuleIds(self::withFailedChecks($slot, [])));
        self::assertNotContains($checksRule, $this->firingRuleIds(self::withFailedChecks($slot, $waiting)));
        self::assertContains('fix-agent-review'.$suffix, $this->firingRuleIds(self::withAgentReview($slot, [AgentReviewConclusion::Failure])));
        self::assertNotContains('fix-agent-review'.$suffix, $this->firingRuleIds(self::withAgentReview($slot, [AgentReviewConclusion::Failure], waitingAskIds: $waiting)));
    }

    /** @return iterable<string, array{string, list<string>, bool}> */
    public static function activeWorkDuringReview(): iterable
    {
        foreach (['implementation', 'in-review'] as $slot) {
            yield $slot.', implement work runs' => [$slot, ['implement'], false];
            yield $slot.', fix work runs' => [$slot, ['fix'], false];
            yield $slot.', sync work runs' => [$slot, ['sync'], false];
            yield $slot.', rebase work runs' => [$slot, ['rebase-stacked'], false];
            yield $slot.', its own review runs' => [$slot, ['review'], true];
            yield $slot.', other work runs' => [$slot, ['merge'], true];
        }
    }

    public function test_every_review_revision_and_fix_rule_names_its_role(): void
    {
        $roles = [];
        foreach ($this->lifecycle()->rules as $rule) {
            $role = $rule->lead->params['role'] ?? null;
            if (null !== $role) {
                $roles[$rule->id] = $role;
            }
        }

        self::assertSame([
            'product-design-revise' => 'design-revise',
            'product-design-review' => 'design-review',
            'product-design-review-fix' => 'design-revise',
            'tech-design-revise' => 'design-revise',
            'tech-design-review' => 'design-review',
            'tech-design-review-fix' => 'design-revise',
            'fix-in-implementation' => 'fix',
            'agent-review' => 'code-review',
            'fix-agent-review' => 'fix',
            'fix-in-review' => 'fix',
            'agent-review-in-review' => 'code-review',
            'fix-agent-review-in-review' => 'fix',
        ], $roles);
    }

    /** @param list<string> $activeWorkKinds */
    #[DataProvider('activeWorkDuringReview')]
    public function test_a_head_waits_for_its_review_until_work_that_pushes_to_its_branch_ends(string $slot, array $activeWorkKinds, bool $asks): void
    {
        $facts = self::withAgentReview($slot, [null], activeWorkKinds: $activeWorkKinds);
        $ruleId = 'agent-review'.('in-review' === $slot ? '-in-review' : '');
        $rule = array_find($this->lifecycle()->rulesFor($slot), static fn ($rule): bool => $ruleId === $rule->id);

        self::assertNotNull($rule);
        self::assertSame($asks, $rule->when->evaluate($facts));
    }

    #[DataProvider('reviewSlots')]
    public function test_a_failed_agent_review_asks_for_a_fix_with_its_own_reason_and_limit(string $slot): void
    {
        $facts = self::withAgentReview($slot, [AgentReviewConclusion::Failure]);
        $suffix = 'in-review' === $slot ? '-in-review' : '';
        $rule = array_find($this->lifecycle()->rulesFor($slot), static fn ($rule): bool => 'fix-agent-review'.$suffix === $rule->id);

        self::assertNotNull($rule);
        self::assertTrue($rule->when->evaluate($facts));
        self::assertSame(['kind' => 'fix', 'role' => 'fix', 'reason' => 'agent-review', 'limit' => 10], \array_slice($rule->lead->params, 0, 4));
        self::assertNotContains('agent-review'.$suffix, $this->firingRuleIds($facts));
    }

    /** @return iterable<string, array{string, AgentReviewConclusion|null}> */
    public static function boundAgentReviewRules(): iterable
    {
        yield 'review' => ['agent-review', null];
        yield 'fix' => ['fix-agent-review', AgentReviewConclusion::Failure];
    }

    #[DataProvider('boundAgentReviewRules')]
    public function test_an_agent_review_rule_acts_on_the_pull_request_whose_head_needs_it(string $ruleId, ?AgentReviewConclusion $conclusion): void
    {
        $passed = FactsMother::pullRequest(checks: ChecksState::Passed, id: Uuid::v7());
        $needing = FactsMother::pullRequest(checks: ChecksState::Passed, id: Uuid::v7());
        $facts = FactsMother::facts(
            card: FactsMother::card(slot: 'implementation'),
            pullRequest: $passed,
            pullRequests: [$passed, $needing],
            provided: [AgentReviewFacts::class => new AgentReviewFacts([
                new ReviewedHead((string) $passed->id, str_repeat('a', 40), AgentReviewConclusion::Success),
                new ReviewedHead((string) $needing->id, str_repeat('b', 40), $conclusion),
            ]), AgentReviewPostingFacts::class => new AgentReviewPostingFacts(unposted: false, uncommented: [])],
        );
        $rule = array_find($this->lifecycle()->rulesFor('implementation'), static fn ($rule): bool => $ruleId === $rule->id);
        self::assertNotNull($rule);

        $bound = new RuleSubject()->bind($rule, $facts);

        self::assertTrue($bound->truth);
        self::assertEquals($needing->id, $bound->subject);
    }

    public function test_a_draft_that_passed_the_agent_review_is_marked_ready(): void
    {
        $passed = self::withAgentReview('implementation', [AgentReviewConclusion::Success], draft: true);
        $pending = self::withAgentReview('implementation', [null], draft: true);
        $epic = self::withAgentReview('implementation', [AgentReviewConclusion::Success], draft: true, epic: true);
        $changesRequested = self::withAgentReview('implementation', [AgentReviewConclusion::Success], draft: true, changesRequested: true);
        $ready = $this->call('forge-write', ['write' => 'review-ready']);

        self::assertContainsEquals($ready, $this->actions($passed));
        self::assertNotContainsEquals($ready, $this->actions($pending));
        self::assertNotContainsEquals($ready, $this->actions($epic));
        self::assertNotContainsEquals($ready, $this->actions($changesRequested));
    }

    public function test_a_card_waits_in_implementation_and_in_review_until_the_agent_review_passed(): void
    {
        $toReview = $this->call('move', ['to' => 'in-review']);
        $merge = $this->mergeWrite();

        self::assertNotContainsEquals($toReview, $this->actions(self::withAgentReview('implementation', [null])));
        self::assertNotContainsEquals($toReview, $this->actions(self::withAgentReview('implementation', [AgentReviewConclusion::Failure])));
        self::assertContainsEquals($toReview, $this->actions(self::withAgentReview('implementation', [AgentReviewConclusion::Success])));
        self::assertNotContainsEquals($merge, $this->actions(self::withAgentReview('in-review', [null], approved: true)));
        self::assertContainsEquals($merge, $this->actions(self::withAgentReview('in-review', [AgentReviewConclusion::Success], approved: true)));
    }

    public function test_an_approval_that_overrides_a_failed_agent_review_lets_the_card_go_on(): void
    {
        $toReview = $this->call('move', ['to' => 'in-review']);
        $ready = $this->call('forge-write', ['write' => 'review-ready']);
        $merge = $this->mergeWrite();

        $implementation = self::withAgentReview('implementation', [AgentReviewConclusion::Failure], overriddenBy: 'ubermuda');
        self::assertContainsEquals($toReview, $this->actions($implementation));
        self::assertContainsEquals($ready, $this->actions(self::withAgentReview('implementation', [AgentReviewConclusion::Failure], draft: true, overriddenBy: 'ubermuda')));
        self::assertContainsEquals($merge, $this->actions(self::withAgentReview('in-review', [AgentReviewConclusion::Failure], approved: true, overriddenBy: 'ubermuda')));
        self::assertNotContainsEquals($merge, $this->actions(self::withAgentReview('in-review', [AgentReviewConclusion::Failure], approved: true)));
        $fixes = array_filter($this->actions($implementation), static fn (ActionCall $action): bool => 'request' === $action->key && 'fix' === ($action->params['kind'] ?? null));
        self::assertSame([], array_values($fixes));
    }

    public function test_an_epic_child_waits_for_the_agent_review_before_it_merges_into_the_epic_branch(): void
    {
        $merge = $this->mergeWrite();

        self::assertNotContainsEquals($merge, $this->actions(self::withAgentReview('in-review', [null], epicBranch: true)));
        self::assertContainsEquals($merge, $this->actions(self::withAgentReview('in-review', [AgentReviewConclusion::Success], epicBranch: true)));
    }

    /** @param list<string> $waitingAskIds */
    private static function withFailedChecks(string $slot, array $waitingAskIds): Facts
    {
        return FactsMother::facts(
            card: FactsMother::card(slot: $slot),
            pullRequest: FactsMother::pullRequest(checks: ChecksState::Failed),
            provided: [WaitingAskFacts::class => new WaitingAskFacts($waitingAskIds)],
        );
    }

    /**
     * @param list<AgentReviewConclusion|null> $conclusions     the newest review conclusion of each open pull request head
     * @param list<string>                     $activeWorkKinds
     * @param list<string>                     $waitingAskIds
     */
    private static function withAgentReview(string $slot, array $conclusions, bool $draft = false, bool $epic = false, bool $approved = false, bool $ready = false, bool $epicBranch = false, bool $changesRequested = false, array $activeWorkKinds = [], ?string $overriddenBy = null, array $waitingAskIds = [], int $openChildren = 0): Facts
    {
        $id = Uuid::v7();
        $heads = array_map(static fn (?AgentReviewConclusion $conclusion): ReviewedHead => new ReviewedHead((string) $id, str_repeat('a', 40), $conclusion, overriddenBy: $overriddenBy), $conclusions);

        return FactsMother::facts(
            card: FactsMother::card(slot: $slot, type: $epic ? 'epic' : 'feature', childCount: $openChildren, openChildCount: $openChildren),
            pullRequest: FactsMother::pullRequest(draft: $draft, checks: ChecksState::Passed, changesRequested: $changesRequested, baseIsMergeTarget: !$epicBranch, baseIsEpicBranch: $epicBranch, id: $id, checksOnHead: $ready, baseIsDefault: $ready && !$epicBranch, approvalCheckPassed: $approved, canPostCheck: $ready),
            run: FactsMother::run(activeWorkKinds: $activeWorkKinds),
            provided: [AgentReviewFacts::class => new AgentReviewFacts($heads), AgentReviewPostingFacts::class => new AgentReviewPostingFacts(unposted: false, uncommented: []), WaitingAskFacts::class => new WaitingAskFacts($waitingAskIds)],
        );
    }

    #[DataProvider('reviewSlots')]
    public function test_an_epic_pull_request_asks_for_an_agent_review(string $slot): void
    {
        $suffix = 'in-review' === $slot ? '-in-review' : '';

        self::assertContains('agent-review'.$suffix, $this->firingRuleIds(self::withAgentReview($slot, [null], epic: true)));
        self::assertNotContains('agent-review'.$suffix, $this->firingRuleIds(self::withAgentReview($slot, [AgentReviewConclusion::Success], epic: true)));
        self::assertContains('fix-agent-review'.$suffix, $this->firingRuleIds(self::withAgentReview($slot, [AgentReviewConclusion::Failure], epic: true)));
    }

    public function test_an_epic_with_an_open_child_asks_for_no_agent_review_and_no_fix_round(): void
    {
        self::assertNotContains('agent-review', $this->firingRuleIds(self::withAgentReview('implementation', [null], epic: true, openChildren: 1)));
        self::assertNotContains('fix-agent-review', $this->firingRuleIds(self::withAgentReview('implementation', [AgentReviewConclusion::Failure], epic: true, openChildren: 1)));
    }

    public function test_an_epic_waits_for_its_own_agent_review_before_it_gets_the_approval_check_or_merges(): void
    {
        $merge = $this->mergeWrite();
        $pass = $this->call('forge-write', ['write' => 'approval-check', 'state' => 'pass']);
        $epic = static fn (?AgentReviewConclusion $conclusion, bool $approved): Facts => self::withAgentReview('in-review', [$conclusion], epic: true, approved: $approved, ready: true);

        self::assertNotContainsEquals($pass, $this->actions($epic(null, false)));
        self::assertNotContainsEquals($pass, $this->actions($epic(AgentReviewConclusion::Failure, false)));
        self::assertContainsEquals($pass, $this->actions($epic(AgentReviewConclusion::Success, false)));
        self::assertNotContainsEquals($merge, $this->actions($epic(null, true)));
        self::assertContainsEquals($merge, $this->actions($epic(AgentReviewConclusion::Success, true)));
    }

    public function test_an_epic_in_review_with_a_draft_pull_request_still_skips_the_back_to_draft_rule(): void
    {
        self::assertNotContains('back-to-draft', $this->firingRuleIds(self::withAgentReview('in-review', [null], draft: true, epic: true)));
        self::assertContains('back-to-draft', $this->firingRuleIds(self::withAgentReview('in-review', [null], draft: true)));
    }

    public function test_the_revoke_rule_has_no_slot_and_the_pass_rules_sit_in_review(): void
    {
        $rules = array_column($this->lifecycle()->rules, null, 'id');

        self::assertNull($rules['approval-check-revoke']->slot);
        self::assertEquals($this->call('forge-write', ['write' => 'approval-check', 'state' => 'revoke']), $rules['approval-check-revoke']->lead);
        foreach (['approval-check-auto', 'approval-check-person'] as $id) {
            self::assertSame('in-review', $rules[$id]->slot, $id);
            self::assertEquals($this->call('forge-write', ['write' => 'approval-check', 'state' => 'pass']), $rules[$id]->lead, $id);
        }
        foreach (['pull-request-auto-approve', 'dismiss-app-approval'] as $removed) {
            self::assertArrayNotHasKey($removed, $rules);
        }
    }

    public function test_the_revoke_rule_fires_in_any_column_only_for_a_passed_check_that_no_person_backs(): void
    {
        $passed = FactsMother::pullRequest(checks: ChecksState::Passed, approvalCheckPassed: true);
        $byPerson = FactsMother::pullRequest(checks: ChecksState::Passed, approvalsCoveringHead: 1, approvalCheckPassed: true);
        $needsPerson = [HumanReviewFacts::class => new HumanReviewFacts(true)];

        foreach (['implementation', 'in-review', 'next'] as $slot) {
            self::assertContains('approval-check-revoke', $this->firingRuleIds(FactsMother::facts(card: FactsMother::card(slot: $slot), pullRequest: $passed, pullRequests: [$passed], provided: $needsPerson)), $slot);
        }
        self::assertNotContains('approval-check-revoke', $this->firingRuleIds(FactsMother::facts(card: FactsMother::card(slot: 'in-review'), pullRequest: $passed, pullRequests: [$passed])));
        self::assertNotContains('approval-check-revoke', $this->firingRuleIds(FactsMother::facts(card: FactsMother::card(slot: 'in-review'), pullRequest: $byPerson, pullRequests: [$byPerson], provided: $needsPerson)));
        $failed = FactsMother::pullRequest(checks: ChecksState::Passed);
        self::assertNotContains('approval-check-revoke', $this->firingRuleIds(FactsMother::facts(card: FactsMother::card(slot: 'in-review'), pullRequest: $failed, pullRequests: [$failed], provided: $needsPerson)));
    }

    public function test_the_person_rule_needs_green_checks_on_the_head_and_the_default_base(): void
    {
        $approved = static fn (array $overrides = []): Facts => FactsMother::facts(
            card: FactsMother::card(slot: 'in-review'),
            pullRequest: FactsMother::pullRequest(...[...['checks' => ChecksState::Passed, 'approvalsCoveringHead' => 1, 'checksOnHead' => true, 'baseIsDefault' => true], ...$overrides]),
        );

        self::assertContains('approval-check-person', $this->firingRuleIds($approved()));
        self::assertNotContains('approval-check-person', $this->firingRuleIds($approved(['checks' => ChecksState::Pending])));
        self::assertNotContains('approval-check-person', $this->firingRuleIds($approved(['checksOnHead' => false])));
        self::assertNotContains('approval-check-person', $this->firingRuleIds($approved(['baseIsDefault' => false])));
        self::assertNotContains('approval-check-person', $this->firingRuleIds($approved(['draft' => true])));
        self::assertNotContains('approval-check-person', $this->firingRuleIds($approved(['approvalsCoveringHead' => 0])));
        self::assertNotContains('approval-check-person', $this->firingRuleIds($approved(['approvalCheckPassed' => true])));
    }

    public function test_a_behind_pull_request_updates_its_branch_once_the_approval_check_passed(): void
    {
        $update = $this->call('forge-write', ['write' => 'update-branch', 'fallback' => 'sync']);
        $behind = static fn (bool $passed, int $approvals): Facts => FactsMother::facts(
            card: FactsMother::card(slot: 'in-review'),
            pullRequest: FactsMother::pullRequest(checks: ChecksState::Passed, behind: true, approvalsCoveringHead: $approvals, approvalCheckPassed: $passed),
        );

        self::assertContainsEquals($update, $this->actions($behind(true, 0)));
        self::assertNotContainsEquals($update, $this->actions($behind(false, 1)));
    }

    public function test_the_merge_blocked_marker_shows_for_a_blocked_merge_after_the_approval_check_passed(): void
    {
        $marker = array_find($this->lifecycle()->markers, static fn ($marker): bool => 'merge-blocked' === $marker->key) ?? throw new \LogicException('merge-blocked');
        $facts = static fn (array $overrides = [], ?RunInputs $run = null): Facts => FactsMother::facts(
            card: FactsMother::card(slot: 'in-review'),
            pullRequest: FactsMother::pullRequest(...[...['checks' => ChecksState::Passed, 'approvalCheckPassed' => true, 'mergeBlocked' => true], ...$overrides]),
            run: $run ?? FactsMother::run(),
        );

        self::assertSame('needs-you', $marker->kind->value);
        self::assertSame(MarkerSubjectType::PullRequest, MarkerSubjectClassifier::of($marker->when));
        self::assertTrue($marker->when->evaluate($facts()));
        self::assertFalse($marker->when->evaluate($facts(['approvalCheckPassed' => false])));
        self::assertFalse($marker->when->evaluate($facts(['checks' => ChecksState::Pending])));
        self::assertFalse($marker->when->evaluate($facts(['mergeBlocked' => false])));
        self::assertFalse($marker->when->evaluate($facts(['state' => PullRequestState::Closed])));
        self::assertFalse($marker->when->evaluate($facts(run: FactsMother::run(activeWorkerKinds: ['fix']))));
    }

    public function test_the_waits_for_approval_marker_follows_the_approval_check(): void
    {
        $marker = array_find($this->lifecycle()->markers, static fn ($marker): bool => 'waits-for-approval' === $marker->key) ?? throw new \LogicException('waits-for-approval');
        $facts = static fn (array $overrides = []): Facts => FactsMother::facts(
            card: FactsMother::card(slot: 'in-review'),
            pullRequest: FactsMother::pullRequest(...[...['checks' => ChecksState::Passed, 'checksOnHead' => true, 'canMerge' => true, 'baseIsDefault' => true, 'canPostCheck' => true], ...$overrides]),
            provided: [HumanReviewFacts::class => new HumanReviewFacts(true)],
        );

        self::assertTrue($marker->when->evaluate($facts()));
        self::assertFalse($marker->when->evaluate($facts(['approvalCheckPassed' => true])));
    }

    public function test_a_draft_in_review_moves_back_to_implementation(): void
    {
        $facts = FactsMother::facts(
            card: FactsMother::card(slot: 'in-review'),
            pullRequest: FactsMother::pullRequest(draft: true, checks: ChecksState::Passed),
        );

        self::assertContainsEquals($this->call('move', ['to' => 'implementation']), $this->actions($facts));
    }

    public function test_a_ready_pull_request_asks_for_the_merge_write(): void
    {
        $ready = FactsMother::facts(
            card: FactsMother::card(slot: 'in-review'),
            pullRequest: FactsMother::pullRequest(checks: ChecksState::Passed, approvalCheckPassed: true),
        );
        $merge = $this->mergeWrite();

        self::assertContainsEquals($merge, $this->actions($ready));

        $unapproved = FactsMother::facts(
            card: FactsMother::card(slot: 'in-review'),
            pullRequest: FactsMother::pullRequest(checks: ChecksState::Passed),
        );
        self::assertNotContainsEquals($merge, $this->actions($unapproved));
    }

    public function test_an_uncommented_fix_run_or_a_stale_approval_asks_for_its_comment_in_any_column(): void
    {
        $fixRun = $this->call('forge-write', ['write' => 'comment', 'comment' => 'fix-run']);
        $stale = $this->call('forge-write', ['write' => 'comment', 'comment' => 'stale-approval']);
        $quiet = FactsMother::facts(card: FactsMother::card(slot: 'in-review'));
        self::assertNotContainsEquals($fixRun, $this->actions($quiet));
        self::assertNotContainsEquals($stale, $this->actions($quiet));

        foreach (['implementation', 'in-review'] as $slot) {
            $facts = FactsMother::facts(card: FactsMother::card(slot: $slot), provided: [
                FixRunFacts::class => new FixRunFacts(['run-1']),
                StaleApprovalFacts::class => new StaleApprovalFacts(['pull-request-1' => 'head1']),
            ]);

            self::assertContainsEquals($fixRun, $this->actions($facts), $slot);
            self::assertContainsEquals($stale, $this->actions($facts), $slot);
        }
    }

    public function test_an_epic_with_finished_children_moves_to_review_with_a_draft_pull_request(): void
    {
        $toReview = $this->call('move', ['to' => 'in-review']);
        $epic = FactsMother::card(slot: 'implementation', type: 'epic', childCount: 2);

        self::assertContainsEquals($toReview, $this->actions(FactsMother::facts(card: $epic, pullRequest: FactsMother::pullRequest())));
        self::assertContainsEquals($toReview, $this->actions(FactsMother::facts(card: $epic, pullRequest: FactsMother::pullRequest(draft: true))));
    }

    public function test_an_epic_in_review_with_a_draft_pull_request_stays_in_review(): void
    {
        $facts = FactsMother::facts(
            card: FactsMother::card(slot: 'in-review', type: 'epic', childCount: 2),
            pullRequest: FactsMother::pullRequest(draft: true),
        );

        self::assertNotContainsEquals($this->call('move', ['to' => 'implementation']), $this->actions($facts));
    }

    #[DataProvider('epicStateWrites')]
    public function test_an_epic_that_arrives_in_a_slot_writes_the_state_of_its_pull_requests(string $slot, string $write): void
    {
        $writeAction = $this->call('forge-write', ['write' => $write]);
        $epic = FactsMother::facts(card: FactsMother::card(slot: $slot, type: 'epic', childCount: 2, openChildCount: 1), pullRequest: FactsMother::pullRequest());
        $feature = FactsMother::facts(card: FactsMother::card(slot: $slot), pullRequest: FactsMother::pullRequest());

        self::assertContainsEquals($writeAction, $this->actions($epic));
        self::assertContainsEquals($writeAction, $this->actions(FactsMother::facts(card: FactsMother::card(slot: $slot, type: 'epic'))));
        self::assertNotContainsEquals($writeAction, $this->actions($feature));
    }

    /** @return iterable<string, array{string, string}> */
    public static function epicStateWrites(): iterable
    {
        yield 'implementation' => ['implementation', 'draft'];
        yield 'review' => ['in-review', 'ready'];
        yield 'backlog' => ['@backlog', 'close'];
    }

    public function test_an_epic_in_the_backlog_with_an_open_pull_request_stays_there(): void
    {
        $toImplementation = $this->call('move', ['to' => 'implementation', 'from' => '@backlog']);

        $epic = FactsMother::facts(card: FactsMother::card(slot: '@backlog', type: 'epic'), pullRequest: FactsMother::pullRequest());
        self::assertNotContainsEquals($toImplementation, $this->actions($epic));

        $feature = FactsMother::facts(card: FactsMother::card(slot: '@backlog'), pullRequest: FactsMother::pullRequest());
        self::assertContainsEquals($toImplementation, $this->actions($feature));
    }

    #[DataProvider('shippedKeys')]
    public function test_a_terminal_card_requests_its_teardown(string $key): void
    {
        $rules = array_values(array_filter($this->template($key)->rules, static fn ($rule): bool => 'teardown' === $rule->id));

        self::assertCount(1, $rules);
        self::assertSame('@terminal', $rules[0]->slot);
        self::assertTrue($rules[0]->when->evaluate(FactsMother::facts(card: FactsMother::card(slot: '@terminal'))));
        self::assertSame('request', $rules[0]->lead->key);
        self::assertSame(['kind' => 'teardown'], $rules[0]->lead->params);
    }

    #[DataProvider('shippedKeys')]
    public function test_every_request_but_the_teardown_names_what_its_work_needs_from_the_project(string $key): void
    {
        $requests = array_values(array_filter($this->template($key)->rules, static fn ($rule): bool => 'request' === $rule->lead->key));
        $withoutChecks = array_values(array_map(static fn ($rule): string => $rule->id, array_filter($requests, static fn ($rule): bool => [] === $rule->lead->checks)));

        self::assertNotEmpty($requests);
        self::assertSame(['teardown'], $withoutChecks);
    }

    public function test_the_ready_pull_request_of_an_epic_asks_for_the_merge_write(): void
    {
        $facts = FactsMother::facts(
            card: FactsMother::card(slot: 'in-review', type: 'epic', childCount: 2),
            pullRequest: FactsMother::pullRequest(checks: ChecksState::Passed, approvalCheckPassed: true),
        );

        self::assertContainsEquals($this->mergeWrite(), $this->actions($facts));
    }

    #[DataProvider('shippedKeys')]
    public function test_a_global_move_does_not_match_in_its_target_column(string $key): void
    {
        $template = $this->template($key);
        $merged = FactsMother::pullRequest(state: PullRequestState::Merged, closedAt: new \DateTimeImmutable('2026-10-01 11:00:00'));
        $closed = FactsMother::pullRequest(state: PullRequestState::Closed, closedAt: new \DateTimeImmutable('2026-10-01 11:00:00'));

        foreach ([['@terminal', $merged], ['@backlog', $closed]] as [$slot, $pullRequest]) {
            $facts = FactsMother::facts(card: FactsMother::card(slot: $slot), pullRequest: $pullRequest, pullRequests: [$pullRequest]);
            foreach ($template->rulesFor(null) as $rule) {
                self::assertFalse($rule->when->evaluate($facts) && $slot === ($rule->lead->params['to'] ?? null), $key.' '.$rule->id.' in '.$slot);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function shippedKeys(): iterable
    {
        yield 'lifecycle' => ['lifecycle'];
        yield 'simple' => ['simple'];
    }

    public function test_an_epic_with_a_reopened_child_does_not_merge(): void
    {
        $facts = FactsMother::facts(
            card: FactsMother::card(slot: 'in-review', type: 'epic', childCount: 2, openChildCount: 1),
            pullRequest: FactsMother::pullRequest(checks: ChecksState::Passed, approvalCheckPassed: true),
        );

        self::assertNotContainsEquals($this->mergeWrite(), $this->actions($facts));
    }

    public function test_a_child_whose_pull_requests_closed_unmerged_stays_in_its_column(): void
    {
        $toImplementation = $this->call('move', ['to' => 'implementation']);
        $toBacklog = $this->call('move', ['to' => '@backlog']);
        $closed = [FactsMother::pullRequest(state: PullRequestState::Closed, closedAt: new \DateTimeImmutable('2026-10-01 11:45:00'))];

        $unblocked = FactsMother::facts(card: FactsMother::card(slot: 'next', isChild: true, documents: [self::approved('tech-design')], parentSlot: 'implementation'));
        self::assertContainsEquals($toImplementation, $this->actions($unblocked));

        $inImplementation = FactsMother::facts(card: FactsMother::card(slot: 'implementation', isChild: true), pullRequests: $closed);
        self::assertNotContainsEquals($toBacklog, $this->actions($inImplementation));

        $inNext = FactsMother::facts(card: FactsMother::card(slot: 'next', isChild: true, documents: [self::approved('tech-design')], parentSlot: 'implementation'), pullRequests: $closed);
        self::assertNotContainsEquals($toImplementation, $this->actions($inNext));
    }

    public function test_a_child_in_next_starts_only_with_an_approved_tech_design(): void
    {
        $toImplementation = $this->call('move', ['to' => 'implementation']);
        $id = '01a10beb-ba65-736b-8626-a6e3fa59dfc5';

        self::assertContainsEquals($toImplementation, $this->actions(self::nextChild([self::approved('tech-design')])));
        self::assertNotContainsEquals($toImplementation, $this->actions(self::nextChild([])));
        self::assertNotContainsEquals($toImplementation, $this->actions(self::nextChild([new DocumentFacts(['tech-design'], 'in-review', $id)])));
        self::assertNotContainsEquals($toImplementation, $this->actions(self::nextChild([new DocumentFacts(['tech-design'], 'changes-requested', $id)])));
        self::assertNotContainsEquals($toImplementation, $this->actions(self::nextChild([new DocumentFacts(['product-design'], 'approved', $id)])));
    }

    public function test_a_child_in_next_waits_until_its_epic_sits_in_implementation(): void
    {
        $toImplementation = $this->call('move', ['to' => 'implementation']);
        $documents = [self::approved('tech-design')];

        foreach ([['implementation', true], ['next', false], ['@backlog', false], ['tech-design', false], [null, false]] as [$parentSlot, $starts]) {
            $facts = FactsMother::facts(card: FactsMother::card(slot: 'next', isChild: true, documents: $documents, parentSlot: $parentSlot));
            $starts ? self::assertContainsEquals($toImplementation, $this->actions($facts), \sprintf('Parent slot "%s".', $parentSlot)) : self::assertNotContainsEquals($toImplementation, $this->actions($facts), \sprintf('Parent slot "%s".', $parentSlot ?? 'none'));
        }
    }

    public function test_a_child_in_the_backlog_is_parked_and_a_child_outside_it_is_not_moved(): void
    {
        $toNext = $this->call('move', ['to' => 'next', 'from' => '@backlog']);
        $documents = [self::approved('tech-design')];
        $backlog = FactsMother::facts(card: FactsMother::card(slot: '@backlog', isChild: true, documents: $documents, parentSlot: 'implementation'));

        self::assertSame(['child-to-next'], $this->firingRuleIds($backlog));
        self::assertContainsEquals($toNext, $this->actions($backlog));
        self::assertNotContainsEquals($toNext, $this->actions(FactsMother::facts(card: FactsMother::card(slot: '@backlog'))));
        $tail = array_map(static fn ($rule): string => $rule->id, \array_slice($this->lifecycle()->rules, -2));
        self::assertSame(['child-to-next', 'follow-up-to-next'], $tail);
    }

    public function test_an_epic_entering_implementation_evaluates_its_children_and_only_there(): void
    {
        $evaluate = $this->call('evaluate', ['cards' => 'children']);
        $epic = static fn (string $slot): Facts => FactsMother::facts(card: FactsMother::card(slot: $slot, type: 'epic', childCount: 2, openChildCount: 2), run: FactsMother::run(activeWorkerKinds: ['breakdown']));

        self::assertContains('epic-entered-implementation', $this->firingRuleIds($epic('implementation')));
        self::assertNotContains('epic-entered-implementation', $this->firingRuleIds($epic('next')));
        self::assertNotContains('epic-entered-implementation', $this->firingRuleIds(FactsMother::facts(card: FactsMother::card(slot: 'implementation'))));
        self::assertContainsEquals($evaluate, $this->actions($epic('implementation')));
    }

    public function test_an_unplanned_child_gets_its_ask_in_next_and_not_in_the_backlog(): void
    {
        $parentDesign = [self::approved('tech-design')];
        $inNext = FactsMother::facts(card: FactsMother::card(slot: 'next', isChild: true, parentDocuments: $parentDesign));
        $inBacklog = FactsMother::facts(card: FactsMother::card(slot: '@backlog', isChild: true, parentDocuments: $parentDesign));

        self::assertContains('unplanned-child', $this->firingRuleIds($inNext));
        self::assertNotContains('unplanned-child', $this->firingRuleIds($inBacklog));
    }

    public function test_the_lifecycle_template_lets_no_run_of_the_parent_move_a_child(): void
    {
        $moves = $this->template('lifecycle')->manualMoves;

        self::assertCount(9, $moves);
        self::assertSame([], array_filter($moves, static fn ($move): bool => ManualMoveActor::ParentRun === $move->by));
    }

    public function test_a_child_in_next_waits_while_any_run_of_its_epic_is_open(): void
    {
        $toImplementation = $this->call('move', ['to' => 'implementation']);
        $child = FactsMother::card(slot: 'next', isChild: true, documents: [self::approved('tech-design')], parentSlot: 'implementation');

        self::assertNotContainsEquals($toImplementation, $this->actions(FactsMother::facts(card: $child, run: FactsMother::run(parentActiveKinds: ['breakdown']))));
        self::assertNotContainsEquals($toImplementation, $this->actions(FactsMother::facts(card: $child, run: FactsMother::run(parentActiveKinds: ['implement']))));
        self::assertContainsEquals($toImplementation, $this->actions(FactsMother::facts(card: $child)));
    }

    public function test_an_epic_in_any_column_evaluates_its_children_once_no_worker_run_of_it_is_open(): void
    {
        $evaluate = $this->call('evaluate', ['cards' => 'children']);
        $epic = FactsMother::card(slot: 'next', type: 'epic', childCount: 2, openChildCount: 2);

        self::assertNotContainsEquals($evaluate, $this->actions(FactsMother::facts(card: $epic, run: FactsMother::run(activeWorkerKinds: ['breakdown']))));
        self::assertNotContainsEquals($evaluate, $this->actions(FactsMother::facts(card: $epic, run: FactsMother::run(activeWorkerKinds: ['fix']))));
        self::assertContainsEquals($evaluate, $this->actions(FactsMother::facts(card: $epic)));
        self::assertContainsEquals($evaluate, $this->actions(FactsMother::facts(card: FactsMother::card(slot: 'tech-design', type: 'epic', childCount: 2, openChildCount: 2))));
        self::assertNotContainsEquals($evaluate, $this->actions(FactsMother::facts(card: FactsMother::card(slot: 'implementation'))));
    }

    public function test_a_merged_epic_waits_for_its_open_run_and_a_merged_card_does_not(): void
    {
        $toTerminal = $this->call('move', ['to' => '@terminal', 'outcome' => 'merged']);
        $merged = FactsMother::pullRequest(state: PullRequestState::Merged, closedAt: new \DateTimeImmutable('2026-10-01 11:00:00'));
        $epic = FactsMother::card(slot: 'in-review', type: 'epic', childCount: 2);
        $feature = FactsMother::card(slot: 'in-review');
        $open = FactsMother::run(activeWorkerKinds: ['fix']);

        self::assertNotContainsEquals($toTerminal, $this->actions(FactsMother::facts(card: $epic, pullRequest: $merged, pullRequests: [$merged], run: $open)));
        self::assertContainsEquals($toTerminal, $this->actions(FactsMother::facts(card: $epic, pullRequest: $merged, pullRequests: [$merged])));
        self::assertContainsEquals($toTerminal, $this->actions(FactsMother::facts(card: $feature, pullRequest: $merged, pullRequests: [$merged], run: $open)));
    }

    public function test_an_implement_run_that_finished_with_no_code_moves_only_a_card_with_no_pull_request_and_no_live_work(): void
    {
        $finished = static fn (string $reason, bool $live = false): RunInputs => FactsMother::run(activeWorkKinds: $live ? ['implement'] : [], latestWorkState: WorkRequestState::Done, latestWorkKind: 'implement', latestWorkReason: $reason);
        $feature = FactsMother::card(slot: 'implementation');
        $pullRequest = FactsMother::pullRequest();

        self::assertSame(['nothing-to-build'], array_values(array_intersect($this->firingRuleIds(FactsMother::facts(card: $feature, run: $finished('nothing-to-build'))), ['nothing-to-build', 'delivered-without-code'])));
        self::assertSame(['delivered-without-code'], array_values(array_intersect($this->firingRuleIds(FactsMother::facts(card: $feature, run: $finished('delivered-without-code'))), ['nothing-to-build', 'delivered-without-code'])));
        foreach ([
            'an epic' => FactsMother::facts(card: FactsMother::card(slot: 'implementation', type: 'epic'), run: $finished('nothing-to-build')),
            'a linked pull request' => FactsMother::facts(card: $feature, pullRequest: $pullRequest, pullRequests: [$pullRequest], run: $finished('nothing-to-build')),
            'live work' => FactsMother::facts(card: $feature, run: $finished('nothing-to-build', live: true)),
            'another slot' => FactsMother::facts(card: FactsMother::card(slot: 'in-review'), run: $finished('nothing-to-build')),
        ] as $case => $facts) {
            self::assertSame([], array_values(array_intersect($this->firingRuleIds($facts), ['nothing-to-build', 'delivered-without-code'])), $case);
        }
    }

    /** @param list<string> $pair */
    #[DataProvider('overlaps')]
    public function test_only_one_rule_of_an_overlapping_pair_fires(Facts $facts, array $pair, ?string $fires): void
    {
        self::assertSame(null === $fires ? [] : [$fires], array_values(array_intersect($this->firingRuleIds($facts), $pair)));
    }

    /** @return iterable<string, array{Facts, list<string>, ?string}> */
    public static function overlaps(): iterable
    {
        $merged = FactsMother::pullRequest(state: PullRequestState::Merged, closedAt: new \DateTimeImmutable('2026-10-01 11:00:00'));
        yield 'a merged pull request does not ask for an implementation' => [
            FactsMother::facts(card: FactsMother::card(slot: 'implementation'), pullRequest: $merged, pullRequests: [$merged]),
            ['implement', 'merged'],
            'merged',
        ];

        $closed = FactsMother::pullRequest(state: PullRequestState::Closed, closedAt: new \DateTimeImmutable('2026-10-01 11:45:00'));
        yield 'a closed pull request does not ask for an implementation' => [
            FactsMother::facts(card: FactsMother::card(slot: 'implementation'), pullRequest: $closed, pullRequests: [$closed]),
            ['implement'],
            null,
        ];

        yield 'a product design with changes requested asks only for a revision' => [
            FactsMother::facts(card: FactsMother::card(slot: 'product-design', documents: [new DocumentFacts(tags: ['product-design'], status: 'changes-requested', id: '01a10beb-ba65-736b-8626-a6e3fa59dfc5')])),
            ['product-design-session', 'product-design-revise'],
            'product-design-revise',
        ];

        yield 'a tech design with changes requested asks only for a revision' => [
            FactsMother::facts(card: FactsMother::card(slot: 'tech-design', documents: [new DocumentFacts(tags: ['tech-design'], status: 'changes-requested', id: '01a10beb-ba65-736b-8626-a6e3fa59dfc5')])),
            ['tech-design-write', 'tech-design-revise'],
            'tech-design-revise',
        ];

        $conflicting = FactsMother::pullRequest(checks: ChecksState::Passed, conflicting: true);
        yield 'a conflicting pull request asks for a fix and does not move' => [
            FactsMother::facts(card: FactsMother::card(slot: 'implementation'), pullRequest: $conflicting, pullRequests: [$conflicting]),
            ['reviewable', 'fix-in-implementation'],
            'fix-in-implementation',
        ];

        $changesRequested = FactsMother::pullRequest(checks: ChecksState::Passed, changesRequested: true);
        yield 'a pull request with changes requested asks for a fix and does not move' => [
            FactsMother::facts(card: FactsMother::card(slot: 'implementation'), pullRequest: $changesRequested, pullRequests: [$changesRequested]),
            ['reviewable', 'fix-in-implementation'],
            'fix-in-implementation',
        ];

        $behind = FactsMother::pullRequest(checks: ChecksState::Passed, behind: true, approvalCheckPassed: true);
        yield 'an approved pull request that is behind updates and does not merge' => [
            FactsMother::facts(card: FactsMother::card(slot: 'in-review'), pullRequest: $behind, pullRequests: [$behind]),
            ['merge-ready', 'update-behind'],
            'update-behind',
        ];

        $approvedIntoEpic = FactsMother::pullRequest(checks: ChecksState::Passed, baseIsEpicBranch: true, approvalCheckPassed: true);
        yield 'an approved pull request into the epic branch merges through the epic child rule' => [
            FactsMother::facts(card: FactsMother::card(slot: 'in-review', isChild: true), pullRequest: $approvedIntoEpic, pullRequests: [$approvedIntoEpic]),
            ['merge-ready', 'merge-ready-epic-child'],
            'merge-ready-epic-child',
        ];

        yield 'an approved pull request into the epic branch waits while a run of its epic is open' => [
            FactsMother::facts(card: FactsMother::card(slot: 'in-review', isChild: true), pullRequest: $approvedIntoEpic, pullRequests: [$approvedIntoEpic], run: FactsMother::run(parentActiveKinds: ['fix'])),
            ['merge-ready', 'merge-ready-epic-child'],
            null,
        ];

        $approvedIntoDefault = FactsMother::pullRequest(checks: ChecksState::Passed, approvalCheckPassed: true);
        yield 'an approved pull request into the default branch merges through merge-ready' => [
            FactsMother::facts(card: FactsMother::card(slot: 'in-review', isChild: true), pullRequest: $approvedIntoDefault, pullRequests: [$approvedIntoDefault]),
            ['merge-ready', 'merge-ready-epic-child'],
            'merge-ready',
        ];

        $readyForApproval = FactsMother::pullRequest(checks: ChecksState::Passed, checksOnHead: true, baseIsDefault: true, canPostCheck: true);
        yield 'a pull request that no person must review gets the approval check' => [
            FactsMother::facts(card: FactsMother::card(slot: 'in-review'), pullRequest: $readyForApproval, pullRequests: [$readyForApproval]),
            ['approval-check-auto', 'approval-check-person', 'merge-ready'],
            'approval-check-auto',
        ];

        yield 'a pull request that a person must review gets no approval check from Loupe' => [
            FactsMother::facts(card: FactsMother::card(slot: 'in-review'), pullRequest: $readyForApproval, pullRequests: [$readyForApproval], provided: [HumanReviewFacts::class => new HumanReviewFacts(true)]),
            ['approval-check-auto', 'approval-check-person', 'merge-ready'],
            null,
        ];

        $noInstallation = FactsMother::pullRequest(checks: ChecksState::Passed, checksOnHead: true, baseIsDefault: true);
        yield 'a repository with no App installation gets no approval check' => [
            FactsMother::facts(card: FactsMother::card(slot: 'in-review'), pullRequest: $noInstallation, pullRequests: [$noInstallation]),
            ['approval-check-auto', 'approval-check-person', 'merge-ready'],
            null,
        ];

        $checkPassed = FactsMother::pullRequest(checks: ChecksState::Passed, checksOnHead: true, baseIsDefault: true, approvalCheckPassed: true, canPostCheck: true);
        yield 'a pull request whose approval check passed merges and posts no second check' => [
            FactsMother::facts(card: FactsMother::card(slot: 'in-review'), pullRequest: $checkPassed, pullRequests: [$checkPassed]),
            ['approval-check-auto', 'approval-check-person', 'merge-ready'],
            'merge-ready',
        ];

        yield 'a pull request whose approval check passed fails the check when the card needs a person' => [
            FactsMother::facts(card: FactsMother::card(slot: 'in-review'), pullRequest: $checkPassed, pullRequests: [$checkPassed], provided: [HumanReviewFacts::class => new HumanReviewFacts(true)]),
            ['approval-check-auto', 'merge-ready', 'approval-check-revoke'],
            'approval-check-revoke',
        ];

        $approvedByPerson = FactsMother::pullRequest(checks: ChecksState::Passed, approvalsCoveringHead: 1, checksOnHead: true, baseIsDefault: true);
        yield 'a pull request that a person approved gets the approval check when the card needs a person' => [
            FactsMother::facts(card: FactsMother::card(slot: 'in-review'), pullRequest: $approvedByPerson, pullRequests: [$approvedByPerson], provided: [HumanReviewFacts::class => new HumanReviewFacts(true)]),
            ['approval-check-auto', 'approval-check-person', 'merge-ready'],
            'approval-check-person',
        ];

        $personAndCheck = FactsMother::pullRequest(checks: ChecksState::Passed, approvalsCoveringHead: 1, checksOnHead: true, baseIsDefault: true, approvalCheckPassed: true);
        yield 'a person approval keeps the passed check when the card needs a person' => [
            FactsMother::facts(card: FactsMother::card(slot: 'in-review'), pullRequest: $personAndCheck, pullRequests: [$personAndCheck], provided: [HumanReviewFacts::class => new HumanReviewFacts(true)]),
            ['approval-check-person', 'merge-ready', 'approval-check-revoke'],
            'merge-ready',
        ];

        $finishedEpic = FactsMother::card(slot: 'implementation', type: 'epic', childCount: 2);
        yield 'an epic with closed unmerged pull requests stays, and does not go to done' => [
            FactsMother::facts(card: $finishedEpic, pullRequest: $closed, pullRequests: [$closed]),
            ['epic-to-done', 'merged'],
            null,
        ];

        yield 'an epic with no pull request moves to done' => [
            FactsMother::facts(card: $finishedEpic),
            ['epic-to-done', 'merged'],
            'epic-to-done',
        ];

        $productDocuments = [new DocumentFacts(tags: ['product-design'], status: 'approved', id: '01a10beb-ba65-736b-8626-a6e3fa59dfc5'), new DocumentFacts(tags: ['product-design'], status: 'changes-requested', id: '01a10beb-ba65-736b-8626-a6e3fa59dfc5')];
        yield 'a product design with one document approved and one to revise stays to revise' => [
            FactsMother::facts(card: FactsMother::card(slot: 'product-design', documents: $productDocuments)),
            ['product-design-approved', 'product-design-revise'],
            'product-design-revise',
        ];

        $designDocuments = [new DocumentFacts(tags: ['tech-design'], status: 'approved', id: '01a10beb-ba65-736b-8626-a6e3fa59dfc5'), new DocumentFacts(tags: ['tech-design'], status: 'changes-requested', id: '01a10beb-ba65-736b-8626-a6e3fa59dfc5')];
        yield 'a tech design with one document approved and one to revise stays to revise' => [
            FactsMother::facts(card: FactsMother::card(slot: 'tech-design', documents: $designDocuments)),
            ['tech-design-approved', 'tech-design-revise'],
            'tech-design-revise',
        ];

        $justClosed = FactsMother::pullRequest(state: PullRequestState::Closed, closedAt: new \DateTimeImmutable('2026-10-01 11:55:00'));
        yield 'a child whose pull request just closed stays in next' => [
            FactsMother::facts(card: FactsMother::card(slot: 'next', isChild: true, parentSlot: 'implementation'), pullRequest: $justClosed, pullRequests: [$justClosed]),
            ['child-unblocked', 'pull-request-reopened'],
            null,
        ];

        $mergedStacked = FactsMother::pullRequest(state: PullRequestState::Merged, stacked: true, parentMerged: true, closedAt: new \DateTimeImmutable('2026-10-01 11:00:00'));
        yield 'a merged stacked pull request asks for no base change' => [
            FactsMother::facts(card: FactsMother::card(slot: 'in-review'), pullRequest: $mergedStacked, pullRequests: [$mergedStacked]),
            ['rebase-stacked', 'merged'],
            'merged',
        ];
    }

    public function test_a_child_waits_in_next_for_the_epic_branch_to_take_the_default_branch(): void
    {
        $sync = $this->call('forge-write', ['write' => 'merge-main-into-epic', 'fallback' => 'sync-epic']);
        $rules = array_column($this->lifecycle()->rulesFor('next'), null, 'id');
        self::assertEquals($sync, $rules['epic-branch-sync']->lead);
        $ids = array_map(static fn ($rule): string => $rule->id, $this->lifecycle()->rulesFor('next'));
        self::assertLessThan(array_search('child-unblocked', $ids, true), array_search('epic-branch-sync', $ids, true));

        $child = FactsMother::card(slot: 'next', isChild: true, documents: [new DocumentFacts(tags: ['tech-design'], status: 'approved', id: '01a10beb-ba65-736b-8626-a6e3fa59dfc5')], parentSlot: 'implementation');
        $stale = [EpicBranchSyncFacts::class => new EpicBranchSyncFacts(false)];
        $bridgeDone = FactsMother::run(latestWorkState: WorkRequestState::Done, latestWorkKind: 'sync-epic', latestWorkReason: 'done');
        $bridgeBlocked = FactsMother::run(latestWorkState: WorkRequestState::Done, latestWorkKind: 'sync-epic', latestWorkReason: 'blocked');

        $waiting = $this->firingRuleIds(FactsMother::facts(card: $child, provided: $stale));
        self::assertContains('epic-branch-sync', $waiting);
        self::assertNotContains('child-unblocked', $waiting);

        $synced = $this->firingRuleIds(FactsMother::facts(card: $child));
        self::assertNotContains('epic-branch-sync', $synced);
        self::assertContains('child-unblocked', $synced);

        // A finished fallback run counts only through what the epic recorded, so a blocker that finished after it asks for a new merge.
        $staleAfterBridge = $this->firingRuleIds(FactsMother::facts(card: $child, run: $bridgeDone, provided: $stale));
        self::assertContains('epic-branch-sync', $staleAfterBridge);
        self::assertNotContains('child-unblocked', $staleAfterBridge);

        $afterBridge = $this->firingRuleIds(FactsMother::facts(card: $child, run: $bridgeDone));
        self::assertNotContains('epic-branch-sync', $afterBridge);
        self::assertContains('child-unblocked', $afterBridge);

        $blocked = $this->firingRuleIds(FactsMother::facts(card: $child, run: $bridgeBlocked, provided: $stale));
        self::assertContains('epic-branch-sync', $blocked);
        self::assertNotContains('child-unblocked', $blocked);
    }

    public function test_an_epic_child_pull_request_merges_into_the_epic_branch_with_no_approval(): void
    {
        $merge = $this->mergeWrite();
        $child = FactsMother::card(slot: 'in-review', isChild: true);

        $intoEpic = FactsMother::pullRequest(checks: ChecksState::Passed, baseIsEpicBranch: true);
        self::assertContainsEquals($merge, $this->actions(FactsMother::facts(card: $child, pullRequest: $intoEpic, pullRequests: [$intoEpic])));

        $intoDefault = FactsMother::pullRequest(checks: ChecksState::Passed);
        self::assertNotContainsEquals($merge, $this->actions(FactsMother::facts(card: $child, pullRequest: $intoDefault, pullRequests: [$intoDefault])));

        $pending = FactsMother::pullRequest(checks: ChecksState::Pending, baseIsEpicBranch: true);
        self::assertNotContainsEquals($merge, $this->actions(FactsMother::facts(card: $child, pullRequest: $pending, pullRequests: [$pending])));
    }

    public function test_a_behind_epic_child_pull_request_updates_its_branch_with_no_approval(): void
    {
        $update = $this->call('forge-write', ['write' => 'update-branch', 'fallback' => 'sync']);
        $child = FactsMother::card(slot: 'in-review', isChild: true);

        $intoEpic = FactsMother::pullRequest(checks: ChecksState::Passed, behind: true, baseIsEpicBranch: true);
        self::assertContainsEquals($update, $this->actions(FactsMother::facts(card: $child, pullRequest: $intoEpic, pullRequests: [$intoEpic])));

        $intoDefault = FactsMother::pullRequest(checks: ChecksState::Passed, behind: true);
        self::assertNotContainsEquals($update, $this->actions(FactsMother::facts(card: $child, pullRequest: $intoDefault, pullRequests: [$intoDefault])));

        $merged = FactsMother::pullRequest(state: PullRequestState::Merged, checks: ChecksState::Passed, behind: true, baseIsEpicBranch: true, approvalCheckPassed: true);
        self::assertNotContainsEquals($update, $this->actions(FactsMother::facts(card: $child, pullRequest: $merged, pullRequests: [$merged])));
    }

    public function test_a_stage_slot_reads_the_tag_of_its_document(): void
    {
        $lifecycle = $this->lifecycle();

        self::assertSame(['product-design'], $lifecycle->documentTagsFor('product-design'));
        self::assertSame(['tech-design'], $lifecycle->documentTagsFor('tech-design'));
        self::assertSame([], $lifecycle->documentTagsFor('implementation'));
        self::assertSame([], $lifecycle->documentTagsFor(null));
        self::assertSame([], $this->template('simple')->documentTagsFor(null));
    }

    public function test_the_shipped_lifecycle_ends_each_design_review_loop_after_six_failed_reviews(): void
    {
        $lifecycle = $this->lifecycle();

        self::assertSame(6, $lifecycle->reviewRoundLimit(['product-design']));
        self::assertSame(6, $lifecycle->reviewRoundLimit(['tech-design', 'deploy-notes']));
        self::assertNull($lifecycle->reviewRoundLimit(['plan']));
        self::assertNull($this->template('simple')->reviewRoundLimit(['tech-design']));
    }

    public function test_a_revision_request_names_the_document_it_revises_by_the_tag_its_rule_reads(): void
    {
        $params = [];
        foreach ($this->lifecycle()->rules as $rule) {
            if (\in_array($rule->id, ['product-design-revise', 'tech-design-revise'], true)) {
                $params[$rule->id] = [$rule->lead->params[TemplateParser::DOCUMENT_TAG] ?? null, $rule->lead->params[TemplateParser::DOCUMENT_STATUS] ?? null];
            }
        }

        self::assertSame(['product-design-revise' => ['product-design', 'changes-requested'], 'tech-design-revise' => ['tech-design', 'changes-requested']], $params);
    }

    private function mergeWrite(): ActionCall
    {
        $actions = self::getContainer()->get(Actions::class);
        self::assertInstanceOf(Actions::class, $actions);

        return $actions->call('forge-write', ['write' => 'merge', 'fallback' => 'merge'], lists: ['methods' => ['squash', 'merge', 'rebase']]);
    }

    /** @param array<string, int|string> $params */
    private function call(string $key, array $params): ActionCall
    {
        $actions = self::getContainer()->get(Actions::class);
        self::assertInstanceOf(Actions::class, $actions);

        return $actions->call($key, $params);
    }

    private function shipped(): ShippedTemplates
    {
        return static::getContainer()->get(ShippedTemplates::class);
    }

    private function template(string $key): Template
    {
        return static::getContainer()->get(TemplateParser::class)->parse($this->shipped()->source($key));
    }

    private function lifecycle(): Template
    {
        return $this->template('lifecycle');
    }

    /** @return list<string> the id of every Lifecycle rule that matches the facts */
    private function firingRuleIds(Facts $facts): array
    {
        $ids = [];
        foreach ($this->lifecycle()->rulesFor($facts->slot) as $rule) {
            if ($rule->when->evaluate($facts)) {
                $ids[] = $rule->id;
            }
        }

        return $ids;
    }

    /** @return list<ActionCall> the actions of every Lifecycle rule that matches the facts */
    private function actions(Facts $facts): array
    {
        $actions = [];
        foreach ($this->lifecycle()->rulesFor($facts->slot) as $rule) {
            if ($rule->when->evaluate($facts)) {
                array_push($actions, ...$rule->then);
            }
        }

        return $actions;
    }

    /** @param list<DocumentFacts> $documents */
    private static function nextChild(array $documents): Facts
    {
        return FactsMother::facts(card: FactsMother::card(slot: 'next', isChild: true, documents: $documents, parentSlot: 'implementation'));
    }

    private static function approved(string $tag): DocumentFacts
    {
        return new DocumentFacts(tags: [$tag], status: 'approved', id: '01a10beb-ba65-736b-8626-a6e3fa59dfc5');
    }
}
