<?php

declare(strict_types=1);

namespace App\Tests\Module\OpenRouter\Service;

use App\Module\OpenRouter\Service\OpenRouterAnalytics;
use App\Module\OpenRouter\Service\OpenRouterCostOutcome;
use App\Module\OpenRouter\Service\OpenRouterCostReading;
use App\Module\OpenRouter\Service\OpenRouterProbeResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenRouterAnalyticsTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: array<int|string, mixed>, body: string}> */
    private array $requests = [];

    public function test_a_management_key_that_gets_an_answer_is_ok(): void
    {
        $result = $this->analytics(new MockResponse('{"data":[]}', ['http_code' => 200]))->probe('sk-or-v1-secret');

        self::assertSame(OpenRouterProbeResult::Ok, $result);
    }

    public function test_the_probe_posts_the_analytics_query_with_the_bearer_key_and_a_one_hour_range(): void
    {
        $this->analytics(new MockResponse('{}', ['http_code' => 200]))->probe('sk-or-v1-secret');

        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://openrouter.ai/api/v1/analytics/query', $request['url']);
        self::assertContains('Authorization: Bearer sk-or-v1-secret', $request['headers']);
        $body = json_decode($request['body'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['total_usage', 'request_count'], $body['metrics']);
        self::assertSame('2026-10-30T11:00:00+00:00', $body['time_range']['start']);
        self::assertSame('2026-10-30T12:00:00+00:00', $body['time_range']['end']);
        self::assertArrayNotHasKey('filters', $body);
    }

    public function test_a_403_means_the_key_is_not_a_management_key(): void
    {
        self::assertSame(OpenRouterProbeResult::NotManagementKey, $this->analytics(new MockResponse('{}', ['http_code' => 403]))->probe('k'));
    }

    public function test_a_401_means_openrouter_refused_the_key(): void
    {
        self::assertSame(OpenRouterProbeResult::Refused, $this->analytics(new MockResponse('{}', ['http_code' => 401]))->probe('k'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreachableResponses')]
    public function test_a_server_fault_or_a_limit_means_unreachable(int $status): void
    {
        self::assertSame(OpenRouterProbeResult::Unreachable, $this->analytics(new MockResponse('{}', ['http_code' => $status]))->probe('k'));
    }

    /** @return iterable<string, array{int}> */
    public static function unreachableResponses(): iterable
    {
        yield '500' => [500];
        yield '502' => [502];
        yield '503' => [503];
        yield '429' => [429];
        yield '400' => [400];
    }

    public function test_a_transport_error_means_unreachable_and_never_throws(): void
    {
        $result = $this->analytics(new TransportException('timed out'))->probe('k');

        self::assertSame(OpenRouterProbeResult::Unreachable, $result);
    }

    public function test_the_cost_read_filters_by_the_session_and_starts_five_minutes_before_the_run(): void
    {
        $this->analytics(new MockResponse('{"data":[{"total_usage":0.035962,"request_count":4}]}'))
            ->totalCostForSession('sk-or-v1-secret', '0b6f5b5c-6f1a-4e53-9a53-3f5a7f0b8c11', new \DateTimeImmutable('2026-10-30 11:30:00 UTC'));

        self::assertCount(1, $this->requests);
        self::assertContains('Authorization: Bearer sk-or-v1-secret', $this->requests[0]['headers']);
        $body = json_decode($this->requests[0]['body'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['total_usage', 'request_count'], $body['metrics']);
        self::assertSame([['field' => 'session_id', 'operator' => OpenRouterAnalytics::SESSION_FILTER_OPERATOR, 'value' => '0b6f5b5c-6f1a-4e53-9a53-3f5a7f0b8c11']], $body['filters']);
        self::assertSame('2026-10-30T11:25:00+00:00', $body['time_range']['start']);
        self::assertSame('2026-10-30T12:00:00+00:00', $body['time_range']['end']);
    }

    public function test_a_total_is_read_with_six_decimals(): void
    {
        $reading = $this->read(new MockResponse('{"data":[{"total_usage":0.035962,"request_count":4}]}'));

        self::assertSame(OpenRouterCostOutcome::Total, $reading->outcome);
        self::assertSame('0.035962', $reading->costUsd);
    }

    public function test_a_total_in_an_object_is_read_too(): void
    {
        self::assertSame('1.500000', $this->read(new MockResponse('{"data":{"total_usage":1.5,"request_count":"2"}}'))->costUsd);
    }

    public function test_a_free_request_is_a_total_of_zero(): void
    {
        self::assertSame('0.000000', $this->read(new MockResponse('{"data":[{"total_usage":0,"request_count":3}]}'))->costUsd);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('emptyAnswers')]
    public function test_an_answer_with_no_request_is_no_cost(string $body): void
    {
        self::assertSame(OpenRouterCostOutcome::NoCost, $this->read(new MockResponse($body))->outcome);
    }

    /** @return iterable<string, array{string}> */
    public static function emptyAnswers(): iterable
    {
        yield 'no rows' => ['{"data":[]}'];
        yield 'zero requests' => ['{"data":[{"total_usage":0,"request_count":0}]}'];
        yield 'zero requests with a null total' => ['{"data":[{"total_usage":null,"request_count":0}]}'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unrecognizedAnswers')]
    public function test_an_answer_of_an_unknown_shape_is_no_answer_not_no_cost(string $body): void
    {
        self::assertSame(OpenRouterCostOutcome::NoAnswer, $this->read(new MockResponse($body))->outcome);
    }

    /** @return iterable<string, array{string}> */
    public static function unrecognizedAnswers(): iterable
    {
        yield 'empty object' => ['{}'];
        yield 'empty list' => ['[]'];
        yield 'data is not an array' => ['{"data":"none"}'];
        yield 'row is not an array' => ['{"data":[1]}'];
        yield 'row without total_usage' => ['{"data":[{"request_count":4}]}'];
        yield 'row without request_count' => ['{"data":[{"total_usage":0.5}]}'];
        yield 'empty row' => ['{"data":[{}]}'];
        yield 'null total and null count' => ['{"data":[{"total_usage":null,"request_count":null}]}'];
        yield 'total is not a number' => ['{"data":[{"total_usage":"n/a","request_count":2}]}'];
        yield 'negative total' => ['{"data":[{"total_usage":-1,"request_count":2}]}'];
        yield 'renamed fields' => ['{"data":[{"cost":0.5,"requests":2}]}'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedStatuses')]
    public function test_a_401_or_a_403_means_the_key_is_refused(int $status): void
    {
        self::assertSame(OpenRouterCostOutcome::Refused, $this->read(new MockResponse('{}', ['http_code' => $status]))->outcome);
    }

    /** @return iterable<string, array{int}> */
    public static function refusedStatuses(): iterable
    {
        yield '401' => [401];
        yield '403' => [403];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreachableResponses')]
    public function test_a_limit_or_a_server_fault_means_no_answer(int $status): void
    {
        self::assertSame(OpenRouterCostOutcome::NoAnswer, $this->read(new MockResponse('{}', ['http_code' => $status]))->outcome);
    }

    public function test_a_transport_error_or_a_body_that_is_no_json_means_no_answer(): void
    {
        self::assertSame(OpenRouterCostOutcome::NoAnswer, $this->read(new TransportException('timed out'))->outcome);
        self::assertSame(OpenRouterCostOutcome::NoAnswer, $this->read(new MockResponse('<html>'))->outcome);
    }

    private function read(MockResponse|TransportException $response): OpenRouterCostReading
    {
        return $this->analytics($response)->totalCostForSession('k', 'session', new \DateTimeImmutable('2026-10-30 11:30:00 UTC'));
    }

    private function analytics(MockResponse|TransportException $response): OpenRouterAnalytics
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $options['headers'], 'body' => (string) $options['body']];
            if ($response instanceof TransportException) {
                throw $response;
            }

            return $response;
        }, 'https://openrouter.ai/api/v1/');

        return new OpenRouterAnalytics($client, new MockClock('2026-10-30 12:00:00 UTC'), new NullLogger());
    }
}
