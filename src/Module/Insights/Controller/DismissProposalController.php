<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Insights\Command\DismissProposalCommand;
use App\Module\Insights\Command\DismissProposalHandler;
use App\Module\Insights\Entity\Proposal;
use App\Module\Insights\Form\DismissProposalFormType;
use App\Module\Insights\Form\DismissProposalRequest;
use App\Module\Insights\Service\ReportStreamAnswers;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/proposals/{proposalId}/dismiss',
    name: 'app_project_analytics_proposal_dismiss',
    requirements: ['proposalId' => Requirement::UUID],
    methods: ['POST'],
)]
class DismissProposalController extends AppController
{
    public function __construct(
        private readonly ReportStreamAnswers $answers,
        private readonly DismissProposalHandler $dismissProposal,
        private readonly FormFactoryInterface $formFactory,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Project $project,
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(proposalId, project)')] Proposal $proposal,
    ): Response {
        $data = new DismissProposalRequest();
        $form = $this->formFactory->createNamed(DismissProposalFormType::nameFor($proposal), DismissProposalFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->dismissProposal)(new DismissProposalCommand($proposal, $data->reason));

                return $this->answers->row($project, $proposal, $request, 'success', $this->translator->trans('insights.reports.flash.dismissed'));
            } catch (DomainErrors $e) {
                if (!\in_array(DismissProposalHandler::REASON_TOO_LONG, $e->errors, true)) {
                    // The proposal is no longer open, so the row has no form to show the error on.
                    return $this->answers->row($project, $proposal, $request, 'error', implode(' ', array_map($this->translator->trans(...), $e->errors)), status: Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $this->applyDomainErrors($form, $e);
            }
        }

        return $this->answers->row($project, $proposal, $request, 'error', null, $form->createView(), Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
