<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller;

use App\Controller\AppController;
use App\Module\Artifact\Command\ArchiveArtifactCommand;
use App\Module\Artifact\Command\ArchiveArtifactHandler;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Artifact\Security\ArtifactVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('artifact-archive')]
#[IsGranted(ArtifactVoter::MANAGE, subject: 'artifact')]
#[RequireFeatureFlag(ArtifactInstallFlags::FLAG_ENABLED)]
#[Route(
    '/projects/{id:project}/artifacts/{artifactId}/archive',
    name: 'app_project_artifact_archive',
    requirements: ['artifactId' => Requirement::UUID],
    methods: ['POST'],
)]
final class ArchiveArtifactController extends AppController
{
    public function __construct(
        private readonly ArchiveArtifactHandler $archiveArtifact,
    ) {
    }

    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(artifactId, project)')] Artifact $artifact,
        Request $request,
    ): Response {
        ($this->archiveArtifact)(new ArchiveArtifactCommand($artifact));

        // The library row posts `from=library`, so the person stays on the list.
        if ('library' === $request->request->getString('from')) {
            return $this->redirectToRoute('app_project_artifacts', array_filter([
                'id' => (string) $artifact->project->id,
                'archived' => $request->request->getBoolean('archived') ? 1 : null,
            ]));
        }

        return $this->redirectToRoute('app_project_artifact', [
            'id' => (string) $artifact->project->id,
            'artifactId' => (string) $artifact->id,
        ]);
    }
}
