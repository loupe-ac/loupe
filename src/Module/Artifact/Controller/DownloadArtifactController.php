<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller;

use App\Controller\AppController;
use App\Module\Artifact\Command\DownloadArtifactCommand;
use App\Module\Artifact\Command\DownloadArtifactHandler;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Artifact\Security\ArtifactVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

#[IsGranted(ArtifactVoter::VIEW, subject: 'artifact')]
#[RequireFeatureFlag(ArtifactInstallFlags::FLAG_ENABLED)]
#[Route(
    '/projects/{id:project}/artifacts/{artifactId}/download',
    name: 'app_project_artifact_download',
    requirements: ['artifactId' => Requirement::UUID],
    methods: ['GET'],
)]
#[Route(
    '/projects/{id:project}/artifacts/{artifactId}/versions/{number}/download',
    name: 'app_project_artifact_version_download',
    requirements: ['artifactId' => Requirement::UUID, 'number' => Requirement::POSITIVE_INT],
    methods: ['GET'],
)]
final class DownloadArtifactController extends AppController
{
    public function __construct(
        private readonly DownloadArtifactHandler $downloadArtifact,
    ) {
    }

    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(artifactId, project)')] Artifact $artifact,
        ?int $number = null,
    ): Response {
        $download = ($this->downloadArtifact)(new DownloadArtifactCommand($artifact, $number))
            ?? throw $this->createNotFoundException('The artifact has no such version.');

        $response = new BinaryFileResponse($download->path);
        $response->deleteFileAfterSend();
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $download->filename);
        $response->headers->set('Content-Type', 'application/zip');

        return $response;
    }
}
