<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\PublicLinkKind;
use App\Module\Insights\Service\PublicGraphResults;
use App\Module\Insights\Service\PublicLinkVisibility;
use App\Module\Insights\View\PublicLinkDetailView;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Reads the shared item behind a code for a visitor. Every reason to hide it throws the same not found. */
final readonly class ShowPublicLinkHandler
{
    public function __construct(
        private PublicLinkVisibility $visibility,
        private PublicGraphResults $graphResults,
    ) {
    }

    /** @throws NotFoundHttpException when the code shows nothing */
    public function __invoke(ShowPublicLinkCommand $command): PublicLinkDetailView
    {
        $link = $this->visibility->resolve($command->code) ?? throw new NotFoundHttpException('No public link shows for this code.');
        $graph = $link->savedGraph;
        if (PublicLinkKind::Graph !== $link->kind || null === $graph) {
            throw new NotFoundHttpException('No public link shows for this code.');
        }

        return new PublicLinkDetailView($link->project, $graph, $this->graphResults->forGraph($graph), $link->code);
    }
}
