<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Repository\PublicLinkRepository;
use App\Module\Insights\Repository\PublicLinkTakedownRepository;
use App\Module\Insights\View\PublicLinkTakedownDetailView;

/** Reads what the take down page shows: the project behind a link, its owner and how many links it has. */
final readonly class ShowPublicLinkTakedownHandler
{
    public function __construct(
        private PublicLinkRepository $publicLinks,
        private PublicLinkTakedownRepository $publicLinkTakedowns,
    ) {
    }

    public function __invoke(ShowPublicLinkTakedownCommand $command): PublicLinkTakedownDetailView
    {
        $project = $command->link->project;

        return new PublicLinkTakedownDetailView(
            $command->link,
            $project,
            $project->owner,
            $this->publicLinks->countForProject($project),
            $this->publicLinkTakedowns->existsForProject($project),
        );
    }
}
