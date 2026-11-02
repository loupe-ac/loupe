<?php

declare(strict_types=1);

namespace App\Tests\Api\Problem;

use App\Api\Problem\ApiPaths;
use App\Api\Problem\ApiProblem;
use App\Api\Problem\ApiProblemException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class ApiProblemTest extends TestCase
{
    public function test_it_renders_the_problem_body_with_its_extras(): void
    {
        $problem = new ApiProblem(402, 'subscription_required', 'Subscription required', 'No active plan.', extras: ['subscribeUrl' => 'https://loupe.test/billing']);

        self::assertSame([
            'type' => 'https://loupe.ac/docs/reference/api/errors#subscription_required',
            'title' => 'Subscription required',
            'status' => 402,
            'code' => 'subscription_required',
            'detail' => 'No active plan.',
            'subscribeUrl' => 'https://loupe.test/billing',
        ], $problem->toProblemBody());
    }

    public function test_it_lists_the_refused_fields_of_a_validation_problem(): void
    {
        $problem = new ApiProblem(422, 'validation_failed', 'Validation failed', 'One or more fields were refused.', errors: [['field' => 'title', 'code' => 'title_blank', 'message' => 'Blank.']]);

        self::assertSame([['field' => 'title', 'code' => 'title_blank', 'message' => 'Blank.']], $problem->toProblemBody()['errors']);
        self::assertSame([['field' => 'title', 'code' => 'title_blank', 'message' => 'Blank.']], $problem->toLegacyBody()['errors']);
    }

    #[TestWith(['credential_not_allowed', 'insufficient_scope'])]
    #[TestWith(['invalid_credentials', 'unauthorized'])]
    #[TestWith(['project_not_found', 'project_not_found'])]
    public function test_the_legacy_body_keeps_the_old_name_of_a_renamed_code(string $code, string $legacy): void
    {
        $body = new ApiProblem(403, $code, 'Title', 'Detail', extras: ['slug' => 'x'])->toLegacyBody();

        self::assertSame(['error' => $legacy, 'slug' => 'x'], $body);
    }

    #[TestWith(['/api/v1/projects', true])]
    #[TestWith(['/api/v2/projects', true])]
    #[TestWith(['/api/v1', true])]
    #[TestWith(['/api/versions', true])]
    #[TestWith(['/api/projects', false])]
    #[TestWith(['/api/events', false])]
    #[TestWith(['/api/v1x/projects', false])]
    public function test_it_tells_which_paths_answer_problem_json(string $path, bool $expected): void
    {
        self::assertSame($expected, ApiPaths::usesProblemJson($path));
    }

    #[TestWith(['/api/v1/projects', true])]
    #[TestWith(['/api/v1/openapi.json', true])]
    #[TestWith(['/api/versions', true])]
    #[TestWith(['/api/v1/bridge/heartbeat', false])]
    #[TestWith(['/api/v1/live-review-widget/review', false])]
    #[TestWith(['/api/v1/bridges', true])]
    #[TestWith(['/api/projects', false])]
    #[TestWith(['/api/v2/projects', false])]
    public function test_it_tells_which_paths_belong_to_the_public_contract(string $path, bool $expected): void
    {
        self::assertSame($expected, ApiPaths::isPublic($path));
    }

    public function test_a_legacy_error_overrides_the_code_on_an_old_path_only(): void
    {
        $problem = new ApiProblem(426, 'no_bridge_id', 'Upgrade required', 'Upgrade the CLI.', legacyError: 'Upgrade the CLI.');

        self::assertSame(['error' => 'Upgrade the CLI.'], $problem->toLegacyBody());
        self::assertSame('no_bridge_id', $problem->toProblemBody()['code']);
    }

    public function test_it_answers_problem_json_on_a_new_path(): void
    {
        $problem = new ApiProblem(404, 'run_not_found', 'Run not found', 'No such run.', headers: ['X-Test' => '1']);

        $response = $problem->toResponse('/api/v1/bridge/runs/x');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame('1', $response->headers->get('X-Test'));
        self::assertSame($problem->toProblemBody(), json_decode((string) $response->getContent(), true));
    }

    public function test_it_answers_the_flat_error_on_an_old_path(): void
    {
        $response = new ApiProblem(404, 'run_not_found', 'Run not found', 'No such run.')->toResponse('/api/runs/x');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('problem+json', (string) $response->headers->get('Content-Type'));
        self::assertSame('{"error":"run_not_found"}', $response->getContent());
    }

    public function test_the_refused_factory_derives_the_title_from_the_code(): void
    {
        $problem = ApiProblemException::refused(404, 'project_not_found', 'No such project.', ['slug' => 'x'])->problem;

        self::assertSame('Project not found', $problem->title);
        self::assertSame('No such project.', $problem->detail);
        self::assertSame(['slug' => 'x'], $problem->extras);
        self::assertSame(404, $problem->status);
    }
}
