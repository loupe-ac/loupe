<?php

declare(strict_types=1);

namespace App\Module\Artifact\Command;

final readonly class ListArtifactsView
{
    public function __construct(
        /** @var list<ArtifactListItem> newest first */
        public array $items,
        public bool $includeArchived,
    ) {
    }
}
