<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller;

use App\Controller\AppController;
use App\Module\Artifact\Command\ShowArtifactContentCommand;
use App\Module\Artifact\Command\ShowArtifactContentHandler;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Artifact\Service\ArtifactContentHost;
use App\Module\Artifact\Service\ArtifactContentPolicy;
use App\Module\Artifact\Service\ArtifactContentSigner;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Serves one stored file under a signed link. The signature is the only
 * credential: the firewall for these paths reads no session and sets no cookie.
 *
 * The response carries its own CSP, and the nelmio listener leaves a response
 * that already has one alone. On the app domain the sandbox has no
 * allow-same-origin, so a script in a prototype runs with an opaque origin. On
 * the content domain each artifact has its own sub-address and keeps its origin.
 */
#[RequireFeatureFlag(ArtifactInstallFlags::FLAG_ENABLED)]
#[Route(
    '/artifact-content/{versionId}/{expires}/{signature}/{path}',
    name: self::ROUTE_SEALED,
    requirements: [
        'versionId' => Requirement::UUID,
        'expires' => Requirement::DIGITS,
        'signature' => '[a-f0-9]{64}',
        'path' => '.+',
    ],
    methods: ['GET'],
    stateless: true,
)]
#[Route(
    '/{versionId}/{expires}/{signature}/{path}',
    name: self::ROUTE_HOST,
    requirements: [
        'versionId' => Requirement::UUID,
        'expires' => Requirement::DIGITS,
        'signature' => '[a-f0-9]{64}',
        'path' => '.+',
    ],
    methods: ['GET'],
    stateless: true,
)]
final class ShowArtifactContentController extends AppController
{
    public const string ROUTE_SEALED = 'app_artifact_content';
    public const string ROUTE_HOST = 'app_artifact_content_host';

    public function __construct(
        private readonly ShowArtifactContentHandler $showContent,
        private readonly ArtifactContentSigner $signer,
        private readonly ArtifactContentHost $contentHost,
        private readonly ArtifactContentPolicy $policy,
    ) {
    }

    public function __invoke(Request $request, string $versionId, string $expires, string $signature, string $path): Response
    {
        $label = null;
        if (self::ROUTE_HOST === $request->attributes->get('_route')) {
            $label = $this->contentHost->labelOfRequestHost($request->getHost())
                ?? throw $this->createNotFoundException('The address is not a content address.');
        }

        if (!$this->signer->isValid($versionId, (int) $expires, $signature, $label ?? '')) {
            throw $this->createNotFoundException('The link is not valid.');
        }

        $file = ($this->showContent)(new ShowArtifactContentCommand($versionId, $path, $label))
            ?? throw $this->createNotFoundException('The version has no such file.');

        $response = new Response($file->blob->contents(), Response::HTTP_OK, [
            'Content-Type' => $file->contentType,
            'Content-Security-Policy' => $this->policy->forResponse(null !== $label),
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            // A sandboxed page sends Origin: null, and a module script or a fetch needs this to read the file.
            'Access-Control-Allow-Origin' => '*',
        ]);
        $response->setPrivate();

        return $response;
    }
}
