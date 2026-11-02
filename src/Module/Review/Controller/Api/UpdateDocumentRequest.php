<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\OpenApi\PartialChangeSchemaProcessor;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Series;
use App\Module\Review\Entity\Tag;
use OpenApi\Attributes as OA;

/**
 * A partial change of the metadata of a document. A field left out stays uninitialized and
 * keeps its value. An explicit null fails the property type, so it answers 422, except
 * for `series` and `seriesOrdinal`, where null takes the document out of its series.
 * Those two default to null and tell a named null from a missing field by their set hooks.
 */
#[OA\Schema(description: 'A field you leave out keeps its value. No version is created.', x: [PartialChangeSchemaProcessor::MARKER => true])]
final class UpdateDocumentRequest
{
    #[OA\Property(maxLength: Document::MAX_TITLE_LENGTH, minLength: 1)]
    public string $title;

    /** @var list<string> */
    #[OA\Property(description: 'The complete set, and an empty list clears it.', type: 'array', items: new OA\Items(type: 'string', maxLength: Tag::MAX_NAME_LENGTH))]
    public array $tags;

    /** @var list<string> */
    #[OA\Property(description: 'The complete set, and an empty list clears it. A document cannot reference itself.', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))]
    public array $references;

    #[OA\Property(description: 'The series name. Null with a null `seriesOrdinal` takes the document out of its series.', nullable: true, maxLength: Series::MAX_NAME_LENGTH)]
    public ?string $series = null {
        set {
            $this->series = $value;
            $this->placed = true;
        }
    }

    #[OA\Property(description: 'The position in that series, from 1.', nullable: true, maximum: Series::MAX_ORDINAL, minimum: 1)]
    public ?int $seriesOrdinal = null {
        set {
            $this->seriesOrdinal = $value;
            $this->placed = true;
        }
    }

    #[OA\Property(description: 'True archives the document, and false restores it. Nothing is deleted and no version is created.')]
    public bool $archived;

    #[OA\Property(description: 'Why the document is archived. It is required with `archived: true`, and ignored otherwise.')]
    public string $archiveReason;

    /** Set by the hooks above, so an explicit null differs from a field the request left out. */
    private bool $placed = false;

    /** True when the request names a series or an ordinal, even a null one. */
    public function placesInSeries(): bool
    {
        return $this->placed;
    }
}
