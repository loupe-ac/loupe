<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Messenger;

use App\Module\Workflow\Contract\TemplateMetrics;
use App\Module\Workflow\Contract\TemplateMetricSet;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use Symfony\Component\Uid\Uuid;

/** Declares the review-time metric, and reads the column of its `review` slot from the saved slot links on every read. */
final class SavedSlotTemplateMetrics implements TemplateMetrics
{
    public int $calls = 0;

    /** Runs once after the first read, as a change that is saved between the batch read and the lock. */
    public ?\Closure $afterFirstRead = null;

    public function __construct(
        private readonly WorkflowSlotLinkRepository $workflowSlotLinks,
    ) {
    }

    public function forProject(Uuid $projectId): TemplateMetricSet
    {
        $link = $this->workflowSlotLinks->findOneBy(['project' => $projectId, 'slotKey' => 'review']);
        $set = ScriptedTemplateMetrics::reviewTime($link?->columnId);
        if (1 === ++$this->calls && null !== $this->afterFirstRead) {
            ($this->afterFirstRead)();
        }

        return $set;
    }
}
