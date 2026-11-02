<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use App\Api\OpenApi\BinaryResponse;
use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Artifact\Command\ReadArtifactFileCommand;
use App\Module\Artifact\Command\ReadArtifactFileHandler;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Security\ArtifactVoter;
use App\Module\Artifact\Security\ReachableArtifact;
use App\Module\Artifact\Service\ArtifactApiProblems;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Serves a stored file with its stored content type, so the content is not ours to trust.
 * The answer is an attachment that the browser saves instead of rendering. The sandbox
 * policy keeps it inert even where a client renders it, and nosniff keeps the stored type.
 */
#[IsGranted(ArtifactVoter::VIEW, subject: 'artifact')]
#[PublicGet(
    operationId: 'readArtifactFile',
    summary: 'Read one file of an artifact',
    tags: ['Artifacts'],
    description: 'The answer is the file itself, with its stored content type.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'artifact', description: 'The artifact id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'path', description: 'The path of the file inside the version, as the artifact lists it. Percent-encode each `/` as `%2F`, because OpenAPI 3.0 path parameters hold no slash. The route also takes a raw `/`.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'assets%2Flogo.svg'),
        new OA\Parameter(name: 'versionNumber', description: 'The artifact version to read. It defaults to the latest.', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
    ],
    responses: [
        new BinaryResponse(200, 'The bytes of the file.'),
        new ProblemResponse(404, ['project_not_found', 'artifact_not_found', 'artifact_version_not_found', 'artifact_file_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/artifacts/{artifact}/files/{path}',
    name: 'api_artifact_file_read',
    requirements: ['path' => '.+'],
    methods: ['GET'],
)]
final class ReadProjectArtifactFileController extends AppController
{
    public function __construct(
        private readonly ReadArtifactFileHandler $readFile,
        private readonly ArtifactApiProblems $problems,
    ) {
    }

    public function __invoke(Request $request, #[ReachableArtifact] Artifact $artifact, string $path): Response
    {
        try {
            $file = ($this->readFile)(new ReadArtifactFileCommand($artifact, $path, VersionNumber::fromRequest($request)));
        } catch (DomainErrors $e) {
            throw isset($e->errors['path']) ? $this->problems->fileNotFound() : $this->problems->versionNotFound();
        }

        $name = basename($file->path);
        $response = new Response($file->blob->contents(), Response::HTTP_OK, [
            'Content-Type' => 1 === preg_match('/[\x00-\x1f\x7f]/', $file->contentType) ? 'application/octet-stream' : $file->contentType,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, (string) preg_replace('/[^\x20-\x7e]|[%\\\\"\/]/', '_', $name)),
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
        $response->setPrivate();

        return $response;
    }
}
