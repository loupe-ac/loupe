<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Entity\ExperimentExclusion;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

final class ExcludeExperimentCardRequest
{
    #[Assert\Length(max: ExperimentExclusion::MAX_REASON_LENGTH, normalizer: 'trim')]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[OA\Property(description: 'Why the card does not fit the question of the experiment. Surrounding blanks are cut off.', nullable: false, maxLength: ExperimentExclusion::MAX_REASON_LENGTH, minLength: 1)]
    public ?string $reason = null;
}
