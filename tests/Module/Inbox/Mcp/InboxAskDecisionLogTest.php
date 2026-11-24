<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Command\AskInboxCommand;
use App\Module\Inbox\Command\AskInboxHandler;
use App\Module\Inbox\Command\AskInboxItem;
use App\Module\Inbox\Entity\InboxDecision;
use App\Module\Inbox\Entity\InboxDecisionMadeBy;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Mcp\InboxAskTool;
use App\Module\Inbox\Repository\InboxDecisionRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** A logged ask leaves one decision entry on each card it names, and nothing else. */
final class InboxAskDecisionLogTest extends KernelTestCase
{
    use InboxToolScenario;
    use McpTokenScenario;
    use WorkflowProjects;

    private EntityManagerInterface $em;
    private InboxAskTool $tool;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->tool = $this->askTool();
        $this->enableInbox();
        $this->project = $this->workflowProject('inbox-ask-decision');
        $this->bindLifecycle($this->project);
        $this->actAsMcpTokenBoundTo($this->project);
    }

    public function test_a_logged_ask_writes_an_entry_on_the_card_it_names(): void
    {
        $card = $this->card($this->em, $this->project);
        $this->em->flush();

        ($this->tool)(sessionId: (string) Uuid::v4(), items: [$this->item('Which name?', [(string) $card->id])]);

        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertSame('naming', $entries[0]->label);
        self::assertSame('beta', $entries[0]->choice);
        self::assertSame(['alpha'], $entries[0]->otherOptions);
        self::assertSame(InboxDecisionMadeBy::Logged, $entries[0]->madeBy);
        self::assertNull($entries[0]->item);
        self::assertSame((string) $card->id, (string) $entries[0]->card->id);
    }

    public function test_an_ask_that_names_two_cards_writes_an_entry_for_each(): void
    {
        $first = $this->card($this->em, $this->project, 1);
        $second = $this->card($this->em, $this->project, 2);
        $this->em->flush();

        ($this->tool)(sessionId: (string) Uuid::v4(), items: [$this->item('Which name?', [(string) $first->id, (string) $second->id])]);

        self::assertCount(2, $this->entries());
    }

    public function test_an_ask_that_names_no_card_and_has_no_run_writes_no_entry(): void
    {
        $result = ($this->tool)(sessionId: (string) Uuid::v4(), items: [$this->item('Which name?')]);

        self::assertSame('Logged "Which name?" as naming. Go on with "beta".', $result['message']);
        self::assertSame([], $this->entries());
    }

    public function test_an_ask_that_names_no_card_belongs_to_the_card_of_the_asking_run(): void
    {
        $card = $this->card($this->em, $this->project);
        $sessionId = Uuid::v4();
        $bridgeId = Uuid::v7();
        $this->em->persist(new WorkerRun(
            project: $this->project,
            bridgeId: $bridgeId,
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('Card has no id.'),
            cardNumber: $card->number,
            workKind: 'implement',
            state: WorkerRunState::Running,
            sessionId: $sessionId,
            output: '',
        ));
        $this->em->flush();

        $handler = self::getContainer()->get(AskInboxHandler::class);
        self::assertInstanceOf(AskInboxHandler::class, $handler);
        $handler(new AskInboxCommand($this->project, $sessionId, [new AskInboxItem(
            kind: InboxItemKind::Question,
            title: 'Which name?',
            options: ['alpha', 'beta'],
            blocking: true,
            recommendedOption: 1,
            confidence: 'high',
            details: ['undo' => 'Rename it again.'],
            label: 'naming',
        )], $bridgeId));

        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertSame((string) $card->id, (string) $entries[0]->card->id);
    }

    public function test_an_ask_the_template_does_not_log_writes_no_entry(): void
    {
        $card = $this->card($this->em, $this->project);
        $this->em->flush();

        ($this->tool)(sessionId: (string) Uuid::v4(), items: [$this->item('Which store?', [(string) $card->id], 'data')]);

        self::assertSame([], $this->entries());
    }

    /** @return list<InboxDecision> */
    private function entries(): array
    {
        $this->em->clear();

        return $this->decisionRepository()->findBy(['project' => $this->project]);
    }

    /**
     * @param list<string> $cardIds
     *
     * @return array<string, mixed>
     */
    private function item(string $title, array $cardIds = [], string $label = 'naming'): array
    {
        return [
            'kind' => 'question',
            'title' => $title,
            'label' => $label,
            'options' => ['alpha', 'beta'],
            'recommendedOption' => 1,
            'confidence' => 'high',
            'details' => ['undo' => 'Rename it again.'],
            'cardIds' => $cardIds,
        ];
    }

    private function decisionRepository(): InboxDecisionRepository
    {
        $repository = self::getContainer()->get(InboxDecisionRepository::class);
        self::assertInstanceOf(InboxDecisionRepository::class, $repository);

        return $repository;
    }
}
