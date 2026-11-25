<?php

declare(strict_types=1);

namespace App\Module\Readiness\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Readiness\Command\CancelDiscoveryCommand;
use App\Module\Readiness\Command\CancelDiscoveryHandler;
use App\Module\Readiness\Service\ReadinessChecklist;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken(ReadinessChecklist::CANCEL_TOKEN)]
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/discovery/cancel',
    name: 'app_readiness_discovery_cancel',
    methods: ['POST'],
)]
final class CancelDiscoveryController extends AppController
{
    public function __construct(
        private readonly CancelDiscoveryHandler $cancelDiscovery,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('The project voter admits only a signed-in user.');
        }
        ($this->cancelDiscovery)(new CancelDiscoveryCommand($project, $user));

        return $this->redirectToRoute('app_project_workshop', ['id' => (string) $project->id]);
    }
}
