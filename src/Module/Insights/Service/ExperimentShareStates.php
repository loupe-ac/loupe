<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Repository\PublicLinkRepository;
use App\Module\Insights\View\PublicLinkShareView;
use App\Module\Project\Entity\Project;

/** Reads the share state of experiments for the experiment pages, with one query for the links of a whole project. */
final readonly class ExperimentShareStates
{
    public function __construct(
        private PublicLinkRepository $publicLinks,
        private PublicShareGuard $guard,
    ) {
    }

    public function for(Project $project, string $experiment): PublicLinkShareView
    {
        return new PublicLinkShareView($this->publicLinks->findForExperiment($project, $experiment), $this->guard->blockedBy($project));
    }

    /**
     * @param list<string> $experiments
     *
     * @return array<string, PublicLinkShareView> by experiment name
     */
    public function forExperiments(Project $project, array $experiments): array
    {
        $links = $this->publicLinks->findExperimentLinks($project);
        $block = $this->guard->blockedBy($project);
        $states = [];
        foreach ($experiments as $experiment) {
            $states[$experiment] = new PublicLinkShareView($links[$experiment] ?? null, $block);
        }

        return $states;
    }
}
