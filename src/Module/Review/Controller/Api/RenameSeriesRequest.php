<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Module\Review\Entity\Series;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

final class RenameSeriesRequest
{
    #[Assert\Length(max: Series::MAX_NAME_LENGTH, normalizer: 'trim')]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[OA\Property(description: 'The new name, stored as you spell it. It must not belong to another series, ignoring case.', nullable: false, maxLength: Series::MAX_NAME_LENGTH, minLength: 1)]
    public ?string $name = null;
}
