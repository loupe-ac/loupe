<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Service;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The calls Loupe makes to the OpenRouter analytics API. Every failure comes
 * back as a result value, so a slow or absent OpenRouter never breaks a page.
 */
final readonly class OpenRouterAnalytics
{
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
}
