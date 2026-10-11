<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardDeleted;
use App\Module\OAuth\Command\RevokePreviewTokensCommand;
use App\Module\OAuth\Command\RevokePreviewTokensHandler;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A deleted card ends its previews, so the preview tokens minted for it stop working. */
#[AsEventListener]
final readonly class RevokePreviewTokensOnCardDeleted
{
    public function __construct(
        private RevokePreviewTokensHandler $revokePreviewTokens,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CardDeleted $event): void
    {
        try {
            ($this->revokePreviewTokens)(new RevokePreviewTokensCommand(cardId: $event->cardId));
        } catch (\Throwable $e) {
            if ($e instanceof DbalException || !$this->em->isOpen()) {
                throw $e;
            }
            $this->logger->warning('board.preview_tokens_revoke_failed', [
                'cardId' => $event->cardId->toRfc4122(),
                'projectId' => $event->projectId->toRfc4122(),
                'exception' => $e,
            ]);
        }
    }
}
