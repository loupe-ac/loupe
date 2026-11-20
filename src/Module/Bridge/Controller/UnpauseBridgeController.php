<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ListAgentsCommand;
use App\Module\Bridge\Command\ListAgentsHandler;
use App\Module\Bridge\Command\SetBridgePauseCommand;
use App\Module\Bridge\Command\SetBridgePauseHandler;
use App\Module\Bridge\Entity\Bridge;
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

#[CsrfToken('bridge-pause')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/agents/{bridgeId}/unpause',
    name: 'app_project_bridge_unpause',
    requirements: ['bridgeId' => Requirement::UUID],
    methods: ['POST'],
)]
final class UnpauseBridgeController extends AppController
{
    public function __construct(
        private readonly SetBridgePauseHandler $setBridgePause,
        private readonly ListAgentsHandler $listAgents,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        Project $project,
        #[MapEntity(expr: 'repository.findOneFollowingProject(project, bridgeId)')] Bridge $bridge,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('The project voter admits only a signed-in user.');
        }

        $messages = [];
        try {
            ($this->setBridgePause)(new SetBridgePauseCommand($project->owner, $bridge->id, false, $user));
        } catch (DomainErrors $e) {
            $messages = array_values(array_map($this->translator->trans(...), $e->errors));
        }

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $body = '';
            foreach (($this->listAgents)(new ListAgentsCommand($project))->connections as $connection) {
                if ($connection->bridge->id->equals($bridge->id)) {
                    $body .= $this->renderView('@Bridge/_agent_connection.stream.html.twig', ['project' => $project, 'connection' => $connection]);
                }
            }
            foreach ($messages as $message) {
                $body .= $this->renderView('_flash.stream.html.twig', ['severity' => 'error', 'message' => $message]);
            }

            return new Response($body, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
        }

        foreach ($messages as $message) {
            $this->addFlash('bridge-pause', $message);
        }

        return $this->redirectToRoute('app_project_agents', [
            'id' => (string) $project->id,
            '_fragment' => 'agent-connection-'.$bridge->id,
        ], Response::HTTP_SEE_OTHER);
    }
}
