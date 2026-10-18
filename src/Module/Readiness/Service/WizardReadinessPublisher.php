<?php

declare(strict_types=1);

namespace App\Module\Readiness\Service;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Project\Entity\Project;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tells the open boards of a project when the four rows of the setup wizard
 * read differently. It keeps the last fingerprint of each project in the app
 * cache, so a heartbeat that changes nothing publishes nothing.
 */
final readonly class WizardReadinessPublisher
{
    public const string TYPE = 'bridge.readiness_changed';

    private const string KEY_PREFIX = 'readiness.wizard.';

    public function __construct(
        private ReadinessChecklist $checklist,
        private ProjectTopicBuilder $topics,
        private LiveUpdatePublisher $publisher,

        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    /** Call it after the change commits. A project with no stored fingerprint counts as changed. */
    public function publishIfChanged(Project $project): bool
    {
        $projectId = $project->id ?? throw new \LogicException('A stored project has an id.');
        $fingerprint = $this->checklist->wizardFingerprint($project);
        $item = $this->cache->getItem(self::KEY_PREFIX.$projectId->toRfc4122());
        if ($item->isHit() && $item->get() === $fingerprint) {
            return false;
        }

        $this->publisher->queue($this->topics->forBoard($projectId), ['type' => self::TYPE, 'projectId' => $projectId->toRfc4122()]);
        $this->cache->save($item->set($fingerprint));

        return true;
    }
}
