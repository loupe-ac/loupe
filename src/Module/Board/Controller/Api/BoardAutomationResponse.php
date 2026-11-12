<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Module\Board\Command\ShowBoardAutomationView;
use App\Module\Board\Entity\BoardAutomationSettings;
use OpenApi\Attributes as OA;

/** The automation settings of a project board. */
#[OA\Schema(required: ['enabled', 'stuckDelayMinutes', 'approvalCoversConflictResolution'])]
final readonly class BoardAutomationResponse
{
    public function __construct(
        #[OA\Property(description: 'Whether the workflow moves the cards and asks for their work. While it is false, the workflow moves no card and asks for no work.')]
        public bool $enabled,

        #[OA\Property(description: 'The minutes a pull request may wait for a merge or for any card change before its card shows Stuck.', maximum: BoardAutomationSettings::MAX_STUCK_DELAY_MINUTES, minimum: BoardAutomationSettings::MIN_STUCK_DELAY_MINUTES)]
        public int $stuckDelayMinutes,

        #[OA\Property(description: 'Whether an approval of a pull request still covers a merge from the base that holds a conflict resolution. A project with no workflow reports true.')]
        public bool $approvalCoversConflictResolution,
    ) {
    }

    public static function fromView(ShowBoardAutomationView $view): self
    {
        return new self($view->enabled, $view->stuckDelayMinutes, $view->approvalCoversConflictResolution);
    }
}
