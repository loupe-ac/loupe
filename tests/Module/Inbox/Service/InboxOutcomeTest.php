<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReviewVerdict;
use App\Module\Inbox\Repository\InboxReviewRepository;
use App\Module\Inbox\Service\InboxOutcome;
use App\Module\Inbox\Service\InboxReviewLookup;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InboxOutcomeTest extends TestCase
{
    /** @return iterable<string, array{string, InboxItemKind, InboxItemState, ?InboxCloser, ?InboxCloseCause, ?InboxReviewVerdict}> */
    public static function labels(): iterable
    {
        $resolved = InboxItemState::Resolved;
        $cancelled = InboxItemState::Cancelled;

        yield 'owner answered a question' => ['inbox.outcome.owner.answered', InboxItemKind::Question, $resolved, InboxCloser::Owner, null, null];
        yield 'owner answered a workflow question' => ['inbox.outcome.owner.answered', InboxItemKind::Workflow, $resolved, InboxCloser::Owner, null, null];
        yield 'owner finished a to-do' => ['inbox.outcome.owner.done', InboxItemKind::Todo, $resolved, InboxCloser::Owner, null, null];
        yield 'owner approved' => ['inbox.outcome.owner.approved', InboxItemKind::Review, $resolved, InboxCloser::Owner, null, InboxReviewVerdict::Approved];
        yield 'owner requested changes' => ['inbox.outcome.owner.changes_requested', InboxItemKind::Review, $resolved, InboxCloser::Owner, null, InboxReviewVerdict::ChangesRequested];
        yield 'owner closed a review with no verdict' => ['inbox.outcome.owner.done', InboxItemKind::Review, $resolved, InboxCloser::Owner, null, null];
        yield 'agent answered for the owner' => ['inbox.outcome.agent_for_owner.answered', InboxItemKind::Question, $resolved, InboxCloser::OwnerThroughAgent, null, null];
        yield 'agent finished a to-do for the owner' => ['inbox.outcome.agent_for_owner.done', InboxItemKind::Todo, $resolved, InboxCloser::OwnerThroughAgent, null, null];
        yield 'agent approved for the owner' => ['inbox.outcome.agent_for_owner.approved', InboxItemKind::Review, $resolved, InboxCloser::OwnerThroughAgent, null, InboxReviewVerdict::Approved];
        yield 'agent requested changes for the owner' => ['inbox.outcome.agent_for_owner.changes_requested', InboxItemKind::Review, $resolved, InboxCloser::OwnerThroughAgent, null, InboxReviewVerdict::ChangesRequested];
        yield 'rule approved' => ['inbox.outcome.rule.approved', InboxItemKind::Review, $resolved, InboxCloser::Rule, null, InboxReviewVerdict::Approved];
        yield 'rule requested changes' => ['inbox.outcome.rule.changes_requested', InboxItemKind::Review, $resolved, InboxCloser::Rule, null, InboxReviewVerdict::ChangesRequested];
        yield 'rule resolved with no verdict' => ['inbox.outcome.rule.resolved', InboxItemKind::Review, $resolved, InboxCloser::Rule, null, null];
        yield 'loupe command succeeded' => ['inbox.outcome.loupe.command_succeeded', InboxItemKind::Todo, $resolved, InboxCloser::Loupe, InboxCloseCause::CommandSucceeded, null];
        yield 'loupe cause ended' => ['inbox.outcome.loupe.resolved', InboxItemKind::Wait, $resolved, InboxCloser::Loupe, InboxCloseCause::CauseEnded, null];
        yield 'loupe cause ended on a review' => ['inbox.outcome.loupe.resolved', InboxItemKind::Review, $resolved, InboxCloser::Loupe, InboxCloseCause::CauseEnded, null];
        yield 'loupe fact ended' => ['inbox.outcome.loupe.resolved', InboxItemKind::Notice, $resolved, InboxCloser::Loupe, InboxCloseCause::FactEnded, null];
        yield 'owner dismissed' => ['inbox.outcome.owner.dismissed', InboxItemKind::Question, InboxItemState::Declined, InboxCloser::Owner, null, null];
        yield 'agent dismissed for the owner' => ['inbox.outcome.agent_for_owner.dismissed', InboxItemKind::Todo, InboxItemState::Declined, InboxCloser::OwnerThroughAgent, null, null];
        yield 'agent withdrew' => ['inbox.outcome.agent.withdrawn', InboxItemKind::Question, $cancelled, InboxCloser::Agent, InboxCloseCause::Withdrawn, null];
        yield 'card deleted' => ['inbox.outcome.loupe.card_deleted', InboxItemKind::Question, $cancelled, InboxCloser::Loupe, InboxCloseCause::CardDeleted, null];
        yield 'card finished' => ['inbox.outcome.loupe.card_finished', InboxItemKind::Wait, $cancelled, InboxCloser::Loupe, InboxCloseCause::CardFinished, null];
        yield 'rule no longer asks' => ['inbox.outcome.loupe.rule_withdrew', InboxItemKind::Workflow, $cancelled, InboxCloser::Rule, InboxCloseCause::RuleWithdrew, null];
        yield 'switched off' => ['inbox.outcome.loupe.switched_off', InboxItemKind::Review, $cancelled, InboxCloser::Loupe, InboxCloseCause::SwitchedOff, null];
        yield 'fact ended without recovery' => ['inbox.outcome.loupe.cancelled', InboxItemKind::Notice, $cancelled, InboxCloser::Loupe, InboxCloseCause::FactEnded, null];
        yield 'cause ended on a cancelled wait' => ['inbox.outcome.loupe.cancelled', InboxItemKind::Wait, $cancelled, InboxCloser::Loupe, InboxCloseCause::CauseEnded, null];
        yield 'a resolved item with no closer' => ['inbox.outcome.resolved', InboxItemKind::Todo, $resolved, null, null, null];
        yield 'a declined item with no closer' => ['inbox.outcome.declined', InboxItemKind::Todo, InboxItemState::Declined, null, null, null];
        yield 'a cancelled item with no closer' => ['inbox.outcome.cancelled', InboxItemKind::Todo, $cancelled, null, null, null];
    }

    #[DataProvider('labels')]
    public function test_a_closed_item_gets_the_label_of_its_close_record(string $key, InboxItemKind $kind, InboxItemState $state, ?InboxCloser $closer, ?InboxCloseCause $cause, ?InboxReviewVerdict $verdict): void
    {
        $item = $this->item($kind, $state, $closer, $cause);

        $label = $this->outcome()->label($item, $verdict);

        self::assertSame($key, $label?->key);
        self::assertSame([], $label->parameters);
    }

    public function test_an_open_item_has_no_label(): void
    {
        self::assertNull($this->outcome()->label($this->item(InboxItemKind::Question, InboxItemState::Open, null, null), null));
        self::assertNull($this->outcome()->forItem($this->item(InboxItemKind::Review, InboxItemState::Open, null, null)));
    }

    public function test_every_close_cause_has_a_label_for_a_cancelled_item(): void
    {
        foreach (InboxCloseCause::cases() as $cause) {
            $label = $this->outcome()->label($this->item(InboxItemKind::Wait, InboxItemState::Cancelled, InboxCloser::Loupe, $cause), null);

            self::assertStringStartsWith('inbox.outcome.', $label->key ?? '', $cause->value);
        }
    }

    /** @return iterable<string, array{string, InboxItemState, ?InboxCloser, ?InboxCloseCause}> */
    public static function shortLabels(): iterable
    {
        yield 'answered by the owner' => ['inbox.outcome.resolved', InboxItemState::Resolved, InboxCloser::Owner, null];
        yield 'done by Loupe after a command' => ['inbox.outcome.resolved', InboxItemState::Resolved, InboxCloser::Loupe, InboxCloseCause::CommandSucceeded];
        yield 'dismissed by the owner' => ['inbox.outcome.declined', InboxItemState::Declined, InboxCloser::Owner, null];
        yield 'cancelled when the card was deleted' => ['inbox.outcome.cancelled', InboxItemState::Cancelled, InboxCloser::Loupe, InboxCloseCause::CardDeleted];
        yield 'withdrawn by the agent' => ['inbox.outcome.cancelled', InboxItemState::Cancelled, InboxCloser::Agent, InboxCloseCause::Withdrawn];
    }

    #[DataProvider('shortLabels')]
    public function test_the_short_label_names_the_state_and_no_closer_or_cause(string $key, InboxItemState $state, ?InboxCloser $closer, ?InboxCloseCause $cause): void
    {
        $item = $this->item(InboxItemKind::Todo, $state, $closer, $cause);

        self::assertSame($key, $this->outcome()->shortFor($item)?->key);
        self::assertNull($this->outcome()->verdictFor($item));
    }

    public function test_an_open_item_has_no_short_label(): void
    {
        self::assertNull($this->outcome()->shortFor($this->item(InboxItemKind::Review, InboxItemState::Open, null, null)));
    }

    private function outcome(): InboxOutcome
    {
        return new InboxOutcome(new InboxReviewLookup($this->createStub(InboxReviewRepository::class)));
    }

    private function item(InboxItemKind $kind, InboxItemState $state, ?InboxCloser $closer, ?InboxCloseCause $cause): InboxItem
    {
        $project = new Project(new User(fullName: 'Riley Chen', email: 'riley@example.com', password: 'x'), 'outcome-test');
        $item = new InboxItem($project, 1, $kind, 'Title', false, origin: InboxAskOrigin::Agent);
        $item->state = $state;
        $item->closedBy = $closer;
        $item->closeCause = $cause;

        return $item;
    }
}
