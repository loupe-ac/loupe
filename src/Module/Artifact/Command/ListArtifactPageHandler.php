<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Repository\ArtifactRepository;
use App\Module\Artifact\Repository\ArtifactVersionRepository;

/**
 * @phpstan-type ArtifactListPayload array{artifacts: list<array{artifactId: string, name: string, latestVersion: int, archived: bool, sourceLink: ?string, createdAt: string, path: string}>, page: int, perPage: int, total: int, hasMore: bool}
 */
final readonly class ListArtifactPageHandler
{
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private ArtifactRepository $artifacts,
        private ArtifactVersionRepository $artifactVersions,
    ) {
    }

    /** @return ArtifactListPayload */
    public function __invoke(ListArtifactPageCommand $command): array
    {
        // Clamped rather than rejected, so an out-of-range page reads as an empty page.
        $page = max(1, $command->page);
        $perPage = min(self::MAX_PER_PAGE, max(1, $command->perPage));

        $rows = $this->artifacts->findPageByProject($command->project, $command->includeArchived, $perPage, ($page - 1) * $perPage);
        $latest = $this->artifactVersions->latestNumbers($rows);
        $total = $this->artifacts->countInProject($command->project, $command->includeArchived);

        return [
            'artifacts' => array_map(
                static fn (Artifact $artifact): array => [
                    'artifactId' => (string) $artifact->id,
                    'name' => $artifact->name,
                    'latestVersion' => $latest[(string) $artifact->id] ?? 0,
                    'archived' => null !== $artifact->archivedAt,
                    'sourceLink' => $artifact->sourceLink,
                    'createdAt' => $artifact->createdAt->format(\DateTimeInterface::ATOM),
                    'path' => \sprintf('/projects/%s/artifacts/%s', $command->project->id, $artifact->id),
                ],
                $rows,
            ),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'hasMore' => $page * $perPage < $total,
        ];
    }
}
