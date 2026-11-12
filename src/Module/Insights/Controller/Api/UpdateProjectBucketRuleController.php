<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPatch;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Module\Insights\Command\DescribeBucketRuleCommand;
use App\Module\Insights\Command\DescribeBucketRuleHandler;
use App\Module\Insights\Command\PlaceBucketRuleCommand;
use App\Module\Insights\Command\PlaceBucketRuleHandler;
use App\Module\Insights\Service\BucketRuleLookup;
use App\Module\Insights\Service\InsightsApiProblems;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicPatch(
    operationId: 'updateBucketRule',
    summary: 'Move a time bucket rule to another place',
    tags: ['Bucket rules'],
    description: 'A tool call takes the bucket of the first rule that matches it, so the order decides. The bucket times of the project are computed again.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'rule', description: 'The id of a bucket rule, from the rule list.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UpdateBucketRuleRequest::class))),
    responses: [
        new SuccessResponse(200, 'The rule after the move.', BucketRuleResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'bucket_rule_not_found', 'route_not_found']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/bucket-rules/{rule}',
    name: 'api_bucket_rule_update',
    requirements: ['rule' => Requirement::UUID],
    methods: ['PATCH'],
)]
final class UpdateProjectBucketRuleController extends AppController
{
    public function __construct(
        private readonly BucketRuleLookup $lookup,
        private readonly PlaceBucketRuleHandler $placeRule,
        private readonly DescribeBucketRuleHandler $describeRule,
        private readonly InsightsApiProblems $problems,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, string $rule, #[MapRequestPayload] UpdateBucketRuleRequest $payload): JsonResponse
    {
        $found = $this->lookup->find($project, $rule) ?? throw $this->problems->bucketRuleNotFound();

        try {
            ($this->placeRule)(new PlaceBucketRuleCommand($found, $payload->position ?? throw new \LogicException('position required after validation')));
        } catch (NotFoundHttpException) {
            throw $this->problems->bucketRuleNotFound();
        }

        return new JsonResponse(BucketRuleResponse::fromRow(($this->describeRule)(new DescribeBucketRuleCommand($found))));
    }
}
