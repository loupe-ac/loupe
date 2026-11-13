<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use Symfony\Component\Routing\Requirement\Requirement;

/** The route requirements that the public worker run routes share. */
final readonly class WorkerRunApiRoutes
{
    /** A UUID, so the path /runs/resume never matches as a run id. */
    public const string RUN = Requirement::UUID;
}
