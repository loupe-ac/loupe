<?php

declare(strict_types=1);

namespace App\Module\Readiness\Controller\Api;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

final class UpdateReadinessRequest
{
    #[Assert\NotNull]
    #[OA\Property(description: 'True shows the readiness guide on the Workshop, and false hides it. The checks stay readable while the guide is hidden.', nullable: false)]
    public ?bool $guideShown = null;
}
