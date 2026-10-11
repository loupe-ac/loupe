<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardMoved;
use App\Module\OAuth\Command\RevokePreviewTokensCommand;
use App\Module\OAuth\Command\RevokePreviewTokensHandler;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A card that finishes ends its previews, so the preview tokens minted for it
 * stop working. A move back out revokes nothing, because a new preview mints a
 * new token, and a move between two terminal columns finishes nothing new.
 *
 * It runs inside UpdateCardHandler's transaction, and it fails the move on a
 * database failure for the reason ResolveFeedbackOnCardMoved gives.
 */
#[AsEventListener(priority: -10)]
final readonly class RevokePreviewTokensOnCardMoved
{
    public function __construct(
        private RevokePreviewTokensHandler $revokePreviewTokens,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CardMoved $event): void
    {
        $card = $event->card;
        if (!$card->column->terminal || $event->move->fromColumn->terminal) {
            return;
        }

        try {
            ($this->revokePreviewTokens)(new RevokePreviewTokensCommand(cardId: $card->id));
        } catch (\Throwable $e) {
            if ($e instanceof DbalException || !$this->em->isOpen()) {
                throw $e;
            }
            $this->logger->warning('board.preview_tokens_revoke_failed', [
                'cardId' => (string) $card->id,
                'projectId' => (string) $card->project->id,
                'exception' => $e,
            ]);
        }
    }
}
