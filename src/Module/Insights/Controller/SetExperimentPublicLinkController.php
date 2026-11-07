<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Insights\Command\SetExperimentPublicLinkCommand;
use App\Module\Insights\Command\SetExperimentPublicLinkHandler;
use App\Module\Insights\Form\ShareExperimentRequest;
use App\Module\Insights\Service\ExperimentShareForms;
use App\Module\Insights\Service\ExperimentShareStates;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/experiments/{experiment}/share',
    name: 'app_project_analytics_experiment_share',
    requirements: ['experiment' => WorkerRun::EXPERIMENT_NAME],
    methods: ['POST'],
)]
class SetExperimentPublicLinkController extends AppController
{
    public function __construct(
        private readonly SetExperimentPublicLinkHandler $handler,
        private readonly ExperimentShareForms $forms,
        private readonly ExperimentShareStates $states,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Project $project, string $experiment, Request $request): Response
    {
        $data = new ShareExperimentRequest();
        $form = $this->forms->share($project, $experiment, $data);
        $form->handleRequest($request);

        $invalid = !$form->isSubmitted() || !$form->isValid();
        $error = $invalid ? $this->translator->trans('bridge.experiments.share.invalid') : null;
        $unarchived = false;
        if (!$invalid) {
            try {
                $unarchived = ($this->handler)(new SetExperimentPublicLinkCommand($project, $experiment, $data->enabled, $data->note));
            } catch (DomainErrors $e) {
                $error = $this->translator->trans($e->errors['enabled'] ?? throw $e);
            }
        }

        // The page shows an archived state in several places, so a share that unarchives reloads the page.
        $isStream = TurboBundle::STREAM_FORMAT === $request->getPreferredFormat();
        if (!$isStream || $unarchived) {
            if (null !== $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('app_project_analytics_experiment', ['id' => (string) $project->id, 'experiment' => $experiment]);
        }

        $html = $this->renderView('@Insights/_share_experiment.stream.html.twig', [
            'project' => $project,
            'experiment' => $experiment,
            'share' => $this->states->for($project, $experiment),
            'error' => $error,
        ]);

        return new Response($html, null !== $error ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
