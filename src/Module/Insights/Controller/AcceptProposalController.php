<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Insights\Command\AcceptProposalCommand;
use App\Module\Insights\Command\AcceptProposalHandler;
use App\Module\Insights\Entity\Proposal;
use App\Module\Insights\Entity\ProposalKind;
use App\Module\Insights\Service\ReportStreamAnswers;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
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
        private readonly ReportStreamAnswers $answers,
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
            return $this->answers->row($project, $proposal, $request, 'error', implode(' ', array_map($this->translator->trans(...), $e->errors)), status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->answers->row($project, $proposal, $request, 'success', $this->translator->trans(ProposalKind::BucketRule === $accepted->kind ? 'insights.reports.flash.rule_accepted' : 'insights.reports.flash.accepted'));
    }
}
