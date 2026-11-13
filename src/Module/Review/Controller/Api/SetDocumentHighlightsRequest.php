<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\OpenApi\PartialChangeSchemaProcessor;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/** The whole highlight set. A body without `quotes` fails the validator, so it answers 422. */
#[OA\Schema(x: [PartialChangeSchemaProcessor::MARKER => ['quotes']])]
final class SetDocumentHighlightsRequest
{
    /** @var list<string> */
    #[Assert\All(new Assert\Type('string'))]
    #[Assert\NotNull]
    #[OA\Property(description: 'Verbatim passages of the document, as they read in rendered prose and not as Markdown source. Keep each quote inside one paragraph or list item. The list replaces the whole highlight set, and an empty list clears it.', type: 'array', items: new OA\Items(type: 'string'))]
    public array $quotes;
}
