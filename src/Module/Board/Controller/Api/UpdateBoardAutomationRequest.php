<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\PartialChangeSchemaProcessor;
use App\Module\Board\Entity\BoardAutomationSettings;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A partial change of the automation settings. A field left out stays uninitialized and keeps
 * its value. An explicit null fails the property type, so it answers 422.
 */
#[OA\Schema(description: 'A field you leave out keeps its value.', x: [PartialChangeSchemaProcessor::MARKER => true])]
final class UpdateBoardAutomationRequest
{
    #[OA\Property(description: 'Turn the workflow of the board on or off.')]
    public bool $enabled;

    #[Assert\Range(min: BoardAutomationSettings::MIN_STUCK_DELAY_MINUTES, max: BoardAutomationSettings::MAX_STUCK_DELAY_MINUTES)]
    #[OA\Property(description: 'The minutes a pull request may wait for a merge or for any card change before its card shows Stuck.', maximum: BoardAutomationSettings::MAX_STUCK_DELAY_MINUTES, minimum: BoardAutomationSettings::MIN_STUCK_DELAY_MINUTES)]
    public int $stuckDelayMinutes;

    #[OA\Property(description: 'Whether an approval of a pull request still covers a merge from the base that holds a conflict resolution. The value belongs to the copy of the workflow template that the project holds, so a project with no workflow answers 409 `no_workflow`.')]
    public bool $approvalCoversConflictResolution;
}
