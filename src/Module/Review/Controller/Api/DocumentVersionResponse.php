<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Module\Review\Entity\DocumentVersion;
use OpenApi\Attributes as OA;

#[OA\Schema(required: ['versionNumber', 'createdAt', 'description', 'markdown'])]
final readonly class DocumentVersionResponse
{
    public function __construct(
        #[OA\Property]
        public int $versionNumber,

        #[OA\Property(format: 'date-time')]
        public string $createdAt,

        #[OA\Property(nullable: true)]
        public ?string $description,

        #[OA\Property(description: 'The Markdown source of this version.')]
        public string $markdown,
    ) {
    }

    public static function fromVersion(DocumentVersion $version): self
    {
        return new self($version->versionNumber, $version->createdAt->format(\DATE_ATOM), $version->description, $version->markdownSource);
    }
}
