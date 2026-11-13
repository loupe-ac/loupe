<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Entity\BridgeCommand;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

final class StopWorkerRunRequest
{
    #[Assert\Length(max: BridgeCommand::MAX_REASON_LENGTH)]
    #[OA\Property(description: 'Why the run must stop, which a person reads on the run. At most 1000 characters. A body may leave it out.', nullable: true, maxLength: BridgeCommand::MAX_REASON_LENGTH)]
    public ?string $reason = null;
}
