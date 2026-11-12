<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicDelete;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Module\Insights\Command\DeleteBucketRuleCommand;
use App\Module\Insights\Command\DeleteBucketRuleHandler;
use App\Module\Insights\Service\BucketRuleLookup;
use App\Module\Insights\Service\InsightsApiProblems;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicDelete(
    operationId: 'deleteBucketRule',
    summary: 'Delete a time bucket rule',
    tags: ['Bucket rules'],
    description: 'The tool calls it matched fall to a later rule or to the other bucket, and the bucket times of the project are computed again.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'rule', description: 'The id of a bucket rule, from the rule list.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new SuccessResponse(200, 'The rule is deleted.', BucketRuleDeletedResponse::class),
        new ProblemResponse(404, ['project_not_found', 'bucket_rule_not_found', 'route_not_found']),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/bucket-rules/{rule}',
    name: 'api_bucket_rule_delete',
    requirements: ['rule' => Requirement::UUID],
    methods: ['DELETE'],
)]
final class DeleteProjectBucketRuleController extends AppController
{
    public function __construct(
        private readonly BucketRuleLookup $lookup,
        private readonly DeleteBucketRuleHandler $deleteRule,
        private readonly InsightsApiProblems $problems,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, string $rule): JsonResponse
    {
        $found = $this->lookup->find($project, $rule) ?? throw $this->problems->bucketRuleNotFound();
        $id = (string) $found->id;

        ($this->deleteRule)(new DeleteBucketRuleCommand($found));

        return new JsonResponse(new BucketRuleDeletedResponse($id));
    }
}
