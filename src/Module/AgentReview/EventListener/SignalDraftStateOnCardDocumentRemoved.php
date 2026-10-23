<?php

declare(strict_types=1);

namespace App\Module\AgentReview\EventListener;

use App\Module\Board\Entity\CardDocument;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Service\DraftStateSignal;
use App\Module\Workflow\Contract\DesignReviewTags;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A draft design that loses its link to a card, by an unlink or a card delete,
 * can leave its agent review loop. After the flush the link is gone, so the
 * listener reads it while the flush runs. A rollback after the flush costs the
 * page one needless refetch.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
#[AsDoctrineListener(event: Events::onClear)]
final class SignalDraftStateOnCardDocumentRemoved implements ResetInterface
{
    /** @var array<string, array{Uuid, Uuid}> project and document ids, by document id */
    private array $documents = [];

    public function __construct(
        private readonly DesignReviewTags $designReviewTags,
        private readonly DraftStateSignal $signal,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityDeletions() as $entity) {
            if (!$entity instanceof CardDocument || DocumentStatus::Draft !== $entity->document->status) {
                continue;
            }
            $projectId = $entity->document->project->requireId();
            $documentId = $entity->document->id ?? throw new \LogicException('A linked document has an id.');
            $tags = $this->designReviewTags->of($projectId);
            foreach ($entity->document->tags as $tag) {
                if (\in_array($tag->name, $tags, true)) {
                    $this->documents[$documentId->toRfc4122()] = [$projectId, $documentId];

                    break;
                }
            }
        }
    }

    public function postFlush(): void
    {
        $documents = $this->documents;
        $this->documents = [];
        foreach ($documents as [$projectId, $documentId]) {
            $this->signal->send($projectId, $documentId);
        }
    }

    public function onClear(): void
    {
        $this->documents = [];
    }

    #[\Override]
    public function reset(): void
    {
        $this->documents = [];
    }
}
