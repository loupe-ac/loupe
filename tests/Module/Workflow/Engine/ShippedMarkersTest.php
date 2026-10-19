<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Engine;

use App\Module\AgentReview\Workflow\DocumentReviewFacts;
use App\Module\AgentReview\Workflow\DocumentReviewState;
use App\Module\Board\Workflow\CardActivityFacts;
use App\Module\Board\Workflow\ChildMark;
use App\Module\Board\Workflow\ChildrenFacts;
use App\Module\Board\Workflow\PauseFacts;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkTakerReading;
use App\Module\Bridge\ValueObject\WorkTakerState;
use App\Module\Bridge\View\CardRunWarning;
use App\Module\Bridge\Workflow\HoldFacts;
use App\Module\Bridge\Workflow\RunStoppedFacts;
use App\Module\Bridge\Workflow\SessionFacts;
use App\Module\Bridge\Workflow\WorkTakerFacts;
use App\Module\Inbox\Workflow\OpenQuestion;
use App\Module\Inbox\Workflow\OpenQuestionFacts;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Workflow\Contract\BlockerHold;
use App\Module\Workflow\Contract\ChecksState;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\ForgeRequest;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Contract\MarkerKind;
use App\Module\Workflow\Contract\MarkerLink;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Contract\PauseView;
use App\Module\Workflow\Contract\PullRequestFacts;
use App\Module\Workflow\Contract\PullRequestState;
use App\Module\Workflow\Contract\StackParent;
use App\Module\Workflow\Expression\AllOf;
use App\Module\Workflow\Expression\AnyOf;
use App\Module\Workflow\Expression\ConditionLeaf;
use App\Module\Workflow\Expression\Expression;
use App\Module\Workflow\Fact\BlockerHoldFacts;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateParser;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Component\Uid\Uuid;

/** The markers of the shipped templates, read through the marker pass on facts a card can have. */
final class ShippedMarkersTest extends KernelTestCase
{
    use MarkerScenario;

    private const array LIFECYCLE_KEYS = [
        'paused', 'run-stopped', 'checks-failed', 'conflicting', 'ready-not-merged', 'pull-request-idle', 'nothing-happened',
        'document-in-review', 'waits-for-approval', 'open-question', 'design-review-running', 'session-open', 'run-open', 'no-capable-bridge', 'no-bridge-online', 'bridge-paused', 'work-requested',
        'forge-request-pending', 'held-by-blocker', 'waits-for-parent-card', 'waits-for-parent-branch', 'waiting-on-checks',
        'child-stuck', 'child-needs-you', 'child-working', 'child-waiting', 'unmanaged',
    ];

    public function test_the_lifecycle_template_ships_its_markers_in_order(): void
    {
        self::assertSame(self::LIFECYCLE_KEYS, array_map(static fn ($marker): string => $marker->key, $this->template('lifecycle')->markers));
    }

    public function test_the_simple_template_ships_the_same_markers_without_slots_and_without_the_ones_that_need_them(): void
    {
        $markers = $this->template('simple')->markers;

        self::assertSame(array_values(array_diff(self::LIFECYCLE_KEYS, ['pull-request-idle', 'nothing-happened', 'document-in-review', 'design-review-running'])), array_map(static fn ($marker): string => $marker->key, $markers));
        foreach ($markers as $marker) {
            self::assertSame([], $marker->slots, $marker->key);
        }
    }

    #[DataProvider('shippedKeys')]
    public function test_every_label_and_remedy_has_an_english_string(string $key): void
    {
        $translator = static::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue('en');

        foreach ($this->template($key)->markers as $marker) {
            self::assertTrue($catalogue->defines($marker->label), \sprintf('"%s" has no English string.', $marker->label));
            self::assertNotNull($marker->remedy, $marker->key);
            self::assertTrue($catalogue->defines($marker->remedy), \sprintf('"%s" has no English string.', $marker->remedy));
        }
    }

    #[DataProvider('shippedKeys')]
    public function test_every_label_that_reads_a_pull_request_names_it(string $key): void
    {
        $translator = static::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue('en');

        foreach ($this->template($key)->markers as $marker) {
            if (\in_array(EngineFact::PullRequest, $marker->when->reads(), true)) {
                self::assertStringContainsString('#%number%', $catalogue->get($marker->label), $marker->key);
            }
        }
    }

    #[DataProvider('shippedKeys')]
    public function test_every_label_that_names_a_pull_request_has_a_condition_that_gives_its_number(string $key): void
    {
        $translator = static::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue('en');

        $checked = 0;
        foreach ($this->template($key)->markers as $marker) {
            if (!str_contains($catalogue->get($marker->label), '#%number%') || !\in_array(EngineFact::PullRequest, $marker->when->reads(), true)) {
                continue;
            }
            ++$checked;
            self::assertTrue(self::givesPullRequestEvidence($marker->when), \sprintf('"%s" names a pull request, and no condition outside a negation gives its number.', $marker->key));
        }
        self::assertGreaterThan(0, $checked);
    }

    /** Whether a leaf outside a negation reads the pull request and tells the marker what it saw. */
    private static function givesPullRequestEvidence(Expression $expression): bool
    {
        return match (true) {
            $expression instanceof ConditionLeaf => $expression->condition instanceof MarkerEvidence && \in_array(EngineFact::PullRequest, $expression->reads(), true),
            $expression instanceof AllOf, $expression instanceof AnyOf => array_any($expression->children, self::givesPullRequestEvidence(...)),
            default => false,
        };
    }

    /** @return iterable<string, array{string}> */
    public static function shippedKeys(): iterable
    {
        yield 'lifecycle' => ['lifecycle'];
        yield 'simple' => ['simple'];
    }

    public function test_a_quiet_card_with_nothing_to_report_shows_no_marker(): void
    {
        self::assertSame([], $this->keys(FactsMother::facts(card: FactsMother::card(slot: 'implementation'))));
    }

    public function test_a_pause_is_stuck_with_its_reason(): void
    {
        $pause = new PauseView(Uuid::v7(), Uuid::v7(), Uuid::v7(), 'move-refused', 'rule', PauseKind::Retries, new \DateTimeImmutable('2026-10-01 11:00:00'));

        $this->pass($this->template('lifecycle'), FactsMother::facts(card: FactsMother::card(slot: 'implementation'), provided: [PauseFacts::class => new PauseFacts($pause)]));

        self::assertSame(['paused'], $this->writtenKeys());
        self::assertSame(['%reason%' => 'move-refused'], $this->written('paused')->params);
    }

    #[DataProvider('shippedKeys')]
    public function test_a_stopped_run_is_stuck_until_new_work_is_under_way(string $key): void
    {
        $template = $this->template($key);

        self::assertSame(['run-stopped'], $this->passKeys($template, $this->stoppedFacts()));
        self::assertSame(['work-requested'], $this->passKeys($template, $this->stoppedFacts(['implement'])), 'queued work waits for a bridge');
        self::assertSame(['run-open'], $this->passKeys($template, $this->stoppedFacts([], ['implement'])));
        self::assertSame(['run-stopped'], $this->passKeys($template, $this->stoppedFacts()), 'the work ended with the card still where the run stopped');
    }

    #[DataProvider('shippedKeys')]
    public function test_queued_work_with_no_online_bridge_is_stuck_and_links_to_the_agents_page(string $key): void
    {
        $template = $this->template($key);

        self::assertSame(['no-bridge-online'], $this->passKeys($template, $this->takerFacts(WorkTakerState::None, ['implement'])));
        self::assertSame(MarkerKind::Stuck, $this->written('no-bridge-online')->kind);
        self::assertSame(MarkerLink::Agents, $this->written('no-bridge-online')->link);
        self::assertEquals(new \DateTimeImmutable('2026-10-01 11:00:00'), $this->written('no-bridge-online')->since);
    }

    #[DataProvider('shippedKeys')]
    public function test_queued_work_with_an_online_bridge_that_cannot_run_it_says_so_and_links_to_the_agents_page(string $key): void
    {
        $template = $this->template($key);

        self::assertSame(['no-capable-bridge'], $this->passKeys($template, $this->takerFacts(WorkTakerState::NotCapable, ['implement'])));
        self::assertSame(MarkerKind::Stuck, $this->written('no-capable-bridge')->kind);
        self::assertSame(MarkerLink::Agents, $this->written('no-capable-bridge')->link);
    }

    #[DataProvider('shippedKeys')]
    public function test_queued_work_with_only_a_paused_bridge_waits_and_links_to_the_agents_page(string $key): void
    {
        $template = $this->template($key);

        self::assertSame(['bridge-paused'], $this->passKeys($template, $this->takerFacts(WorkTakerState::Paused, ['implement'])));
        self::assertSame(MarkerKind::Waiting, $this->written('bridge-paused')->kind);
        self::assertSame(MarkerLink::Agents, $this->written('bridge-paused')->link);
    }

    #[DataProvider('shippedKeys')]
    public function test_queued_work_that_a_bridge_could_take_waits(string $key): void
    {
        self::assertSame(['work-requested'], $this->passKeys($this->template($key), $this->takerFacts(WorkTakerState::Available, ['implement'])));
        self::assertSame(MarkerKind::Waiting, $this->written('work-requested')->kind);
        self::assertNull($this->written('work-requested')->link);
    }

    #[DataProvider('shippedKeys')]
    public function test_queued_work_never_shows_two_waiting_lines(string $key): void
    {
        $facts = FactsMother::facts(
            card: FactsMother::card(slot: 'implementation'),
            run: FactsMother::run(activeWorkKinds: ['implement', 'fix']),
            provided: [WorkTakerFacts::class => new WorkTakerFacts([
                'implement' => new WorkTakerReading(WorkTakerState::Paused, new \DateTimeImmutable('2026-10-01 11:00:00'), null),
                'fix' => new WorkTakerReading(WorkTakerState::None, new \DateTimeImmutable('2026-10-01 11:00:00'), null),
            ])],
        );

        self::assertSame(['no-bridge-online'], $this->passKeys($this->template($key), $facts));
    }

    public function test_failing_checks_are_stuck_until_a_fix_is_under_way(): void
    {
        $pullRequest = FactsMother::pullRequest(checks: ChecksState::Failed, number: 12, checksFailedSince: new \DateTimeImmutable('2026-10-01 10:00:00'));
        self::assertSame(['checks-failed'], $this->keys($this->workFacts('implementation', $pullRequest)));
        self::assertSame(['%number%' => 12], $this->written('checks-failed')->params);
        self::assertEquals(new \DateTimeImmutable('2026-10-01 10:00:00'), $this->written('checks-failed')->since);
        self::assertSame(['work-requested'], $this->keys($this->workFacts('implementation', $pullRequest, ['fix'])));
        self::assertSame(['run-open'], $this->keys($this->workFacts('implementation', $pullRequest, [], ['repair'])));
        self::assertSame(['checks-failed', 'work-requested'], $this->keys($this->workFacts('implementation', $pullRequest, ['review'])));
    }

    public function test_a_conflict_is_stuck_until_a_fix_is_under_way(): void
    {
        $pullRequest = FactsMother::pullRequest(conflicting: true, number: 3);

        self::assertSame(['conflicting'], $this->keys($this->workFacts('in-review', $pullRequest)));
        self::assertSame(['work-requested'], $this->keys($this->workFacts('in-review', $pullRequest, ['fix'])));
    }

    public function test_a_closed_pull_request_with_failing_checks_shows_nothing(): void
    {
        $pullRequest = FactsMother::pullRequest(state: PullRequestState::Merged, checks: ChecksState::Failed);

        self::assertSame([], $this->keys(FactsMother::facts(card: FactsMother::card(slot: 'in-review'), pullRequest: $pullRequest, pullRequests: [$pullRequest])));
    }

    public function test_a_ready_pull_request_is_stuck_after_the_delay_unless_a_merge_is_under_way(): void
    {
        $ready = static fn (string $since): PullRequestFacts => FactsMother::pullRequest(checks: ChecksState::Passed, number: 5, readyToMerge: true, readySince: new \DateTimeImmutable($since));

        self::assertSame(['ready-not-merged'], $this->keys($this->workFacts('in-review', $ready('2026-10-01 11:00:00'))));
        self::assertSame([], $this->keys($this->workFacts('in-review', $ready('2026-10-01 11:55:00'))), 'the delay has not passed');
        self::assertEquals(new \DateTimeImmutable('2026-10-01 12:10:00'), $this->writtenDueAt);
        self::assertSame(['work-requested'], $this->keys($this->workFacts('in-review', $ready('2026-10-01 11:00:00'), ['merge'])));
    }

    public function test_pending_checks_wait_and_hide_the_idle_marker(): void
    {
        $pullRequest = FactsMother::pullRequest(checks: ChecksState::Pending, number: 8);
        $facts = FactsMother::facts(
            card: FactsMother::card(slot: 'in-review'),
            pullRequest: $pullRequest,
            pullRequests: [$pullRequest],
            provided: [CardActivityFacts::class => new CardActivityFacts(new \DateTimeImmutable('2026-10-01 09:00:00'))],
        );

        self::assertSame(['waiting-on-checks'], $this->keys($facts));
        self::assertSame(['%number%' => 8], $this->written('waiting-on-checks')->params);
    }

    public function test_a_card_that_nothing_moves_shows_the_idle_marker_of_its_pull_request_first(): void
    {
        $quiet = [CardActivityFacts::class => new CardActivityFacts(new \DateTimeImmutable('2026-10-01 09:00:00'))];
        $pullRequest = FactsMother::pullRequest(checks: ChecksState::Passed, number: 9);

        self::assertSame(['pull-request-idle'], $this->keys(FactsMother::facts(card: FactsMother::card(slot: 'in-review'), pullRequest: $pullRequest, pullRequests: [$pullRequest], provided: $quiet)));
        self::assertSame(['%number%' => 9], $this->written('pull-request-idle')->params);
        self::assertSame(['nothing-happened'], $this->keys(FactsMother::facts(card: FactsMother::card(slot: 'tech-design'), provided: $quiet)));
        self::assertSame([], $this->keys(FactsMother::facts(card: FactsMother::card(slot: 'next'), provided: $quiet)), 'the Next column stays silent');
        self::assertSame(['nothing-happened'], $this->keys(FactsMother::facts(card: FactsMother::card(slot: 'implementation', childCount: 1, openChildCount: 1), pullRequest: $pullRequest, pullRequests: [$pullRequest], provided: $quiet)), 'an open child hides the pull request idle marker only');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function childKinds(): iterable
    {
        foreach (['lifecycle', 'simple'] as $template) {
            yield $template.', stuck' => [$template, 'stuck', 'child-stuck'];
            yield $template.', needs you' => [$template, 'needs-you', 'child-needs-you'];
            yield $template.', working' => [$template, 'working', 'child-working'];
            yield $template.', waiting' => [$template, 'waiting', 'child-waiting'];
        }
    }

    #[DataProvider('childKinds')]
    public function test_an_epic_shows_the_state_kind_of_a_child_with_the_child_and_its_start(string $template, string $kind, string $key): void
    {
        $since = new \DateTimeImmutable('2026-10-01 10:00:00');
        $facts = FactsMother::facts(card: FactsMother::card(slot: 'implementation', type: 'epic', childCount: 2, openChildCount: 2), provided: [
            ChildrenFacts::class => new ChildrenFacts(2, 2, childMarks: [$kind => new ChildMark(31, 'Build it', $since)]),
        ]);

        $this->pass($this->template($template), $facts);

        self::assertSame([$key], $this->writtenKeys());
        self::assertSame(['%number%' => 31, '%title%' => 'Build it'], $this->written($key)->params);
        self::assertEquals($since, $this->written($key)->since);
    }

    public function test_an_epic_with_children_in_several_states_shows_the_stuck_one_first(): void
    {
        $mark = new ChildMark(31, 'Build it', new \DateTimeImmutable('2026-10-01 10:00:00'));
        $facts = FactsMother::facts(card: FactsMother::card(slot: 'implementation', type: 'epic', childCount: 2, openChildCount: 2), provided: [
            ChildrenFacts::class => new ChildrenFacts(2, 2, childMarks: ['working' => $mark, 'stuck' => $mark]),
        ]);

        self::assertSame(['child-stuck', 'child-working'], $this->keys($facts));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function busyChildKinds(): iterable
    {
        yield 'working' => ['working', ['child-working']];
        yield 'waiting' => ['waiting', ['child-waiting']];
    }

    /** @param list<string> $expected */
    #[DataProvider('busyChildKinds')]
    public function test_a_quiet_epic_with_a_busy_child_is_not_called_idle(string $kind, array $expected): void
    {
        $quiet = new CardActivityFacts(new \DateTimeImmutable('2026-10-01 09:00:00'));
        $mark = new ChildMark(31, 'Build it', new \DateTimeImmutable('2026-10-01 10:00:00'));
        $epic = FactsMother::card(slot: 'implementation', type: 'epic', childCount: 1, openChildCount: 1);

        self::assertSame(['nothing-happened'], $this->keys(FactsMother::facts(card: $epic, provided: [CardActivityFacts::class => $quiet])), 'a quiet epic with quiet children is idle');
        self::assertSame($expected, $this->keys(FactsMother::facts(card: $epic, provided: [CardActivityFacts::class => $quiet, ChildrenFacts::class => new ChildrenFacts(1, 1, childMarks: [$kind => $mark])])));
    }

    public function test_the_idle_marker_gives_way_to_a_busy_child_on_its_own_condition(): void
    {
        $marker = array_find($this->template('lifecycle')->markers, static fn ($marker): bool => 'nothing-happened' === $marker->key);
        self::assertNotNull($marker);
        $quiet = new CardActivityFacts(new \DateTimeImmutable('2026-10-01 09:00:00'));
        $mark = new ChildMark(31, 'Build it', new \DateTimeImmutable('2026-10-01 10:00:00'));
        $epic = FactsMother::card(slot: 'implementation', type: 'epic', childCount: 1, openChildCount: 1);

        foreach (['working' => false, 'waiting' => false, 'stuck' => true, 'needs-you' => true] as $kind => $idle) {
            $facts = FactsMother::facts(card: $epic, provided: [CardActivityFacts::class => $quiet, ChildrenFacts::class => new ChildrenFacts(1, 1, childMarks: [$kind => $mark])]);
            self::assertSame($idle, $marker->when->evaluate($facts), $kind);
        }
    }

    public function test_a_held_card_is_not_called_idle_and_shows_the_chip(): void
    {
        $facts = FactsMother::facts(card: FactsMother::card(slot: 'implementation'), provided: [
            CardActivityFacts::class => new CardActivityFacts(new \DateTimeImmutable('2026-10-01 09:00:00')),
            HoldFacts::class => new HoldFacts(new \DateTimeImmutable('2026-10-01 08:00:00')),
        ]);

        self::assertSame(['unmanaged'], $this->keys($facts));
        self::assertSame(MarkerKind::Chip, $this->written('unmanaged')->kind);
        self::assertEquals(new \DateTimeImmutable('2026-10-01 08:00:00'), $this->written('unmanaged')->since);
    }

    public function test_a_document_in_review_needs_the_person_only_in_the_slot_of_its_tag(): void
    {
        $review = static fn (string $tag): array => [new DocumentFacts([$tag], DocumentStatus::InReview->value, 'doc', 'The design', new \DateTimeImmutable('2026-10-01 10:00:00'))];

        self::assertSame(['document-in-review'], $this->keys(FactsMother::facts(card: FactsMother::card(slot: 'tech-design', documents: $review('tech-design')))));
        self::assertSame(['%title%' => 'The design'], $this->written('document-in-review')->params);
        self::assertSame(['document-in-review'], $this->keys(FactsMother::facts(card: FactsMother::card(slot: 'product-design', documents: $review('product-design')))));
        self::assertSame([], $this->keys(FactsMother::facts(card: FactsMother::card(slot: 'tech-design', documents: $review('product-design')))));
        self::assertSame([], $this->keys(FactsMother::facts(card: FactsMother::card(slot: 'implementation', documents: $review('tech-design')))));
    }

    public function test_a_draft_design_in_its_agent_review_shows_the_round_in_its_slot_only(): void
    {
        $facts = static fn (string $slot, string $tag): Facts => FactsMother::facts(
            card: FactsMother::card(slot: $slot),
            provided: [DocumentReviewFacts::class => new DocumentReviewFacts([new DocumentReviewState('doc', [$tag], 2, null, null, null, false, false, 1)])],
        );

        $this->pass($this->template('lifecycle'), $facts('tech-design', 'tech-design'));
        self::assertSame(['design-review-running'], $this->writtenKeys());
        self::assertSame(['%round%' => 2, '%limit%' => 6], $this->written('design-review-running')->params);

        self::assertSame(['design-review-running'], $this->keys($facts('product-design', 'product-design')));
        self::assertSame([], $this->keys($facts('product-design', 'tech-design')));
        self::assertSame([], $this->keys($facts('implementation', 'tech-design')));
    }

    public function test_a_pull_request_that_waits_for_approval_and_an_open_question_need_the_person(): void
    {
        $pullRequest = FactsMother::pullRequest(checks: ChecksState::Passed, number: 4, waitsForApproval: true, waitsForApprovalSince: new \DateTimeImmutable('2026-10-01 10:30:00'));
        $question = new OpenQuestionFacts([new OpenQuestion(6, 'Which way?', new \DateTimeImmutable('2026-10-01 10:00:00'), blocking: true)]);

        $this->pass($this->template('lifecycle'), FactsMother::facts(card: FactsMother::card(slot: 'in-review'), pullRequest: $pullRequest, pullRequests: [$pullRequest], provided: [OpenQuestionFacts::class => $question]));

        self::assertSame(['waits-for-approval', 'open-question'], $this->writtenKeys());
        self::assertSame(['%number%' => 6, '%title%' => 'Which way?'], $this->written('open-question')->params);
    }

    public function test_a_non_blocking_to_do_alone_needs_nobody_in_either_shipped_template(): void
    {
        $todo = new OpenQuestionFacts([new OpenQuestion(929, 'Rebuild the Design System artifact', new \DateTimeImmutable('2026-10-01 10:00:00'), blocking: false)]);
        $both = new OpenQuestionFacts([...$todo->questions, new OpenQuestion(931, 'Which way?', new \DateTimeImmutable('2026-10-01 11:00:00'), blocking: true)]);

        foreach (['lifecycle', 'simple'] as $key) {
            self::assertNotContains('open-question', $this->passKeys($this->template($key), FactsMother::facts(provided: [OpenQuestionFacts::class => $todo])), $key);
            $this->pass($this->template($key), FactsMother::facts(provided: [OpenQuestionFacts::class => $both]));
            self::assertSame(['%number%' => 931, '%title%' => 'Which way?'], $this->written('open-question')->params, $key);
        }
    }

    public function test_work_shows_as_working_with_the_session_first(): void
    {
        $facts = FactsMother::facts(
            card: FactsMother::card(slot: 'implementation'),
            run: FactsMother::run(activeWorkKinds: ['implement'], activeWorkerKinds: ['implement']),
            provided: [SessionFacts::class => new SessionFacts(new \DateTimeImmutable('2026-10-01 11:00:00'), 'claude')],
        );

        self::assertSame(['session-open', 'run-open', 'work-requested'], $this->keys($facts));
    }

    public function test_a_pending_request_to_the_forge_is_working(): void
    {
        $pullRequest = FactsMother::pullRequest(number: 2, forgeRequests: [new ForgeRequest(new \DateTimeImmutable('2026-10-01 11:59:00'), new \DateTimeImmutable('2026-10-01 12:05:00'))]);

        self::assertSame(['forge-request-pending'], $this->keys(FactsMother::facts(card: FactsMother::card(slot: 'in-review'), pullRequest: $pullRequest, pullRequests: [$pullRequest])));
        self::assertEquals(new \DateTimeImmutable('2026-10-01 12:05:00'), $this->writtenDueAt);
    }

    public function test_a_blocker_and_a_stacked_pull_request_wait(): void
    {
        $blocker = new BlockerHoldFacts(new BlockerHold('card', new \DateTimeImmutable('2026-10-01 10:00:00'), 31, 'The base'));
        self::assertSame(['held-by-blocker'], $this->keys(FactsMother::facts(card: FactsMother::card(slot: 'next'), provided: [BlockerHoldFacts::class => $blocker])));
        self::assertSame(['%number%' => 31, '%title%' => 'The base'], $this->written('held-by-blocker')->params);

        $ready = static fn (StackParent $parent): PullRequestFacts => FactsMother::pullRequest(checks: ChecksState::Passed, baseIsMergeTarget: false, stacked: true, number: 5, stackParent: $parent, readyToMerge: true, readySince: new \DateTimeImmutable('2026-10-01 11:00:00'));
        $known = $ready(new StackParent('feature/a', 4, 20));
        self::assertSame(['waits-for-parent-card'], $this->keys(FactsMother::facts(card: FactsMother::card(slot: 'in-review'), pullRequest: $known, pullRequests: [$known])));
        self::assertSame(['%number%' => 5, '%parent_number%' => 20, '%pull_request%' => 4], $this->written('waits-for-parent-card')->params);
        $unknown = $ready(new StackParent('feature/a'));
        self::assertSame(['waits-for-parent-branch'], $this->keys(FactsMother::facts(card: FactsMother::card(slot: 'in-review'), pullRequest: $unknown, pullRequests: [$unknown])));
        self::assertSame(['%number%' => 5, '%branch%' => 'feature/a'], $this->written('waits-for-parent-branch')->params);
    }

    public function test_a_card_in_a_terminal_column_shows_nothing(): void
    {
        $pullRequest = FactsMother::pullRequest(checks: ChecksState::Failed);

        self::assertSame([], $this->keys(FactsMother::facts(card: FactsMother::card(slot: '@terminal'), pullRequest: $pullRequest, pullRequests: [$pullRequest])));
    }

    public function test_the_simple_template_shows_the_markers_it_ships_in_a_card_with_no_slot(): void
    {
        $pullRequest = FactsMother::pullRequest(checks: ChecksState::Failed, number: 1);
        $facts = FactsMother::facts(pullRequest: $pullRequest, pullRequests: [$pullRequest], provided: [HoldFacts::class => new HoldFacts(new \DateTimeImmutable('2026-10-01 08:00:00'))]);

        $this->pass($this->template('simple'), $facts);

        self::assertSame(['checks-failed', 'unmanaged'], $this->writtenKeys());
    }

    /**
     * @param list<string> $work    the kinds of the open work requests
     * @param list<string> $workers the kinds of the open worker runs
     */
    private function workFacts(string $slot, PullRequestFacts $pullRequest, array $work = [], array $workers = []): Facts
    {
        return FactsMother::facts(
            card: FactsMother::card(slot: $slot),
            pullRequest: $pullRequest,
            pullRequests: [$pullRequest],
            run: FactsMother::run(activeWorkKinds: $work, activeWorkerKinds: $workers),
        );
    }

    /**
     * @param list<string> $work    the kinds of the open work requests
     * @param list<string> $workers the kinds of the open worker runs
     */
    private function stoppedFacts(array $work = [], array $workers = []): Facts
    {
        return FactsMother::facts(
            card: FactsMother::card(slot: 'implementation'),
            run: FactsMother::run(activeWorkKinds: $work, activeWorkerKinds: $workers),
            provided: [RunStoppedFacts::class => new RunStoppedFacts(new CardRunWarning('run', WorkerRunState::GaveUp, 'summary', new \DateTimeImmutable('2026-10-01 10:00:00')), null)],
        );
    }

    /** @param list<string> $work the kinds of the open work requests */
    private function takerFacts(WorkTakerState $state, array $work): Facts
    {
        return FactsMother::facts(
            card: FactsMother::card(slot: 'implementation'),
            run: FactsMother::run(activeWorkKinds: $work),
            provided: [WorkTakerFacts::class => new WorkTakerFacts(array_fill_keys($work, new WorkTakerReading($state, new \DateTimeImmutable('2026-10-01 11:00:00'), null)))],
        );
    }

    /** @return list<string> */
    private function keys(Facts $facts): array
    {
        return $this->passKeys($this->template('lifecycle'), $facts);
    }

    /** @return list<string> */
    private function writtenKeys(): array
    {
        return array_map(static fn ($marker): string => $marker->key, $this->written ?? []);
    }

    private function template(string $key): Template
    {
        self::bootKernel();
        $shipped = self::getContainer()->get(ShippedTemplates::class);
        self::assertInstanceOf(ShippedTemplates::class, $shipped);
        $parser = self::getContainer()->get(TemplateParser::class);
        self::assertInstanceOf(TemplateParser::class, $parser);

        return $parser->parse($shipped->source($key));
    }
}
