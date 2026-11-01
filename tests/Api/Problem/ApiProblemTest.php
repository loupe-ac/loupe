<?php

declare(strict_types=1);

namespace App\Tests\Api\Problem;

use App\Api\Problem\ApiPaths;
use App\Api\Problem\ApiProblem;
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
        $problem = new ApiProblem(422, 'validation_failed', 'Validation failed', errors: [['field' => 'title', 'code' => 'title_blank', 'message' => 'Blank.']]);

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
}
