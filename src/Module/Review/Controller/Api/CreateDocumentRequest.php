<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\OpenApi\PartialChangeSchemaProcessor;
use App\Doctrine\SearchLanguage;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Series;
use App\Module\Review\Entity\Tag;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A new document. A missing title and an explicit null both fail NotBlank, so the type
 * stays nullable and the schema does not. The handler checks the lengths, the size of the
 * Markdown, the series and the references, so a refusal of those carries its own code.
 */
#[OA\Schema(x: [PartialChangeSchemaProcessor::MARKER => ['title', 'markdown']])]
final class CreateDocumentRequest
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[OA\Property(nullable: false, maxLength: Document::MAX_TITLE_LENGTH, minLength: 1)]
    public ?string $title = null;

    #[Assert\NotBlank]
    #[OA\Property(description: 'At most 1048575 bytes. Write a card as `card:<number>` and a pull request as `pr:owner/repo#<number>`.', nullable: false)]
    public ?string $markdown = null;

    #[OA\Property(description: 'What this first version is, in one or two sentences.', nullable: false)]
    public ?string $description = null;

    /** @var list<string> */
    #[OA\Property(description: 'Lowercased on write and created on first use.', type: 'array', items: new OA\Items(type: 'string', maxLength: Tag::MAX_NAME_LENGTH))]
    public array $tags = [];

    /** @var list<string> */
    #[OA\Property(description: 'Documents of the same project.', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))]
    public array $references = [];

    #[OA\Property(description: 'The ordered set this document is an item of. It needs `seriesOrdinal`.', nullable: false, maxLength: Series::MAX_NAME_LENGTH)]
    public ?string $series = null;

    #[OA\Property(description: 'The position in that series, from 1. No two documents in one series hold the same number.', nullable: false, maximum: Series::MAX_ORDINAL, minimum: 1)]
    public ?int $seriesOrdinal = null;

    #[OA\Property(ref: new Model(type: SearchLanguage::class), description: 'The language of the document. It defaults to the project setting.')]
    public ?SearchLanguage $language = null;

    #[OA\Property(description: 'Keep the document out of review and the inbox until `publish`.')]
    public bool $draft = false;

    /** A blank description means none was given, but "0" is a description. */
    public function description(): ?string
    {
        $description = null === $this->description ? null : trim($this->description);

        return '' === $description ? null : $description;
    }
}
