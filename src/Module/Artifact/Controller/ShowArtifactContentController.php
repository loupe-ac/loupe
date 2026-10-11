<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller;

use App\Controller\AppController;
use App\Module\Artifact\Command\ShowArtifactContentCommand;
use App\Module\Artifact\Command\ShowArtifactContentHandler;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Artifact\Service\ArtifactContentSigner;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Serves one stored file under a signed link. The signature is the only
 * credential: the firewall for this path reads no session and sets no cookie.
 *
 * The response carries its own CSP, and the nelmio listener leaves a response
 * that already has one alone. The sandbox has no allow-same-origin, so a script
 * in a prototype runs with an opaque origin and cannot reach the app.
 */
#[RequireFeatureFlag(ArtifactInstallFlags::FLAG_ENABLED)]
#[Route(
    '/artifact-content/{versionId}/{expires}/{signature}/{path}',
    name: 'app_artifact_content',
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
    public const string CONTENT_SECURITY_POLICY = "sandbox allow-scripts allow-forms allow-popups allow-pointer-lock; frame-ancestors 'self'";

    public function __construct(
        private readonly ShowArtifactContentHandler $showContent,
        private readonly ArtifactContentSigner $signer,
    ) {
    }

    public function __invoke(string $versionId, string $expires, string $signature, string $path): Response
    {
        if (!$this->signer->isValid($versionId, (int) $expires, $signature)) {
            throw $this->createNotFoundException('The link is not valid.');
        }

        $file = ($this->showContent)(new ShowArtifactContentCommand($versionId, $path))
            ?? throw $this->createNotFoundException('The version has no such file.');

        $response = new Response($file->blob->contents(), Response::HTTP_OK, [
            'Content-Type' => $file->contentType,
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            // An opaque-origin page sends Origin: null, and a module script or a fetch needs this to read the file.
            'Access-Control-Allow-Origin' => '*',
        ]);
        $response->setPrivate();

        return $response;
    }
}
