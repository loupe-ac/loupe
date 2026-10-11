<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\BoardColumnTerminalChanged;
use App\Module\OAuth\Command\RevokePreviewTokensCommand;
use App\Module\OAuth\Command\RevokePreviewTokensHandler;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

/**
 * A column that turns terminal finishes the cards it holds, so they lose their
 * preview tokens by the rule a single move follows. It handles a failure the
 * way RevokePreviewTokensOnCardMoved does.
 */
#[AsEventListener(priority: -10)]
final readonly class RevokePreviewTokensOnBoardColumnTerminalChanged
{
    public function __construct(
        private RevokePreviewTokensHandler $revokePreviewTokens,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(BoardColumnTerminalChanged $event): void
    {
        if (!$event->terminal || [] === $event->cardIds) {
            return;
        }

        try {
            foreach ($event->cardIds as $cardId) {
                ($this->revokePreviewTokens)(new RevokePreviewTokensCommand(cardId: Uuid::fromString($cardId)));
            }
        } catch (\Throwable $e) {
            if ($e instanceof DbalException || !$this->em->isOpen()) {
                throw $e;
            }
            $this->logger->warning('board.preview_tokens_revoke_failed', [
                'columnId' => $event->columnId,
                'projectId' => (string) $event->project->id,
                'exception' => $e,
            ]);
        }
    }
}
