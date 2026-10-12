<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller;

use App\Controller\AppController;
use App\Module\Artifact\Command\ShowArtifactCommand;
use App\Module\Artifact\Command\ShowArtifactHandler;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Artifact\Security\ArtifactVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

#[IsGranted(ArtifactVoter::VIEW, subject: 'artifact')]
#[RequireFeatureFlag(ArtifactInstallFlags::FLAG_ENABLED)]
#[Route(
    '/projects/{id:project}/artifacts/{artifactId}',
    name: 'app_project_artifact',
    requirements: ['artifactId' => Requirement::UUID],
    methods: ['GET'],
)]
#[Route(
    '/projects/{id:project}/artifacts/{artifactId}/versions/{number}',
    name: 'app_project_artifact_version',
    requirements: ['artifactId' => Requirement::UUID, 'number' => Requirement::POSITIVE_INT],
    methods: ['GET'],
)]
#[Route(
    '/projects/{id:project}/artifacts/{artifactId}/full-screen',
    name: 'app_project_artifact_full',
    requirements: ['artifactId' => Requirement::UUID],
    defaults: ['fullScreen' => true],
    methods: ['GET'],
)]
#[Route(
    '/projects/{id:project}/artifacts/{artifactId}/versions/{number}/full-screen',
    name: 'app_project_artifact_version_full',
    requirements: ['artifactId' => Requirement::UUID, 'number' => Requirement::POSITIVE_INT],
    defaults: ['fullScreen' => true],
    methods: ['GET'],
)]
final class ShowArtifactController extends AppController
{
    public function __construct(
        private readonly ShowArtifactHandler $showArtifact,
    ) {
    }

    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(artifactId, project)')] Artifact $artifact,
        ?int $number = null,
        bool $fullScreen = false,
        #[MapQueryParameter] ?string $file = null,
    ): Response {
        $view = ($this->showArtifact)(new ShowArtifactCommand($artifact, $number, '' === $file ? null : $file))
            ?? throw $this->createNotFoundException('The artifact has no such version or file.');

        return $this->render('@Artifact/show_artifact.html.twig', [
            'project' => $artifact->project,
            'artifact' => $view->artifact,
            'version' => $view->version,
            'pinned' => null !== $number,
            'fullScreen' => $fullScreen,
            'versions' => $view->versions,
            'cards' => $view->cards,
            'files' => $view->files,
            'file' => $view->file,
            'mainFile' => $view->mainFile,
            'contentLink' => $view->contentLink,
            'ownContentOrigin' => $view->ownContentOrigin,
            'allowedOrigins' => $view->allowedOrigins,
        ]);
    }
}
