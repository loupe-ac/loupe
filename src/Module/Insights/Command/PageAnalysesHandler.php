<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Repository\AnalysisRepository;
use App\Module\Insights\Repository\ProposalRepository;

final readonly class PageAnalysesHandler
{
    public function __construct(
        private AnalysisRepository $analyses,
        private ProposalRepository $proposals,
    ) {
    }

    /** @return list<AnalysisDetailView> at most `limit` analyses, newest first */
    public function __invoke(PageAnalysesCommand $command): array
    {
        $after = null !== $command->afterCreatedAt && null !== $command->afterId ? [$command->afterCreatedAt, $command->afterId] : null;
        $analyses = $this->analyses->pageByProject($command->project, $command->limit, $after);
        $costs = $this->analyses->costsOf($command->project, $analyses);
        $proposals = $this->proposals->findByAnalyses($analyses);

        return array_map(
            static fn (Analysis $analysis): AnalysisDetailView => new AnalysisDetailView($analysis, $costs[(string) $analysis->id] ?? null, $proposals[(string) $analysis->id] ?? []),
            $analyses,
        );
    }
}
