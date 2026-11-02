<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Exception\DomainErrors;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentRepository;

/**
 * Turns the document ids of a request into documents of one project. An id that names no
 * document of the project is refused like a document of another project, so a caller
 * learns nothing about documents outside it.
 */
final readonly class DocumentReferenceResolver
{
    public function __construct(
        private DocumentRepository $documents,
    ) {
    }

    /**
     * @param list<string> $ids
     *
     * @return list<Document>
     *
     * @throws DomainErrors `references` when an id names no document of the project
     */
    public function resolve(Project $project, array $ids): array
    {
        $found = [];
        foreach ($ids as $id) {
            $found[] = $this->documents->findOneByIdAndProjectId($id, (string) $project->id)
                ?? throw new DomainErrors(['references' => 'review.references.error.other_project']);
        }

        return $found;
    }
}
