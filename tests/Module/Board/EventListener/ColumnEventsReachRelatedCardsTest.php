<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Command\DeleteBoardColumnCommand;
use App\Module\Board\Command\DeleteBoardColumnHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Command\EvaluateWorkflowCardHandler;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Messenger\EvaluateCardHandler;
use App\Module\Workflow\Repository\WorkflowQueuedEvaluationRepository;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** Drives the real column handlers, then runs the evaluations they queued through the engine of a Lifecycle board. */
final class ColumnEventsReachRelatedCardsTest extends KernelTestCase
{
    use ActionScenario;

    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('column-events');
        $this->bindLifecycle($this->project);
        $this->transport()->reset();
    }

    public function test_a_terminal_column_that_turns_open_reopens_the_epic_of_its_card(): void
    {
        $this->extraColumn('Archived', terminal: true);
        $epic = $this->epicIn('done');
        $this->childOf($epic, 'archived');

        $this->configureColumn($this->project, 'archived', terminal: false);
        $this->runQueuedEvaluations();

        self::assertSame('in-progress', $this->columnOf($epic));
    }

    public function test_a_column_delete_that_reopens_a_card_reopens_its_epic(): void
    {
        $archived = $this->extraColumn('Archived', terminal: true);
        $epic = $this->epicIn('done');
        $this->childOf($epic, 'archived');

        $this->service(DeleteBoardColumnHandler::class)(new DeleteBoardColumnCommand($archived, Actor::Human, $this->column($this->project, 'next')));
        $this->runQueuedEvaluations();

        self::assertSame('in-progress', $this->columnOf($epic));
    }

    public function test_a_blocker_column_that_turns_terminal_starts_the_blocked_child(): void
    {
        $this->extraColumn('Parked', terminal: false);
        $epic = $this->epicIn('in-progress');
        $blocker = $this->card($this->project, 'parked');
        $child = $this->childOf($epic, 'next');
        $this->approveTechDesign($child);
        $this->em()->persist(new CardLink($blocker, $child, CardLinkKind::Blocks));
        $this->em()->flush();

        $this->configureColumn($this->project, 'parked', terminal: true);
        $this->runQueuedEvaluations();
        // The blocker sits outside the epic, so the first pass syncs the epic branch and queues the child again.
        $this->runQueuedEvaluations();

        self::assertSame('in-progress', $this->columnOf($child));
    }

    private function extraColumn(string $label, bool $terminal): BoardColumn
    {
        $slug = strtolower($label);
        $column = new BoardColumn(project: $this->project, label: $label, slug: $slug, position: 20, terminal: $terminal);
        $this->em()->persist($column);
        $this->em()->flush();
        $this->seededColumns[$this->project->id.'/'.$slug] = $column;

        return $column;
    }

    private function epicIn(string $column): Card
    {
        $epic = $this->card($this->project, $column);
        $epic->type = 'epic';
        $this->em()->flush();

        return $epic;
    }

    private function childOf(Card $epic, string $column): Card
    {
        $child = $this->card($this->project, $column);
        $child->parent = $epic;
        $this->em()->flush();

        return $child;
    }

    private function approveTechDesign(Card $card): void
    {
        $document = new Document($this->project->owner, $this->project, 'Tech design');
        $document->status = DocumentStatus::Approved;
        $tag = new Tag($this->project, 'tech-design');
        $this->em()->persist($tag);
        $document->tags->add($tag);
        $this->em()->persist($document);
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->flush();
    }

    /** Runs the evaluations the column change queued, once each, and not the ones those evaluations queue in turn. */
    private function runQueuedEvaluations(): void
    {
        $cardIds = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof EvaluateCard) {
                $cardIds[] = $message->cardId;
            }
        }
        self::assertNotEmpty($cardIds);
        $this->transport()->reset();

        $handler = new EvaluateCardHandler(
            new EvaluateWorkflowCardHandler($this->service(Engine::class), new MockClock('2026-10-02 12:00:00')),
            $this->service(WorkflowQueuedEvaluationRepository::class),
            new EventDispatcher(),
        );
        foreach ($cardIds as $cardId) {
            $handler(new EvaluateCard($cardId));
        }
    }

    private function columnOf(Card $card): ?string
    {
        $this->em()->clear();

        return $this->em()->find(Card::class, $card->id)?->column->slug;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.card_eval');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
