<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Api\OpenApi\RateLimitedResponse;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\GitHub\View\GitHubRepositoryFacts;
use App\Module\SiteReview\Command\ListedSite;
use App\Module\SiteReview\Command\ListSitesCommand;
use App\Module\SiteReview\Command\ListSitesHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lists the caller's projects for the loupe CLI, which checks a token with it
 * and names the valid slugs when a rule file names an unknown project. Each
 * project says what the bridge collects from the tool calls of its runs,
 * which skill packages its workers need, whether it needs a profile, whether
 * the owner still has to start discovery, and the GitHub facts of its
 * repositories.
 *
 * Agent-scoped tokens only. The firewall grants this path to
 * ROLE_API_AGENT alone, so a project-bound widget token gets 403
 * `insufficient_scope` before this class runs. A widget token is embedded in
 * public page HTML, and its contract is one project's comments and nothing
 * else; enumerating the owner's projects would leak the inventory to any page
 * visitor. Project ownership is enforced by the handler's owner-scoped lookup.
 */
#[OA\Get(
    operationId: 'listBridgeProjects',
    summary: 'List the projects of the caller for the CLI',
    tags: ['Bridge'],
    responses: [
        new OA\Response(response: 200, description: 'The projects, with what the bridge collects from their tool calls.', content: new OA\JsonContent(ref: '#/components/schemas/Sites')),
        new OA\Response(response: 401, description: 'Refused: `invalid_credentials`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 402, description: 'Refused: `subscription_required`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 403, description: 'Refused: `credential_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 404, description: 'Refused: `route_not_found`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        new RateLimitedResponse(),
    ],
)]
#[Route(
    '/api/v1/bridge/projects',
    name: 'api_projects',
    methods: ['GET'],
)]
final class ListSitesController extends AppController
{
    public function __construct(
        private readonly ListSitesHandler $listSites,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Sites endpoint reached without an authenticated User.');
        }

        $view = ($this->listSites)(new ListSitesCommand($user));

        return $this->json(['sites' => array_values(array_map(
            static fn (ListedSite $site): array => [
                'id' => (string) $site->project->id,
                'slug' => $site->project->slug,
                'name' => $site->project->name,
                'collectFullText' => $site->collectFullText,
                'subcommandPrograms' => $site->subcommandPrograms,
                'skillPackages' => $site->skillPackages,
                'needsProfile' => $site->needsProfile,
                'discoveryRemains' => $site->discoveryRemains,
                'github' => [
                    'appInstalled' => $site->github->appInstalled,
                    'repositories' => array_map(
                        static fn (GitHubRepositoryFacts $repository): array => [
                            'path' => $repository->path,
                            'appInstalled' => $repository->appInstalled,
                            'defaultBranch' => $repository->defaultBranch,
                            'rulesRead' => $repository->rulesRead,
                            'reviewRequired' => $repository->reviewRequired,
                            'mergeApproval' => $repository->mergeApproval,
                            'mergeApprovalSource' => $repository->mergeApprovalSource,
                            'mergeApprovalPullRequestUrl' => $repository->mergeApprovalPullRequestUrl,
                        ],
                        $site->github->repositories,
                    ),
                ],
            ],
            $view->sites,
        ))]);
    }
}
