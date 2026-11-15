<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller;

use App\Controller\AppController;
use App\Module\Artifact\Command\ShowArtifactLiveBandCommand;
use App\Module\Artifact\Command\ShowArtifactLiveBandHandler;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Artifact\Security\ArtifactVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/** The band of an open artifact page, fetched when a signal says the artifact changed. */
#[IsGranted(ArtifactVoter::VIEW, subject: 'artifact')]
#[RequireFeatureFlag(ArtifactInstallFlags::FLAG_ENABLED)]
#[Route(
    '/projects/{id:project}/artifacts/{artifactId}/live-band',
    name: 'app_project_artifact_live_band',
    requirements: ['artifactId' => Requirement::UUID],
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowArtifactLiveBandController extends AppController
{
    public function __construct(
        private readonly ShowArtifactLiveBandHandler $showLiveBand,
    ) {
    }

    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(artifactId, project)')] Artifact $artifact,
        Request $request,
        #[MapQueryParameter] int $shown = 0,
        #[MapQueryParameter] bool $archived = false,
        #[MapQueryParameter] bool $full = false,
    ): Response {
        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return $this->redirectToRoute('app_project_artifact', [
                'id' => (string) $artifact->project->id,
                'artifactId' => (string) $artifact->id,
            ]);
        }

        $view = ($this->showLiveBand)(new ShowArtifactLiveBandCommand($artifact, $shown, $archived));

        return new Response(
            $this->renderView('@Artifact/_live_band.stream.html.twig', [
                'project' => $artifact->project,
                'artifact' => $artifact,
                'view' => $view,
                'full' => $full,
            ]),
            Response::HTTP_OK,
            ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }
}
