<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Artifact\Command\ArtifactFileInput;
use App\Module\Artifact\Command\StoreArtifactVersionCommand;
use App\Module\Artifact\Command\StoreArtifactVersionHandler;
use App\Security\CredentialRateLimitKey;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\MapUploadedFile;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Stores the files of one prototype as a new version. The firewall admits
 * agent-scoped tokens alone, and the project must belong to the token's owner.
 *
 * The rate limit key expression sees only the request, the arguments and this
 * controller, so the key service rides on a public property.
 */
#[RateLimit('agent_artifact_store', key: new Expression('this.rateLimitKey.forRequest(request)'))]
#[Route(
    '/api/v1/bridge/projects/{handle}/artifacts',
    name: 'api_project_artifact_store',
    requirements: ['handle' => '[^/]+'],
    methods: ['POST'],
)]
#[Route(
    '/api/projects/{handle}/artifacts',
    name: 'api_legacy_project_artifact_store',
    requirements: ['handle' => '[^/]+'],
    methods: ['POST'],
)]
final class StoreArtifactVersionController extends AppController
{
    public const string SESSION_HEADER = 'X-Loupe-Session';

    public function __construct(
        private readonly StoreArtifactVersionHandler $storeVersion,
        private readonly TranslatorInterface $translator,
        public readonly CredentialRateLimitKey $rateLimitKey,
    ) {
    }

    /** @param list<UploadedFile> $files */
    public function __invoke(
        string $handle,
        Request $request,
        #[MapRequestPayload] StoreArtifactVersionRequest $payload,
        #[MapUploadedFile] array $files = [],
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Artifact store reached without an authenticated User.');
        }

        if (array_values($files) !== $files || \count($files) !== \count($payload->paths)) {
            return $this->json(
                ['error' => 'artifact_refused', 'errors' => ['files' => $this->translator->trans('artifact.error.paths_mismatch')]],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $inputs = [];
        foreach ($files as $index => $file) {
            $contents = file_get_contents($file->getPathname());
            if (false === $contents) {
                throw new \RuntimeException('An uploaded file could not be read.');
            }

            $inputs[] = new ArtifactFileInput(
                $payload->paths[$index],
                $contents,
                $file->getClientMimeType(),
            );
        }

        try {
            $version = ($this->storeVersion)(new StoreArtifactVersionCommand(
                owner: $user,
                handle: $handle,
                artifactId: $payload->artifactId,
                name: $payload->name,
                sourceLink: $payload->sourceLink,
                sessionId: $request->headers->get(self::SESSION_HEADER),
                files: $inputs,
                card: $payload->card,
                notes: $payload->notes,
            ));
        } catch (DomainErrors $error) {
            if (isset($error->errors['project'])) {
                return $this->json(['error' => 'project_not_found'], JsonResponse::HTTP_NOT_FOUND);
            }

            return $this->json([
                'error' => 'artifact_refused',
                'errors' => array_map(fn (string $key): string => $this->translator->trans($key), $error->errors),
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $projectId = (string) $version->artifact->project->id;
        $artifactId = (string) $version->artifact->id;
        $stablePath = \sprintf('/projects/%s/artifacts/%s', $projectId, $artifactId);

        return $this->json([
            'artifactId' => $artifactId,
            'versionNumber' => $version->number,
            'path' => $stablePath,
            'versionPath' => \sprintf('%s/versions/%d', $stablePath, $version->number),
        ], JsonResponse::HTTP_CREATED);
    }
}
