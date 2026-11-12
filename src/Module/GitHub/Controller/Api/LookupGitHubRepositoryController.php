<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\GitHub\Command\LookupGitHubRepositoryCommand;
use App\Module\GitHub\Command\LookupGitHubRepositoryHandler;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\GitHub\Service\GitHubAppApiFailed;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ReachableProject;
use App\Security\CredentialRateLimitKey;
use OpenApi\Attributes as OA;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tells `loupe init` whether the App reaches the repository of a folder and who
 * holds it. It names no project of another owner.
 */
#[IsGranted(GitHubConnectionVoter::MANAGE, subject: 'project')]
#[PublicGet(
    operationId: 'getGitHubRepositoryStatus',
    summary: 'Read how one GitHub repository stands for the project',
    tags: ['GitHub'],
    description: 'Asks GitHub whether the Loupe GitHub App reaches the repository, and the project whether it holds it. GitHub answers are cached for ten minutes. Only the project owner may ask.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'path', description: 'The repository as owner/name.', in: 'query', required: true, schema: new OA\Schema(type: 'string'), example: 'acme/api'),
    ],
    responses: [
        new SuccessResponse(200, 'How the repository stands.', LookupGitHubRepositoryResponse::class),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
        new ProblemResponse(502, ['github_unavailable']),
    ],
)]
#[RateLimit('github_repository_lookup', key: new Expression('this.rateLimitKey.forRequest(request)'))]
#[Route(
    '/api/v1/projects/{project}/github/repositories/status',
    name: 'api_github_repository_status',
    methods: ['GET'],
)]
final class LookupGitHubRepositoryController extends AppController
{
    public function __construct(
        private readonly LookupGitHubRepositoryHandler $lookup,
        public readonly CredentialRateLimitKey $rateLimitKey,
    ) {
    }

    private const string PATH_PATTERN = '#^[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}$#';

    public function __invoke(#[ReachableProject] Project $project, Request $request): JsonResponse
    {
        $path = $request->query->getString('path');
        if ('' === $path || 1 !== preg_match(self::PATH_PATTERN, $path)) {
            throw ApiProblemException::validationFailed([['field' => 'path', 'code' => 'path_invalid', 'message' => 'path must be a repository written as owner/name.']]);
        }

        try {
            $standing = ($this->lookup)(new LookupGitHubRepositoryCommand($project, $path));
        } catch (GitHubAppApiFailed) {
            throw ApiProblemException::refused(JsonResponse::HTTP_BAD_GATEWAY, 'github_unavailable', 'GitHub did not answer.');
        }

        return new JsonResponse(new LookupGitHubRepositoryResponse($path, $standing->value));
    }
}
