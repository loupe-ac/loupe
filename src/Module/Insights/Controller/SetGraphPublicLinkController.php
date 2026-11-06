<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Insights\Command\SetGraphPublicLinkCommand;
use App\Module\Insights\Command\SetGraphPublicLinkHandler;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Form\ShareGraphRequest;
use App\Module\Insights\Service\DashboardGraphForms;
use App\Module\Insights\Service\GraphShareStates;
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

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/dashboard/graphs/{graphId}/share',
    name: 'app_project_analytics_dashboard_graph_share',
    requirements: ['graphId' => Requirement::UUID],
    methods: ['POST'],
)]
class SetGraphPublicLinkController extends AppController
{
    public function __construct(
        private readonly SetGraphPublicLinkHandler $handler,
        private readonly DashboardGraphForms $forms,
        private readonly GraphShareStates $states,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(graphId, project)')] InsightsSavedGraph $graph,
        Request $request,
    ): Response {
        $data = new ShareGraphRequest();
        $form = $this->forms->share($graph, $data);
        $form->handleRequest($request);

        $invalid = !$form->isSubmitted() || !$form->isValid();
        $error = $invalid ? $this->translator->trans('analytics.dashboard.flash.invalid') : null;
        if (!$invalid) {
            try {
                ($this->handler)(new SetGraphPublicLinkCommand($graph, $data->enabled));
            } catch (DomainErrors $e) {
                $error = $this->translator->trans($e->errors['enabled'] ?? throw $e);
            }
        }

        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            if (null !== $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('app_project_analytics_dashboard', ['id' => (string) $project->id]);
        }

        $html = $this->renderView('@Insights/_share_graph.stream.html.twig', [
            'project' => $project,
            'graph' => $graph,
            'share' => $this->states->for($graph),
            'error' => $invalid ? $error : null,
        ]);

        return new Response($html, $invalid ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
