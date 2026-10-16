<?php

declare(strict_types=1);

namespace App\Module\Review\Twig;

use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Repository\DocumentRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes a project's document counts to the app-shell nav. The counts are a
 * Review concern, and Review → Project is the allowed dependency direction.
 *
 * The nav pill counts the documents that wait for a review. The switcher counts
 * every document that is not archived, which is the size of the project.
 */
final class ReviewNavExtension extends AbstractExtension
{
    public function __construct(
        private readonly DocumentRepository $documents,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('project_document_count', $this->documentCount(...)),
            new TwigFunction('project_document_counts', $this->documentCounts(...)),
        ];
    }

    public function documentCount(Project $project): int
    {
        return $this->documents->countByProjectAndStatuses($project, [DocumentStatus::InReview]);
    }

    /**
     * @param list<Project> $projects
     *
     * @return array<string, int> project id => count
     */
    public function documentCounts(array $projects): array
    {
        return $this->documents->countActiveByProjects($projects);
    }
}
