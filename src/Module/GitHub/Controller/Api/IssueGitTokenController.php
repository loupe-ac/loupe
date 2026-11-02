<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\GitHub\Command\IssueGitTokenCommand;
use App\Module\GitHub\Command\IssueGitTokenHandler;
use App\Module\GitHub\Service\GitHubGitTokenRefused;
use App\Security\CredentialRateLimitKey;
use OpenApi\Attributes as OA;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hands a managed worker a GitHub App token that opens one repository of one
 * project and no other. The firewall admits agent-scoped tokens alone, and a
 * token bound to one project reaches no other project of its owner. The request
 * names the repository, and the issuer serves only one the project holds.
 *
 * The rate limit key expression sees only the request, the arguments and this
 * controller, so the key service rides on a public property.
 */
#[OA\Post(
    operationId: 'issueGitToken',
    summary: 'Issue a push token for one repository',
    tags: ['Tokens'],
    parameters: [
        new OA\Parameter(name: 'handle', description: 'A project id or a project slug.', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 201, description: 'The token.', content: new OA\JsonContent(type: 'object')),
        new OA\Response(response: 400, description: 'Refused: `invalid_json`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `git_identity_not_managed`, `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `project_not_found`, `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 409, description: 'Refused: `app_not_configured`, `no_installation`, `installation_suspended`, `no_repository`, `missing_grant`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 415, description: 'Refused: `unsupported_media_type`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 422, description: 'Refused: `validation_failed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 502, description: 'Refused: `github_unavailable`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    ],
)]
#[RateLimit('agent_git_token', key: new Expression('this.rateLimitKey.forRequest(request)'))]
#[Route(
    '/api/v1/bridge/projects/{handle}/git-token',
    name: 'api_project_git_token_issue',
    requirements: ['handle' => '[^/]+'],
    methods: ['POST'],
)]
#[Route(
    '/api/projects/{handle}/git-token',
    name: 'api_legacy_project_git_token_issue',
    requirements: ['handle' => '[^/]+'],
    methods: ['POST'],
)]
final class IssueGitTokenController extends AppController
{
    public function __construct(
        private readonly IssueGitTokenHandler $issueGitToken,
        public readonly CredentialRateLimitKey $rateLimitKey,
    ) {
    }

    public function __invoke(string $handle, #[MapRequestPayload] IssueGitTokenRequest $payload): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('The git token endpoint was reached without an authenticated User.');
        }

        try {
            $token = ($this->issueGitToken)(new IssueGitTokenCommand(
                $user,
                $handle,
                $payload->repository ?? throw new \LogicException('repository required after validation'),
            ));
        } catch (DomainErrors $e) {
            throw isset($e->errors['handle']) ? ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'project_not_found', 'No project answers this handle.') : ApiProblemException::refused(JsonResponse::HTTP_FORBIDDEN, 'git_identity_not_managed', 'This project does not manage its git identity.');
        } catch (GitHubGitTokenRefused $refused) {
            throw ApiProblemException::refused(GitHubGitTokenRefused::GITHUB_UNAVAILABLE === $refused->cause ? JsonResponse::HTTP_BAD_GATEWAY : JsonResponse::HTTP_CONFLICT, $refused->cause, 'GitHub did not issue a git token.', ['missing' => $refused->missingGrants]);
        }

        return $this->json([
            'token' => $token->token,
            'expiresAt' => $token->expiresAt->format(\DATE_ATOM),
        ], JsonResponse::HTTP_CREATED);
    }
}
