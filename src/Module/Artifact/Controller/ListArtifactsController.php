<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller;

use App\Controller\AppController;
use App\Module\Artifact\Command\ListArtifactsCommand;
use App\Module\Artifact\Command\ListArtifactsHandler;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[RequireFeatureFlag(ArtifactInstallFlags::FLAG_ENABLED)]
#[Route(
    '/projects/{id:project}/artifacts',
    name: 'app_project_artifacts',
    methods: ['GET'],
)]
final class ListArtifactsController extends AppController
{
    public function __construct(
        private readonly ListArtifactsHandler $listArtifacts,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $view = ($this->listArtifacts)(new ListArtifactsCommand(
            $project,
            $request->query->getBoolean('archived'),
        ));

        return $this->render('@Artifact/list_artifacts.html.twig', [
            'project' => $project,
            'items' => $view->items,
            'includeArchived' => $view->includeArchived,
        ]);
    }
}
