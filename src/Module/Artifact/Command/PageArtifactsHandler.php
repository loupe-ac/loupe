<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

use App\Api\Pagination\Cursor;
use App\Api\Pagination\Page;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Repository\ArtifactRepository;
use App\Module\Artifact\Repository\ArtifactVersionRepository;

final readonly class PageArtifactsHandler
{
    public function __construct(
        private ArtifactRepository $artifacts,
        private ArtifactVersionRepository $artifactVersions,
    ) {
    }

    /** @return Page<PagedArtifact> */
    public function __invoke(PageArtifactsCommand $command): Page
    {
        $rows = $this->artifacts->findKeysetPageByProject(
            $command->project,
            $command->includeArchived,
            $command->page->fetchSize(),
            $command->sort->newestFirst(),
            $command->page->cursor?->createdPosition(),
        );
        $page = Page::fromRows($rows, $command->page, fn (Artifact $artifact): Cursor => Cursor::afterCreated(
            $command->sort->value,
            $artifact->createdAt,
            $artifact->id ?? throw new \LogicException('A stored artifact has an id.'),
            ['includeArchived' => $command->includeArchived],
        ));
        $latest = $this->artifactVersions->latestNumbers($page->items);

        return new Page(
            array_map(static fn (Artifact $artifact): PagedArtifact => new PagedArtifact($artifact, $latest[(string) $artifact->id] ?? 0), $page->items),
            $page->next,
        );
    }
}
