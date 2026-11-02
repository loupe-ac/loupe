<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use App\Api\OpenApi\PartialChangeSchemaProcessor;
use App\Module\Artifact\Entity\Artifact;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/** The fields of the multipart request that creates an artifact. The handler checks the name. */
#[OA\Schema(
    description: '`errors[].code` covers `paths_mismatch`, `no_files`, `too_many_files`, `unsafe_path`, `claude_design_canvas`, `duplicate_path`, `bad_content_type`, `too_large`, `upload_failed`, `card_not_found` and the name codes `name_blank`, `name_too_long`.',
    x: [PartialChangeSchemaProcessor::MARKER => ['name', 'paths', 'files']],
)]
final class ArtifactUploadRequest extends ArtifactVersionUploadRequest
{
    #[OA\Property(nullable: false, maxLength: Artifact::MAX_NAME_LENGTH, minLength: 1)]
    public ?string $name = null;

    #[Assert\Length(max: self::MAX_SOURCE_LINK_LENGTH)]
    #[OA\Property(nullable: false, maxLength: self::MAX_SOURCE_LINK_LENGTH)]
    public ?string $sourceLink = null;
}
