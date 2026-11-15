<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Repository\AnalysisRepository;
use App\Module\Insights\Repository\ProposalRepository;

final readonly class ListReportRowsHandler
{
    public function __construct(
        private AnalysisRepository $analyses,
        private ProposalRepository $proposals,
    ) {
    }

    public function __invoke(ListReportRowsCommand $command): ListReportRowsView
    {
        $analyses = $this->analyses->findByProjectAndIds($command->project, $command->ids);
        $costs = $this->analyses->costsOf($command->project, $analyses);
        $proposals = $this->proposals->findByAnalyses($analyses);

        return new ListReportRowsView(
            rows: array_map(
                static fn (Analysis $analysis): AnalysisDetailView => new AnalysisDetailView($analysis, $costs[(string) $analysis->id] ?? null, $proposals[(string) $analysis->id] ?? []),
                $analyses,
            ),
            newer: null === $command->since ? 0 : $this->analyses->countCreatedAfter($command->project, $command->since),
        );
    }
}
