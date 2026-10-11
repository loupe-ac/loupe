<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Command\ConfigureBoardColumnCommand;
use App\Module\Board\Command\ConfigureBoardColumnHandler;
use App\Module\Board\Command\DeleteBoardColumnCommand;
use App\Module\Board\Command\DeleteBoardColumnHandler;
use App\Module\Board\Event\BoardColumnDeleted;
use App\Module\Board\Event\BoardColumnTerminalChanged;
use App\Module\Board\EventListener\RevokePreviewTokensOnBoardColumnDeleted;
use App\Module\Board\EventListener\RevokePreviewTokensOnBoardColumnTerminalChanged;
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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Driven through a real column delete and a real flag change, which finish cards with no CardMoved. */
final class RevokePreviewTokensOnBoardColumnChangesTest extends KernelTestCase
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

    public function test_a_delete_into_a_terminal_column_revokes_the_tokens_of_every_moved_card(): void
    {
        $project = $this->feedbackProject('preview-delete-done');
        $first = $this->cardIn($project, 'in-progress', 1);
        $second = $this->cardIn($project, 'in-progress', 2);
        $elsewhere = $this->cardIn($project, 'backlog', 3);
        $this->em->flush();
        $tokens = [$this->mintForCard($project, $first), $this->mintForCard($project, $second)];
        $kept = $this->mintForCard($project, $elsewhere);

        $this->delete($project, 'in-progress', 'done');

        foreach ($tokens as $token) {
            self::assertTrue($this->isRevoked($token));
        }
        self::assertFalse($this->isRevoked($kept));
    }

    public function test_a_delete_into_an_open_column_revokes_nothing(): void
    {
        $project = $this->feedbackProject('preview-delete-open');
        $card = $this->cardIn($project, 'in-progress');
        $this->em->flush();
        $token = $this->mintForCard($project, $card);

        $this->delete($project, 'in-progress', 'next');

        self::assertFalse($this->isRevoked($token));
    }

    public function test_a_delete_of_a_terminal_column_into_another_revokes_nothing(): void
    {
        $project = $this->feedbackProject('preview-delete-terminal');
        $card = $this->cardIn($project, 'in-progress');
        $this->em->flush();
        $token = $this->mintForCard($project, $card);
        // Mint refuses a finished card, so the card reaches done with no move.
        $card->column = $this->column($project, 'done');
        $this->em->flush();

        $this->delete($project, 'done', 'shipped');

        self::assertFalse($this->isRevoked($token));
    }

    public function test_a_column_that_turns_terminal_revokes_the_tokens_of_its_cards(): void
    {
        $project = $this->feedbackProject('preview-terminal-on');
        $first = $this->cardIn($project, 'in-progress', 1);
        $second = $this->cardIn($project, 'in-progress', 2);
        $elsewhere = $this->cardIn($project, 'next', 3);
        $this->em->flush();
        $tokens = [$this->mintForCard($project, $first), $this->mintForCard($project, $second)];
        $kept = $this->mintForCard($project, $elsewhere);

        $this->configureTerminal($project, 'in-progress', true);

        foreach ($tokens as $token) {
            self::assertTrue($this->isRevoked($token));
        }
        self::assertFalse($this->isRevoked($kept));
    }

    public function test_a_column_that_stops_being_terminal_revokes_nothing(): void
    {
        $project = $this->feedbackProject('preview-terminal-off');
        $card = $this->cardIn($project, 'in-progress');
        $this->em->flush();
        $token = $this->mintForCard($project, $card);
        $card->column = $this->column($project, 'done');
        $this->em->flush();

        $this->configureTerminal($project, 'done', false);

        self::assertFalse($this->isRevoked($token));
    }

    public function test_a_database_failure_escapes_the_listeners_and_other_failures_are_logged(): void
    {
        $project = $this->feedbackProject('preview-column-failure');
        $cardId = Uuid::v7()->toRfc4122();
        $deleted = new BoardColumnDeleted($project, 'column-id', 'in-progress', 'done', [$cardId], Actor::Human, terminal: false, targetTerminal: true);
        $changed = new BoardColumnTerminalChanged($project, 'column-id', true, [$cardId], Actor::Human);

        $logger = new RecordingLogger();
        $failing = $this->createStub(PreviewTokenRepository::class);
        $failing->method('revokeByCard')->willThrowException(new \RuntimeException('boom'));
        new RevokePreviewTokensOnBoardColumnDeleted($this->handler($failing), $this->em, $logger)($deleted);
        new RevokePreviewTokensOnBoardColumnTerminalChanged($this->handler($failing), $this->em, $logger)($changed);
        self::assertSame(['board.preview_tokens_revoke_failed', 'board.preview_tokens_revoke_failed'], array_column($logger->records, 'message'));

        $database = $this->createStub(PreviewTokenRepository::class);
        $database->method('revokeByCard')->willThrowException(new DbalInvalidArgument('database'));
        try {
            new RevokePreviewTokensOnBoardColumnDeleted($this->handler($database), $this->em, new RecordingLogger())($deleted);
            self::fail('The database failure did not escape the delete listener.');
        } catch (DbalInvalidArgument) {
        }
        $this->expectException(DbalInvalidArgument::class);
        new RevokePreviewTokensOnBoardColumnTerminalChanged($this->handler($database), $this->em, new RecordingLogger())($changed);
    }

    private function handler(PreviewTokenRepository $repository): RevokePreviewTokensHandler
    {
        $projects = self::getContainer()->get(ProjectRepository::class);
        self::assertInstanceOf(ProjectRepository::class, $projects);

        return new RevokePreviewTokensHandler($repository, $projects, SilentAuditor::create());
    }

    private function delete(Project $project, string $slug, string $target): void
    {
        $handler = self::getContainer()->get(DeleteBoardColumnHandler::class);
        self::assertInstanceOf(DeleteBoardColumnHandler::class, $handler);

        $handler(new DeleteBoardColumnCommand($this->column($project, $slug), Actor::Human, $this->column($project, $target)));
    }

    private function configureTerminal(Project $project, string $slug, bool $terminal): void
    {
        $handler = self::getContainer()->get(ConfigureBoardColumnHandler::class);
        self::assertInstanceOf(ConfigureBoardColumnHandler::class, $handler);
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $column = $this->column($project, $slug);
        $handler(new ConfigureBoardColumnCommand($column, Actor::Human, $translator->trans($column->label), $terminal, $column->label, $column->terminal));
    }
}
