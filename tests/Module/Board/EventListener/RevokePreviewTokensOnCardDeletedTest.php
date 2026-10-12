<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Command\DeleteCardCommand;
use App\Module\Board\Command\DeleteCardHandler;
use App\Module\Board\Event\CardDeleted;
use App\Module\Board\EventListener\RevokePreviewTokensOnCardDeleted;
use App\Module\OAuth\Command\RevokePreviewTokensHandler;
use App\Module\OAuth\Repository\PreviewTokenRepository;
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
use Symfony\Component\Uid\Uuid;

final class RevokePreviewTokensOnCardDeletedTest extends KernelTestCase
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

    public function test_deleting_a_card_revokes_its_tokens_and_leaves_the_others(): void
    {
        $project = $this->feedbackProject('preview-deleted');
        $card = $this->cardIn($project, 'in-progress');
        $other = $this->cardIn($project, 'in-progress', 2);
        $this->em->flush();
        $cardId = $card->id ?? throw new \LogicException('A flushed card has an id.');
        $mine = $this->mint('pr-1', $cardId, $project->owner, $project);
        $theirs = $this->mint('pr-2', $other->id, $project->owner, $project);

        $delete = self::getContainer()->get(DeleteCardHandler::class);
        self::assertInstanceOf(DeleteCardHandler::class, $delete);
        $delete(new DeleteCardCommand($card, Actor::Human));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM board_cards WHERE id = :id', ['id' => (string) $cardId]));
        self::assertTrue($this->isRevoked($mine));
        self::assertFalse($this->isRevoked($theirs));
    }

    public function test_a_failure_in_the_listener_is_logged_and_does_not_throw(): void
    {
        $logger = new RecordingLogger();
        $repository = $this->createStub(PreviewTokenRepository::class);
        $repository->method('revokeByCard')->willThrowException(new \RuntimeException('boom'));
        $cardId = Uuid::v7();
        $projectId = Uuid::v7();

        $this->listener($repository, $logger)(new CardDeleted($projectId, $cardId));

        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        self::assertSame('board.preview_tokens_revoke_failed', $logger->records[0]['message']);
        self::assertSame($cardId->toRfc4122(), $logger->records[0]['context']['cardId']);
    }

    public function test_a_database_failure_in_the_listener_reaches_the_caller(): void
    {
        $repository = $this->createStub(PreviewTokenRepository::class);
        $repository->method('revokeByCard')->willThrowException(new DbalInvalidArgument('boom'));

        $this->expectException(DbalInvalidArgument::class);
        $this->listener($repository, new RecordingLogger())(new CardDeleted(Uuid::v7(), Uuid::v7()));
    }

    private function listener(PreviewTokenRepository $repository, RecordingLogger $logger): RevokePreviewTokensOnCardDeleted
    {
        $projects = self::getContainer()->get(ProjectRepository::class);
        self::assertInstanceOf(ProjectRepository::class, $projects);

        return new RevokePreviewTokensOnCardDeleted(new RevokePreviewTokensHandler($repository, $projects, SilentAuditor::create()), $this->em, $logger);
    }
}
