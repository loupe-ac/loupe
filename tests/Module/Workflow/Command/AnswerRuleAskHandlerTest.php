<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Command;

use App\Module\Account\Repository\UserRepository;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardValue;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardValueRepository;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\PublishDocumentCommand;
use App\Module\Review\Command\PublishDocumentHandler;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Command\AnswerRuleAskCommand;
use App\Module\Workflow\Command\AnswerRuleAskHandler;
use App\Module\Workflow\Command\EvaluateWorkflowCardHandler;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Entity\WorkflowBinding;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Entity\WorkflowSlotLink;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Messenger\EvaluateCardHandler;
use App\Module\Workflow\Repository\WorkflowQueuedEvaluationRepository;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Tests\Module\Workflow\Action\ActionScenario;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class AnswerRuleAskHandlerTest extends KernelTestCase
{
    use ActionScenario;

    private RecordingAuditor $audit;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->audit = RecordingAuditor::installedIn(self::getContainer());
    }

    public function test_the_link_option_links_the_parent_design_keeps_the_item_id_and_queues_an_evaluation(): void
    {
        [$card, $parent, $itemId] = $this->askedChild('answer-link');
        $design = $this->document($parent, 'tech-design');

        $this->answer($itemId, 0);

        self::assertSame([(string) $design->id], $this->linkedIds($card));
        $state = $this->state_($card);
        self::assertEquals($itemId, $state->askItemId);
        self::assertNull($state->lastRefusal);
        self::assertContains($card->id?->toRfc4122(), $this->queuedEvaluations());
        self::assertContains('workflow.ask_answered', array_column($this->audit->sink->events, 'operation'));
    }

    public function test_the_detach_option_removes_the_parent(): void
    {
        [$card, , $itemId] = $this->askedChild('answer-detach');

        $this->answer($itemId, 1);

        $this->em()->clear();
        self::assertNull($this->em()->find(Card::class, $card->id)?->parent);
        self::assertContains($card->id?->toRfc4122(), $this->queuedEvaluations());
    }

    public function test_the_options_actions_run_in_order(): void
    {
        [$card, $parent, $itemId] = $this->askedChild('answer-order');
        $design = $this->document($parent, 'tech-design');

        $this->answer($itemId, 2);

        $this->em()->clear();
        $reloaded = $this->em()->find(Card::class, $card->id) ?? throw new \LogicException('The card exists.');
        self::assertSame('in-progress', $reloaded->column->slug);
        self::assertSame([(string) $design->id], $this->linkedIds($card));
    }

    public function test_a_refusal_stops_the_option_and_stays_on_the_rule_state(): void
    {
        [$card, , $itemId] = $this->askedChild('answer-refused');

        $this->answer($itemId, 2);

        $this->em()->clear();
        $reloaded = $this->em()->find(Card::class, $card->id) ?? throw new \LogicException('The card exists.');
        self::assertSame('next', $reloaded->column->slug);
        $state = $this->state_($card);
        self::assertSame('no-parent-document', $state->lastRefusal);
        self::assertNotNull($state->lastRefusalAt);
        self::assertEquals($itemId, $state->askItemId);
        self::assertContains($card->id?->toRfc4122(), $this->queuedEvaluations());
        self::assertContains('workflow.ask_answered', array_column($this->audit->sink->events, 'operation'));
    }

    public function test_an_item_that_no_rule_state_holds_changes_nothing(): void
    {
        [$card, $parent] = $this->askedChild('answer-unknown');
        $this->document($parent, 'tech-design');

        $this->answer(Uuid::v7(), 0);

        self::assertSame([], $this->linkedIds($card));
        self::assertSame([], $this->queuedEvaluations());
    }

    public function test_an_item_the_engine_withdrew_changes_nothing(): void
    {
        [$card, $parent, $itemId] = $this->askedChild('answer-withdrawn');
        $this->document($parent, 'tech-design');
        $this->state_($card)->askItemId = null;
        $this->em()->flush();

        $this->answer($itemId, 0);

        self::assertSame([], $this->linkedIds($card));
        self::assertSame([], $this->queuedEvaluations());
    }

    public function test_an_option_the_rule_lacks_changes_nothing(): void
    {
        [$card, $parent, $itemId] = $this->askedChild('answer-range');
        $this->document($parent, 'tech-design');

        $this->answer($itemId, 7);

        self::assertSame([], $this->linkedIds($card));
        self::assertSame([], $this->queuedEvaluations());
    }

    public function test_a_held_card_changes_nothing(): void
    {
        [$card, $parent, $itemId] = $this->askedChild('answer-held');
        $this->document($parent, 'tech-design');
        $this->service(CardHolds::class)->hold($card->project, $card->id ?? throw new \LogicException('A flushed card has an id.'), null);

        $this->answer($itemId, 0);

        self::assertSame([], $this->linkedIds($card));
        self::assertSame([], $this->queuedEvaluations());
    }

    public function test_a_card_that_left_the_slot_of_the_rule_changes_nothing(): void
    {
        [$card, , $itemId] = $this->askedChild('answer-left-slot');
        $card->column = $this->column($card->project, 'in-progress');
        $this->em()->flush();

        $this->answer($itemId, 1);

        $this->em()->clear();
        self::assertNotNull($this->em()->find(Card::class, $card->id)?->parent);
        self::assertSame([], $this->queuedEvaluations());
    }

    public function test_a_rule_that_stopped_holding_changes_nothing(): void
    {
        [$card, , $itemId] = $this->askedChild('answer-rule-false', ['all' => [['card.parent.exists' => []], ['not' => ['card.parent.exists' => []]]]]);

        $this->answer($itemId, 1);

        $this->em()->clear();
        self::assertNotNull($this->em()->find(Card::class, $card->id)?->parent);
    }

    public function test_the_start_answer_of_the_session_ask_stores_the_approval_and_the_session_request_follows(): void
    {
        [$card, $itemId] = $this->askedSession('answer-session-start');
        self::assertSame([], $this->requestsOf($card, 'tech-design-session'), 'The ask holds the session back.');

        $this->answer($itemId, 0);

        self::assertTrue($this->value($card, 'session-start-approved'));
        self::assertTrue($this->value($card, 'tech-design-interactive'), 'The switch stays on.');
        self::assertNull($this->state_($card, 'tech-design-session-ask')->lastRefusal);
        self::assertContains($card->id?->toRfc4122(), $this->queuedEvaluations());

        $this->evaluate($card);

        self::assertCount(1, $this->requestsOf($card, 'tech-design-session'));
        self::assertSame([], $this->requestsOf($card, 'tech-design-write'));
    }

    public function test_the_agent_answer_of_the_session_ask_turns_the_switch_off_and_the_agent_request_follows(): void
    {
        [$card, $itemId] = $this->askedSession('answer-session-agent');

        $this->answer($itemId, 1);

        self::assertFalse($this->value($card, 'tech-design-interactive'));
        self::assertNull($this->value($card, 'session-start-approved'), 'The agent answer writes no start approval.');
        self::assertNull($this->state_($card, 'tech-design-session-ask')->lastRefusal);
        self::assertContains($card->id?->toRfc4122(), $this->queuedEvaluations());

        $this->evaluate($card);

        self::assertCount(1, $this->requestsOf($card, 'tech-design-write'));
        self::assertSame([], $this->requestsOf($card, 'tech-design-session'));
    }

    private function answer(Uuid $itemId, int $optionIndex): void
    {
        $this->em()->clear();
        $transport = self::getContainer()->get('messenger.transport.card_eval');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        ($this->service(AnswerRuleAskHandler::class))(new AnswerRuleAskCommand($itemId->toRfc4122(), $optionIndex));
    }

    /**
     * @param array<string, mixed> $when
     *
     * @return array{Card, Card, Uuid} the child, its parent and the item id that the child's rule state holds
     */
    private function askedChild(string $name, array $when = ['all' => []]): array
    {
        $project = $this->workflowProject($name);
        $this->bind($project, $when);
        $parent = $this->card($project, 'next');
        $card = $this->card($project, 'next');
        $card->parent = $parent;
        $itemId = Uuid::v7();
        $state = new WorkflowRuleState($card->id ?? throw new \LogicException('The card is persisted.'), $project, 'unplanned-child');
        $state->truth = true;
        $state->askItemId = $itemId;
        $this->em()->persist($state);
        $this->em()->flush();

        return [$card, $parent, $itemId];
    }

    /** @param array<string, mixed> $when */
    private function bind(Project $project, array $when = ['all' => []]): void
    {
        $this->em()->persist(new WorkflowBinding($project, 'test', '1', [
            'key' => 'test',
            'version' => 1,
            'defaultType' => 'feature',
            'types' => [['key' => 'feature', 'label' => 'board.card.type.feature', 'tone' => 'lime']],
            'slots' => [['key' => 'one', 'label' => 'one'], ['key' => 'two', 'label' => 'two']],
            'manualMoves' => [],
            'backoffMinutes' => [],
            'rules' => [[
                'id' => 'unplanned-child',
                'slot' => 'one',
                'when' => $when,
                'then' => ['ask' => [
                    'question' => 'Question',
                    'options' => [
                        ['label' => 'Link', 'then' => [['link-document' => ['from' => 'parent', 'tag' => 'tech-design']]]],
                        ['label' => 'Detach', 'then' => [['detach' => []]]],
                        ['label' => 'Link and move', 'then' => [['link-document' => ['from' => 'parent', 'tag' => 'tech-design']], ['move' => ['to' => 'two']]]],
                    ],
                ]],
            ]],
        ]));
        $this->em()->persist(new WorkflowSlotLink($project, 'one', $this->column($project, 'next')->id));
        $this->em()->persist(new WorkflowSlotLink($project, 'two', $this->column($project, 'in-progress')->id));
        $this->em()->flush();
    }

    private function state_(Card $card, ?string $ruleId = null): WorkflowRuleState
    {
        $this->em()->clear();
        $criteria = null === $ruleId ? ['cardId' => $card->id] : ['cardId' => $card->id, 'ruleId' => $ruleId];

        return $this->service(WorkflowRuleStateRepository::class)->findOneBy($criteria) ?? throw new \LogicException('The rule state exists.');
    }

    /**
     * A Lifecycle card in Tech design with the switch on and a product design that the Workflow account approved,
     * after the engine opened the session ask.
     *
     * @return array{Card, Uuid} the card and the item id of the ask
     */
    private function askedSession(string $name): array
    {
        $this->enableInbox();
        $project = $this->workflowProject($name);
        $this->bindLifecycle($project);
        $card = $this->card($project, 'tech-design');
        $this->service(CardEventRepository::class)->record($card, CardEventKind::Moved, Actor::Human, $project->owner, [], new \DateTimeImmutable('-2 hours'));
        $this->em()->persist(new CardValue($card, 'tech-design-interactive', true, Actor::Human, $project->owner, new \DateTimeImmutable('-1 hour')));
        $document = $this->service(CreateDocumentHandler::class)(new CreateDocumentCommand($project, 'Product design', "# Design\n\nNothing to decide.", tagNames: ['product-design'], draft: true));
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->flush();
        $this->service(PublishDocumentHandler::class)(new PublishDocumentCommand($document));
        $this->service(SubmitReviewHandler::class)(new SubmitReviewCommand($this->service(UserRepository::class)->workflow(), $document, 'approved', 1, 'Auto.', workflowRule: 'product-design-auto-approve'));

        $this->evaluate($card);

        $itemId = $this->state_($card, 'tech-design-session-ask')->askItemId;
        self::assertNotNull($itemId, 'The engine opened the ask.');

        return [$card, $itemId];
    }

    private function enableInbox(): void
    {
        $this->service(FeatureFlagRepository::class)->findAllIndexed()[InboxInstallFlags::FLAG_INBOX_ENABLED]->value = true;
        $this->em()->flush();
        $reader = $this->service(FeatureFlagReaderInterface::class);
        self::assertInstanceOf(ResetInterface::class, $reader);
        $reader->reset();
    }

    private function evaluate(Card $card): void
    {
        $handler = new EvaluateCardHandler(new EvaluateWorkflowCardHandler($this->service(Engine::class), new MockClock()), $this->service(WorkflowQueuedEvaluationRepository::class), new EventDispatcher());
        $handler(new EvaluateCard((string) $card->id));
        $this->em()->flush();
    }

    private function value(Card $card, string $key): mixed
    {
        $this->em()->clear();
        $reloaded = $this->em()->find(Card::class, $card->id) ?? throw new \LogicException('The card exists.');

        return $this->service(CardValueRepository::class)->findOneForCardFresh($reloaded, $key)?->value;
    }

    /** @return list<WorkRequest> */
    private function requestsOf(Card $card, string $ruleId): array
    {
        return $this->service(WorkRequestRepository::class)->findBy(['subjectType' => WorkSubject::CARD, 'subjectId' => $card->id, 'ruleId' => $ruleId]);
    }

    /** @return list<string> */
    private function linkedIds(Card $card): array
    {
        $this->em()->clear();

        return array_map(
            static fn (array $row): string => $row['id'],
            $this->service(CardDocumentRepository::class)->findStatusesAndTagsForCard($this->em()->find(Card::class, $card->id) ?? throw new \LogicException('The card exists.')),
        );
    }

    /** @return list<string> */
    private function queuedEvaluations(): array
    {
        $transport = self::getContainer()->get('messenger.transport.card_eval');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $ids = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof EvaluateCard) {
                $ids[] = $message->cardId;
            }
        }

        return $ids;
    }

    private function document(Card $card, string $tagName): Document
    {
        $document = new Document($card->project->owner, $card->project, 'Design');
        $document->status = DocumentStatus::InReview;
        $tag = new Tag($card->project, $tagName);
        $this->em()->persist($tag);
        $document->tags->add($tag);
        $this->em()->persist($document);
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->flush();

        return $document;
    }
}
