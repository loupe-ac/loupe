<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Project\Entity\Project;
use App\Module\Review\View\DocumentListQuery;
use Symfony\Component\Uid\Uuid;

final readonly class ListDocumentRowsCommand
{
    /**
     * @param list<string>      $ids         the documents the page shows
     * @param ?int              $loadedTotal how many documents the filters kept when the page loaded
     * @param list<string>|null $knownIds    the ids the filters kept when the page loaded, or null when the list was too long to carry
     */
    public function __construct(
        public Project $project,
        public DocumentListQuery $listQuery,
        public array $ids,
        public \DateTimeImmutable $since,
        public ?Uuid $sinceId,
        public ?int $loadedTotal = null,
        public ?array $knownIds = null,
    ) {
    }
}
