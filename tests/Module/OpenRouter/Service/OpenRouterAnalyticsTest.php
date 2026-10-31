<?php

declare(strict_types=1);

namespace App\Tests\Module\OpenRouter\Service;

use App\Module\OpenRouter\Service\OpenRouterAnalytics;
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
