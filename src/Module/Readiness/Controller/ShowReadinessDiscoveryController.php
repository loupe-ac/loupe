<?php

declare(strict_types=1);

namespace App\Module\Readiness\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Readiness\Command\ShowReadinessCommand;
use App\Module\Readiness\Command\ShowReadinessHandler;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The discovery row of the readiness settings, which the live region reads when a discovery run changes. */
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/settings/readiness/discovery',
    name: 'app_readiness_settings_discovery',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowReadinessDiscoveryController extends AppController
{
    public function __construct(
        private readonly ShowReadinessHandler $showReadiness,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        return $this->render('@Readiness/_discovery_frame.html.twig', [
            'project' => $project,
            'discovery' => ($this->showReadiness)(new ShowReadinessCommand($project))->readiness->row('repository'),
        ]);
    }
}
