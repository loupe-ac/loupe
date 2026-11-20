<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Bridge\Metric\Metric;
use App\Module\Insights\Command\AcceptProposalCommand;
use App\Module\Insights\Command\AcceptProposalHandler;
use App\Module\Insights\Command\ListReportRowsCommand;
use App\Module\Insights\Command\ListReportRowsHandler;
use App\Module\Insights\Entity\Proposal;
use App\Module\Insights\Entity\ProposalKind;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('insights-proposal-accept')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/proposals/{proposalId}/accept',
    name: 'app_project_analytics_proposal_accept',
    requirements: ['proposalId' => Requirement::UUID],
    methods: ['POST'],
)]
class AcceptProposalController extends AppController
{
    public function __construct(
        private readonly ListReportRowsHandler $listRows,
        private readonly AcceptProposalHandler $acceptProposal,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Project $project,
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(proposalId, project)')] Proposal $proposal,
    ): Response {
        try {
            $accepted = ($this->acceptProposal)(new AcceptProposalCommand($proposal));
        } catch (DomainErrors $e) {
            $analysisId = $proposal->analysis->id ?? throw new \LogicException('A kept analysis has an id.');
            $html = $this->renderView('@Insights/_report_row.stream.html.twig', [
                'project' => $project,
                'analysisId' => (string) $analysisId,
                'row' => ($this->listRows)(new ListReportRowsCommand($project, [$analysisId]))->rows[0] ?? null,
                'page' => max(1, $request->query->getInt('page', 1)),
                'costMetric' => Metric::Cost->valueType(),
                'refusedDismissForm' => null,
                'severity' => 'error',
                'message' => implode(' ', array_map($this->translator->trans(...), $e->errors)),
            ]);

            return new Response($html, Response::HTTP_UNPROCESSABLE_ENTITY, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
        }

        $analysisId = $proposal->analysis->id ?? throw new \LogicException('A kept analysis has an id.');
        $html = $this->renderView('@Insights/_report_row.stream.html.twig', [
            'project' => $project,
            'analysisId' => (string) $analysisId,
            'row' => ($this->listRows)(new ListReportRowsCommand($project, [$analysisId]))->rows[0] ?? null,
            'page' => max(1, $request->query->getInt('page', 1)),
            'costMetric' => Metric::Cost->valueType(),
            'refusedDismissForm' => null,
            'severity' => 'success',
            'message' => $this->translator->trans(ProposalKind::BucketRule === $accepted->kind ? 'insights.reports.flash.rule_accepted' : 'insights.reports.flash.accepted'),
        ]);

        return new Response($html, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
