<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use App\Api\OpenApi\PartialChangeSchemaProcessor;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The fields of the multipart request that stores a version. The Serializer merges the
 * uploaded files into `files`. The handler checks the paths, the card and the files, so
 * their refusals carry the codes of the store.
 */
#[OA\Schema(
    description: '`errors[].code` covers `paths_mismatch`, `no_files`, `too_many_files`, `unsafe_path`, `claude_design_canvas`, `duplicate_path`, `bad_content_type`, `too_large`, `card_not_found` and the name codes `name_blank`, `name_too_long`.',
    x: [PartialChangeSchemaProcessor::MARKER => ['paths', 'files']],
)]
class ArtifactVersionUploadRequest
{
    public const int MAX_NOTES_LENGTH = 2000;
    public const int MAX_SOURCE_LINK_LENGTH = 2000;
    public const int MAX_PATH_LENGTH = 1024;

    /** @var list<string> */
    #[Assert\All([new Assert\Type('string')])]
    #[OA\Property(description: 'The path of each file in `files`, in the same order.', type: 'array', items: new OA\Items(type: 'string', maxLength: self::MAX_PATH_LENGTH))]
    public array $paths = [];

    #[OA\Property(description: 'A card of this project to link.', format: 'uuid', nullable: false)]
    public ?string $card = null;

    #[Assert\Length(max: self::MAX_NOTES_LENGTH)]
    #[OA\Property(description: 'What changed in this version.', nullable: false, maxLength: self::MAX_NOTES_LENGTH)]
    public ?string $notes = null;

    /** @var array<mixed> */
    #[OA\Property(description: 'The files. Each part gives its content type.', type: 'array', items: new OA\Items(type: 'string', format: 'binary'))]
    public array $files = [];
}
