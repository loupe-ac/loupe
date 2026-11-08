<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Insights\Messenger\RefreshProjectInsights;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** Driven through a real move, so the listener runs where UpdateCardHandler dispatches CardMoved. */
final class AnnounceFinishedCardsOnCardMovedTest extends KernelTestCase
{
    use ResolveFeedbackScenario;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->transport()->reset();
    }

    public function test_a_move_into_a_terminal_column_asks_for_the_cards(): void
    {
        $project = $this->feedbackProject('finished-in');
        $card = $this->cardIn($project, 'in-progress');
        $this->em->flush();

        $this->move($card, $project, 'done');

        $this->assertAskedForCards((string) $project->id);
    }

    public function test_a_move_out_of_a_terminal_column_asks_for_the_cards(): void
    {
        $project = $this->feedbackProject('finished-out');
        $card = $this->cardIn($project, 'done');
        $this->em->flush();

        $this->move($card, $project, 'in-progress');

        $this->assertAskedForCards((string) $project->id);
    }

    public function test_a_move_between_two_open_columns_asks_for_nothing(): void
    {
        $project = $this->feedbackProject('finished-open');
        $card = $this->cardIn($project, 'backlog');
        $this->em->flush();

        $this->move($card, $project, 'in-progress');

        self::assertSame([], $this->transport()->getSent());
    }

    private function assertAskedForCards(string $projectId): void
    {
        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(RefreshProjectInsights::class, $message);
        self::assertSame($projectId, $message->projectId);
        self::assertSame('cards', $message->kind);
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.insights');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function move(Card $card, Project $project, string $slug): void
    {
        $handler = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $handler);

        $handler(new UpdateCardCommand($card, Actor::Human, column: $this->column($project, $slug)));
    }
}
