<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Service;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The calls Loupe makes to the OpenRouter analytics API. Every failure comes
 * back as a result value, so a slow or absent OpenRouter never breaks a page.
 */
final readonly class OpenRouterAnalytics
{
    /**
     * The operator of the session filter. It is not read from the analytics
     * meta endpoint yet, so a real run must confirm it.
     */
    public const string SESSION_FILTER_OPERATOR = 'eq';

    private const int RANGE_MARGIN_MINUTES = 5;

    public function __construct(
        private HttpClientInterface $openrouterClient,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Runs the smallest analytics query. OpenRouter answers 403 to a key that is
     * not a management key, so only this endpoint tells the two kinds apart.
     */
    public function probe(string $key): OpenRouterProbeResult
    {
        $now = $this->clock->now();

        try {
            $status = $this->openrouterClient->request('POST', 'analytics/query', [
                'auth_bearer' => $key,
                'json' => [
                    'metrics' => ['total_usage', 'request_count'],
                    'time_range' => [
                        'start' => $now->modify('-1 hour')->format(\DATE_ATOM),
                        'end' => $now->format(\DATE_ATOM),
                    ],
                ],
            ])->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            $this->logger->info('openrouter.probe_unreachable', ['reason' => $e->getMessage()]);

            return OpenRouterProbeResult::Unreachable;
        }

        $result = match (true) {
            $status >= 200 && $status < 300 => OpenRouterProbeResult::Ok,
            401 === $status => OpenRouterProbeResult::Refused,
            403 === $status => OpenRouterProbeResult::NotManagementKey,
            default => OpenRouterProbeResult::Unreachable,
        };
        if (OpenRouterProbeResult::Unreachable === $result) {
            $this->logger->warning('openrouter.probe_unexpected_status', ['status' => $status]);
        }

        return $result;
    }

    /**
     * The total cost that OpenRouter billed for one session since the run
     * started. The session is the run key. A 401 or a 403 means OpenRouter does
     * not accept the key. Every other fault reads as no answer.
     */
    public function totalCostForSession(string $key, string $sessionId, \DateTimeImmutable $runStart): OpenRouterCostReading
    {
        try {
            $response = $this->openrouterClient->request('POST', 'analytics/query', [
                'auth_bearer' => $key,
                'json' => [
                    'metrics' => ['total_usage', 'request_count'],
                    'filters' => [['field' => 'session_id', 'operator' => self::SESSION_FILTER_OPERATOR, 'value' => $sessionId]],
                    'time_range' => [
                        'start' => $runStart->modify(\sprintf('-%d minutes', self::RANGE_MARGIN_MINUTES))->format(\DATE_ATOM),
                        'end' => $this->clock->now()->format(\DATE_ATOM),
                    ],
                ],
            ]);
            $status = $response->getStatusCode();
            if (401 === $status || 403 === $status) {
                return OpenRouterCostReading::refused();
            }
            if ($status < 200 || $status >= 300) {
                $this->logger->warning('openrouter.cost_read_unexpected_status', ['status' => $status]);

                return OpenRouterCostReading::noAnswer();
            }

            $body = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            $this->logger->info('openrouter.cost_read_unreachable', ['reason' => $e->getMessage()]);

            return OpenRouterCostReading::noAnswer();
        }

        return self::readTotal($body);
    }

    /** @param array<mixed> $body */
    private static function readTotal(array $body): OpenRouterCostReading
    {
        $data = $body['data'] ?? $body;
        $row = \is_array($data) && array_is_list($data) ? ($data[0] ?? null) : $data;
        if (!\is_array($row)) {
            return OpenRouterCostReading::noCost();
        }

        $total = $row['total_usage'] ?? null;
        $count = $row['request_count'] ?? null;
        if (!is_numeric($total) || (is_numeric($count) ? (float) $count <= 0 : (float) $total <= 0)) {
            return OpenRouterCostReading::noCost();
        }

        $cost = number_format((float) $total, 6, '.', '');

        return OpenRouterCostReading::total($cost);
    }
}
