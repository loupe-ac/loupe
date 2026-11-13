<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Command\ResumeWorkerRunsHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/** A missing `runIds` stays uninitialized and fails NotNull. An explicit null fails the property type. Both answer 422. */
#[OA\Schema(required: ['runIds'])]
final class ResumeWorkerRunsRequest
{
    /** @var list<string> */
    #[Assert\All([new Assert\Uuid()])]
    #[Assert\Count(min: 1, max: ResumeWorkerRunsHandler::MAX_RUNS)]
    #[Assert\NotNull]
    #[OA\Property(description: 'The ids of the runs to resume, from 1 to 50. The route treats each run on its own and in order.', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), maxItems: ResumeWorkerRunsHandler::MAX_RUNS, minItems: 1)]
    public array $runIds;
}
