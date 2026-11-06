<?php

declare(strict_types=1);

namespace App\Tests\Api\EventListener;

use App\Api\EventListener\RenderApiProblemOnException;
use App\Api\Problem\ApiProblem;
use App\Api\Problem\ApiProblemException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sentry\ClientBuilder;
use Sentry\Metrics\Types\Metric;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class RenderApiProblemOnExceptionTest extends TestCase
{
    public function test_a_coded_refusal_is_counted_under_its_route(): void
    {
        $this->render('/api/v1/projects', new ApiProblemException(new ApiProblem(404, 'card_not_found', 'Not found', 'No card.')), ['_route' => 'api_show_card']);

        self::assertSame([['404', 'card_not_found', 'api_show_card']], $this->counted());
    }

    #[DataProvider('countedFrameworkRefusals')]
    public function test_a_framework_refusal_is_counted_with_the_code_its_client_reads(string $path, \Throwable $exception, string $status, string $code, string $route): void
    {
        $this->render($path, $exception, 'none' === $route ? [] : ['_route' => $route]);

        self::assertSame([[$status, $code, $route]], $this->counted());
    }

    /** @return iterable<string, array{string, \Throwable, string, string, string}> */
    public static function countedFrameworkRefusals(): iterable
    {
        yield 'rate limit' => ['/api/v1/things', new TooManyRequestsHttpException(30), '429', 'rate_limited', 'api_things'];
        yield 'refused body' => ['/api/v1/things', new HttpException(422, 'invalid', new ValidationFailedException(null, new ConstraintViolationList())), '422', 'validation_failed', 'api_things'];
        yield 'bad json' => ['/api/v1/things', new BadRequestHttpException('bad', new NotEncodableValueException('syntax')), '400', 'invalid_json', 'api_things'];
        yield 'media type' => ['/api/v1/things', new UnsupportedMediaTypeHttpException(), '415', 'unsupported_media_type', 'api_things'];
        yield 'unmatched path' => ['/api/v1/things', new NotFoundHttpException(), '404', 'route_not_found', 'none'];
        yield 'wrong method' => ['/api/v1/things', new MethodNotAllowedHttpException(['GET']), '405', 'method_not_allowed', 'none'];
        yield 'unserved version' => ['/api/v2/things', new NotFoundHttpException(), '404', 'api_version_unsupported', 'none'];
        yield 'other 4xx' => ['/api/v1/things', new HttpException(418), '418', 'http_418', 'api_things'];
    }

    public function test_a_refusal_of_the_security_listener_is_not_counted(): void
    {
        self::assertNull($this->render('/api/v1/things', new AccessDeniedException(), ['_route' => 'api_things']));
        self::assertNull($this->render('/api/v1/things', new AuthenticationException(), ['_route' => 'api_things']));

        self::assertSame([], $this->counted());
    }

    public function test_a_server_fault_is_not_counted(): void
    {
        $this->render('/api/v1/things', new ApiProblemException(new ApiProblem(502, 'github_unavailable', 'Unavailable', 'GitHub failed.')));
        $this->render('/api/v1/things', new \RuntimeException('boom'));

        self::assertSame([], $this->counted());
    }

    public function test_a_path_left_alone_is_not_counted(): void
    {
        $this->render('/projects', new NotFoundHttpException());
        $this->render('/projects', new ApiProblemException(new ApiProblem(404, 'x', 'X', 'Y')));

        self::assertSame([], $this->counted());
    }

    /** @return list<array{string, string, string}> */
    private function counted(): array
    {
        \Sentry\traceMetrics()->flush();
        $rows = [];
        foreach ($this->metrics as $metric) {
            if ('api.refusal' === $metric->getName()) {
                $attributes = $metric->getAttributes()->toSimpleArray();
                $rows[] = [(string) $attributes['status'], (string) $attributes['code'], (string) $attributes['route']];
            }
        }

        return $rows;
    }

    /** @var list<Metric> */
    private array $metrics = [];

    private HubInterface $hub;

    #[\Override]
    protected function setUp(): void
    {
        $this->hub = SentrySdk::getCurrentHub();
        $this->metrics = [];
        $client = ClientBuilder::create([
            'dsn' => 'https://key@o0.ingest.sentry.io/1',
            'default_integrations' => false,
            'before_send_metric' => function (Metric $metric): null {
                $this->metrics[] = $metric;

                return null;
            },
        ])->getClient();
        SentrySdk::setCurrentHub(new Hub($client));
    }

    #[\Override]
    protected function tearDown(): void
    {
        SentrySdk::setCurrentHub($this->hub);
        parent::tearDown();
    }

    public function test_a_thrown_problem_is_rendered_as_problem_json_on_a_v1_path(): void
    {
        $response = $this->render('/api/v1/projects', new ApiProblemException(new ApiProblem(404, 'project_not_found', 'Project not found', 'No such project.', headers: ['X-Extra' => '1'])));

        self::assertSame(404, $response?->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame('1', $response->headers->get('X-Extra'));
        self::assertSame('project_not_found', $this->body($response)['code']);
    }

    public function test_a_thrown_problem_keeps_its_extras_in_the_body(): void
    {
        $response = $this->render('/api/v1/projects', new ApiProblemException(new ApiProblem(403, 'credential_not_allowed', 'Not allowed', 'This credential may not call this route.', extras: ['missing' => ['x']])));

        self::assertSame(403, $response?->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame(['x'], $this->body($response)['missing']);
        self::assertSame('credential_not_allowed', $this->body($response)['code']);
    }

    public function test_an_unmatched_path_outside_a_version_is_route_not_found(): void
    {
        $response = $this->render('/api/anything', new NotFoundHttpException());

        self::assertSame(404, $response?->getStatusCode());
        self::assertSame('route_not_found', $this->body($response)['code']);
    }

    public function test_a_path_outside_the_api_is_left_alone(): void
    {
        self::assertNull($this->render('/projects', new ApiProblemException(new ApiProblem(404, 'x', 'X', 'Y'))));
    }

    public function test_an_unmatched_v1_route_is_route_not_found(): void
    {
        $body = $this->body($this->render('/api/v1/nothing', new NotFoundHttpException()));

        self::assertSame('route_not_found', $body['code']);
    }

    public function test_a_not_found_from_a_matched_route_is_not_a_missing_route(): void
    {
        $response = $this->render('/api/v1/projects', new NotFoundHttpException(), ['_route' => 'api_x']);

        self::assertSame('http_404', $this->body($response)['code']);
    }

    public function test_a_refusal_of_a_matched_route_has_a_detail_that_hides_the_framework_message(): void
    {
        $response = $this->render('/api/v1/projects', new NotFoundHttpException('App\\Module\\Board\\Entity\\Card object not found'), ['_route' => 'api_x']);

        $body = $this->body($response);
        self::assertSame('The server answered this request with status 404 (Not Found).', $body['detail']);
        self::assertStringNotContainsString('Entity', (string) $response?->getContent());
    }

    /** @return iterable<string, array{string, \Throwable, array<string, string>}> */
    public static function everyKindOfRefusal(): iterable
    {
        $matched = ['_route' => 'api_x'];

        yield 'thrown problem' => ['/api/v1/projects', ApiProblemException::invalidCursor('Broken.'), $matched];
        yield 'unknown route' => ['/api/v1/nothing', new NotFoundHttpException(), []];
        yield 'unserved version' => ['/api/v2/projects', new NotFoundHttpException(), []];
        yield 'wrong method' => ['/api/versions', new MethodNotAllowedHttpException(['GET']), []];
        yield 'matched not found' => ['/api/v1/projects', new NotFoundHttpException(), $matched];
        yield 'matched forbidden' => ['/api/v1/projects', new HttpException(403), $matched];
        yield 'unknown status' => ['/api/v1/projects', new HttpException(599), $matched];
        yield 'rate limit' => ['/api/v1/projects', new TooManyRequestsHttpException(5), $matched];
        yield 'media type' => ['/api/v1/projects', new UnsupportedMediaTypeHttpException(), $matched];
        yield 'invalid json' => ['/api/v1/projects', new BadRequestHttpException('bad', new NotEncodableValueException('syntax')), $matched];
        yield 'validation' => ['/api/v1/projects', new HttpException(422, 'invalid', new ValidationFailedException(null, new ConstraintViolationList())), $matched];
        yield 'unexpected failure' => ['/api/v1/projects', new \RuntimeException('boom'), $matched];
    }

    /** @param array<string, string> $attributes */
    #[DataProvider('everyKindOfRefusal')]
    public function test_every_problem_has_all_five_standard_members(string $path, \Throwable $exception, array $attributes): void
    {
        $body = $this->body($this->render($path, $exception, $attributes));

        self::assertIsString($body['type']);
        self::assertNotSame('', $body['title']);
        self::assertIsInt($body['status']);
        self::assertNotSame('', $body['code']);
        self::assertIsString($body['detail']);
        self::assertNotSame('', $body['detail']);
    }

    public function test_an_unserved_version_lists_the_served_ones(): void
    {
        $body = $this->body($this->render('/api/v2/projects', new NotFoundHttpException()));

        self::assertSame('api_version_unsupported', $body['code']);
        self::assertSame(['v1'], $body['versions']);
    }

    public function test_a_wrong_method_is_a_405_with_the_allow_header(): void
    {
        $response = $this->render('/api/versions', new MethodNotAllowedHttpException(['GET']));

        self::assertSame(405, $response?->getStatusCode());
        self::assertSame('GET', $response->headers->get('Allow'));
        self::assertSame('method_not_allowed', $this->body($response)['code']);
    }

    public function test_the_budget_limiter_of_the_framework_becomes_a_rate_limited_problem(): void
    {
        $response = $this->render('/api/v1/bridge/card-holds', new TooManyRequestsHttpException(17), ['_route' => 'x']);

        self::assertSame(429, $response?->getStatusCode());
        self::assertSame('17', $response->headers->get('Retry-After'));
        self::assertSame('rate_limited', $this->body($response)['code']);
    }

    public function test_a_body_that_is_not_json_is_a_415_or_a_400(): void
    {
        $unsupported = $this->render('/api/v1/projects', new UnsupportedMediaTypeHttpException('x'), ['_route' => 'x']);
        self::assertSame('unsupported_media_type', $this->body($unsupported)['code']);

        $invalid = $this->render('/api/v1/projects', new BadRequestHttpException('bad', new NotEncodableValueException('syntax')), ['_route' => 'x']);
        self::assertSame(400, $invalid?->getStatusCode());
        self::assertSame('invalid_json', $this->body($invalid)['code']);
    }

    public function test_a_validation_failure_lists_each_field(): void
    {
        $violations = new ConstraintViolationList([
            new ConstraintViolation('This value should not be blank.', null, [], null, 'title', '', constraint: new NotBlank()),
            new ConstraintViolation('Too long.', null, [], null, 'dueDate', 'x', constraint: new Length(max: 1)),
        ]);
        $exception = new HttpException(422, 'invalid', new ValidationFailedException(null, $violations));

        $body = $this->body($this->render('/api/v1/projects', $exception, ['_route' => 'x']));

        self::assertSame('validation_failed', $body['code']);
        self::assertSame([
            ['field' => 'title', 'code' => 'title_blank', 'message' => 'This value should not be blank.'],
            ['field' => 'dueDate', 'code' => 'due_date_length', 'message' => 'Too long.'],
        ], $body['errors']);
    }

    public function test_an_unexpected_failure_is_a_generic_500_that_leaks_nothing(): void
    {
        $response = $this->render('/api/v1/projects', new \RuntimeException('SQLSTATE secret'), ['_route' => 'x']);

        self::assertSame(500, $response?->getStatusCode());
        self::assertStringNotContainsString('secret', (string) $response->getContent());
    }

    public function test_a_security_refusal_is_left_to_the_firewall(): void
    {
        self::assertNull($this->render('/api/v1/projects', new AccessDeniedException(), ['_route' => 'x']));
    }

    /** @param array<string, mixed> $attributes */
    private function render(string $path, \Throwable $exception, array $attributes = []): ?Response
    {
        $request = Request::create('https://loupe.test'.$path);
        $request->attributes->add($attributes);
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        new RenderApiProblemOnException(new NullLogger())($event);

        return $event->getResponse();
    }

    /** @return array<string, mixed> */
    private function body(?Response $response): array
    {
        self::assertNotNull($response);
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }
}
