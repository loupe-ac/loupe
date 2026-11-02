<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\OpenApi\PartialChangeSchemaProcessor;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Series;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A revision of a document. The handler checks the description, the title, the size of the
 * Markdown, the series and the references. A field left out keeps its value. The references have no default, so an explicit null is refused.
 */
#[OA\Schema(x: [PartialChangeSchemaProcessor::MARKER => ['markdown', 'description']])]
final class CreateDocumentVersionRequest
{
    #[Assert\NotBlank]
    #[OA\Property(description: 'The new Markdown. At most 1048575 bytes.', nullable: false)]
    public ?string $markdown = null;

    #[Assert\NotBlank(normalizer: 'trim')]
    #[OA\Property(description: 'What changed and why, for a reviewer who read the previous version.', nullable: false, minLength: 1)]
    public ?string $description = null;

    #[OA\Property(description: 'A corrected title.', nullable: false, maxLength: Document::MAX_TITLE_LENGTH, minLength: 1)]
    public ?string $title = null;

    /** @var list<string> */
    #[OA\Property(description: 'The complete set. Leave it out to keep it, and send an empty list to clear it.', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), nullable: false)]
    public array $references;

    #[OA\Property(description: 'Leave it out to keep the placement. An empty string takes the document out of its series.', nullable: false, maxLength: Series::MAX_NAME_LENGTH)]
    public ?string $series = null;

    #[OA\Property(description: 'Required whenever `series` is given.', nullable: false, maximum: Series::MAX_ORDINAL, minimum: 1)]
    public ?int $seriesOrdinal = null;
}
