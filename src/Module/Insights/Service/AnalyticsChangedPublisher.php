<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * Tells the open Analytics pages of a project that an area changed. The message
 * names the area and one id, and carries no name and no number.
 */
final readonly class AnalyticsChangedPublisher
{
    public const string TYPE = 'analytics.changed';

    public function __construct(
        private ProjectTopicBuilder $topics,
        private LiveUpdatePublisher $publisher,
    ) {
    }

    /**
     * Call it after the change commits, so a rollback signals nothing. The area is an
     * AnalyticsArea constant. The id is an experiment name, an analysis id or a rule id, or null when no one id fits.
     */
    public function changed(Uuid $projectId, string $area, ?string $id = null): void
    {
        $this->publisher->queue(
            $this->topics->forAnalytics($projectId),
            ['type' => self::TYPE, 'area' => $area, 'id' => $id],
        );
    }
}
