<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

final class UpdateExperimentRequest
{
    #[Assert\NotNull]
    #[OA\Property(description: 'True archives the experiment, and false brings it back. Archiving only hides it from the main list. A run that Loupe records after the archive brings the experiment back by itself.', nullable: false)]
    public ?bool $archived = null;
}
