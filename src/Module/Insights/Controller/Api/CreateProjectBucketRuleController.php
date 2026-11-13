<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPost;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Insights\Command\CreateBucketRuleCommand;
use App\Module\Insights\Command\CreateBucketRuleHandler;
use App\Module\Insights\Command\DescribeBucketRuleCommand;
use App\Module\Insights\Command\DescribeBucketRuleHandler;
use App\Module\Insights\Service\InsightsApiProblems;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicPost(
    operationId: 'createBucketRule',
    summary: 'Add a time bucket rule at the end of the rules',
    tags: ['Bucket rules'],
    description: 'The rule applies to the tool calls of every run, and the bucket times of the project are computed again. A project holds at most 50 rules.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'Idempotency-Key', description: 'A key of your choice. A retry with the same key and body answers as the first request did. The same key with another body gets 409 `idempotency_key_reused`.', in: 'header', schema: new OA\Schema(type: 'string', maxLength: 255)),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CreateBucketRuleRequest::class))),
    responses: [
        new SuccessResponse(201, 'The new rule.', BucketRuleResponse::class, location: true),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(409, ['bucket_rule_limit_reached', 'idempotency_key_reused', 'idempotency_in_progress']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/bucket-rules',
    name: 'api_bucket_rule_create',
    methods: ['POST'],
)]
final class CreateProjectBucketRuleController extends AppController
{
    public function __construct(
        private readonly CreateBucketRuleHandler $createRule,
        private readonly DescribeBucketRuleHandler $describeRule,
        private readonly InsightsApiProblems $problems,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, #[MapRequestPayload] CreateBucketRuleRequest $payload): JsonResponse
    {
        try {
            $rule = ($this->createRule)(new CreateBucketRuleCommand(
                $project,
                $payload->pattern ?? throw new \LogicException('pattern required after validation'),
                $payload->bucket ?? throw new \LogicException('bucket required after validation'),
            ));
        } catch (DomainErrors $e) {
            throw $this->problems->forBucketRule($e);
        }

        return new JsonResponse(
            BucketRuleResponse::fromRow(($this->describeRule)(new DescribeBucketRuleCommand($rule))),
            JsonResponse::HTTP_CREATED,
            ['Location' => $this->generateUrl('api_bucket_rule_list', ['project' => (string) $project->id], UrlGeneratorInterface::ABSOLUTE_URL)],
        );
    }
}
