<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\ValueObject\CommentSignals;
use App\Module\Review\View\DocumentListItem;

/** Builds the rows of the documents list with one grouped query for each derived value. */
final readonly class DocumentListItems
{
    public function __construct(
        private DocumentRepository $documents,
        private DocumentVersionRepository $documentVersions,
        private CommentRepository $comments,
    ) {
    }

    /**
     * @param list<Document> $documents
     *
     * @return list<DocumentListItem>
     */
    public function of(array $documents): array
    {
        $this->documents->preloadTags($documents);
        $latestVersions = $this->documentVersions->findLatestMetaByDocuments($documents);

        // One grouped query for the page rather than one per row, matching how
        // tags and version metadata above are already loaded.
        $signals = $this->comments->signalsByVersions(array_values(array_map(
            static fn (array $meta): string => (string) $meta['versionId'],
            $latestVersions,
        )));

        return array_map(
            static function (Document $document) use ($latestVersions, $signals): DocumentListItem {
                $meta = $latestVersions[(string) $document->id] ?? throw new \LogicException('Document has no versions.');

                return new DocumentListItem(
                    document: $document,
                    versionNumber: $meta['versionNumber'],
                    updatedAt: $meta['createdAt'],
                    signals: $signals[(string) $meta['versionId']] ?? new CommentSignals(),
                    description: $meta['description'],
                );
            },
            $documents,
        );
    }
}
