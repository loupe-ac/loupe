<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Event\CardMoved;
use App\Module\Board\EventListener\RevokePreviewTokensOnCardMoved;
use App\Module\Board\Service\CardMove;
use App\Module\OAuth\Command\RevokePreviewTokensHandler;
use App\Module\OAuth\Repository\PreviewTokenRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\OAuth\PreviewTokenScenario;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SilentAuditor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\InvalidArgumentException as DbalInvalidArgument;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Driven through a real move, so the listener runs where UpdateCardHandler dispatches CardMoved. */
final class RevokePreviewTokensOnCardMovedTest extends KernelTestCase
{
    use PreviewTokenScenario;
    use ResolveFeedbackScenario;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_a_move_into_a_terminal_column_revokes_the_tokens_of_the_card_alone(): void
    {
        $project = $this->feedbackProject('preview-done');
        $card = $this->cardIn($project, 'in-progress');
        $other = $this->cardIn($project, 'in-progress', 2);
        $this->em->flush();
        $mine = $this->mintForCard($project, $card);
        $theirs = $this->mintForCard($project, $other);
        $none = $this->mintForCard($project, null);

        $this->move($card, $project, 'done');

        self::assertTrue($this->isRevoked($mine));
        self::assertFalse($this->isRevoked($theirs));
        self::assertFalse($this->isRevoked($none));
    }

    public function test_a_move_between_open_columns_revokes_nothing(): void
    {
        $project = $this->feedbackProject('preview-open');
        $card = $this->cardIn($project, 'backlog');
        $this->em->flush();
        $token = $this->mintForCard($project, $card);

        $this->move($card, $project, 'in-progress');

        self::assertFalse($this->isRevoked($token));
    }

    public function test_a_move_between_two_terminal_columns_and_a_move_back_out_revoke_nothing_new(): void
    {
        $project = $this->feedbackProject('preview-terminal');
        $card = $this->cardIn($project, 'in-progress');
        $this->em->flush();
        $token = $this->mintForCard($project, $card);
        // Mint refuses a finished card, so the card reaches done with no move.
        $card->column = $this->column($project, 'done');
        $this->em->flush();

        $this->move($card, $project, 'shipped');
        self::assertFalse($this->isRevoked($token));

        $this->move($card, $project, 'in-progress');
        self::assertFalse($this->isRevoked($token));
    }

    public function test_a_failure_in_the_listener_is_logged_and_does_not_break_the_move(): void
    {
        $project = $this->feedbackProject('preview-failure');
        $card = $this->cardIn($project, 'in-progress');
        $this->em->flush();
        $logger = new RecordingLogger();
        $repository = $this->createStub(PreviewTokenRepository::class);
        $repository->method('revokeByCard')->willThrowException(new \RuntimeException('boom'));

        $card->column = $this->column($project, 'done');
        $this->listener($repository, $logger)(new CardMoved($card, new CardMove($this->column($project, 'in-progress')), Actor::Human));

        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        self::assertSame('board.preview_tokens_revoke_failed', $logger->records[0]['message']);
        self::assertSame((string) $card->id, $logger->records[0]['context']['cardId']);
    }

    public function test_a_database_failure_in_the_listener_reaches_the_caller(): void
    {
        $project = $this->feedbackProject('preview-dbal');
        $card = $this->cardIn($project, 'done');
        $this->em->flush();
        $repository = $this->createStub(PreviewTokenRepository::class);
        $repository->method('revokeByCard')->willThrowException(new DbalInvalidArgument('boom'));

        $this->expectException(DbalInvalidArgument::class);
        $this->listener($repository, new RecordingLogger())(new CardMoved($card, new CardMove($this->column($project, 'in-progress')), Actor::Human));
    }

    private function listener(PreviewTokenRepository $repository, RecordingLogger $logger): RevokePreviewTokensOnCardMoved
    {
        $projects = self::getContainer()->get(ProjectRepository::class);
        self::assertInstanceOf(ProjectRepository::class, $projects);

        return new RevokePreviewTokensOnCardMoved(new RevokePreviewTokensHandler($repository, $projects, SilentAuditor::create()), $this->em, $logger);
    }

    private function move(Card $card, Project $project, string $slug): void
    {
        $handler = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $handler);

        $handler(new UpdateCardCommand($card, Actor::Human, column: $this->column($project, $slug)));
    }
}
