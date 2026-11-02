<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/** A missing `archived` and an explicit null both fail NotNull, so the type stays nullable and the schema does not. */
#[OA\Schema(required: ['archived'])]
final class UpdateArtifactRequest
{
    #[Assert\NotNull]
    #[OA\Property(description: 'True archives the artifact, and false restores it. Nothing is deleted. A second write changes nothing.', nullable: false)]
    public ?bool $archived = null;
}
