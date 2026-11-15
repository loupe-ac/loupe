<?php

declare(strict_types=1);

namespace App\Module\Readiness\EventListener;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Readiness\Entity\DiscoveryRun;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Tells the open readiness pages of a project that a discovery run started or
 * changed state. Several writers change a run, two of them inside the review
 * transaction, so the signal queues from the entity. Doctrine calls these hooks
 * inside the transaction of the flush, and the publisher holds the signal there.
 */
#[AsEntityListener(event: Events::postPersist, method: 'saved', entity: DiscoveryRun::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'saved', entity: DiscoveryRun::class)]
final readonly class PublishDiscoveryChangedOnDiscoveryRunSaved
{
    public const string TYPE = 'project.discovery_changed';

    public function __construct(
        private ProjectTopicBuilder $topics,
        private LiveUpdatePublisher $publisher,
    ) {
    }

    public function saved(DiscoveryRun $run): void
    {
        $projectId = $run->project->id ?? throw new \LogicException('Discovery project has no id.');
        $this->publisher->queue($this->topics->forBoard($projectId), [
            'type' => self::TYPE,
            'projectId' => (string) $projectId,
        ]);
    }
}
