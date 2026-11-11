<?php

declare(strict_types=1);

namespace App\Observability;

use App\Module\Workflow\Messenger\EvaluateCard;
use Sentry\Tracing\SamplingContext;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * With no usable DSN the SDK would still build and profile every transaction, then
 * drop it at the transport. A zero rate stops that work at the start.
 */
final readonly class TracesSampler
{
    /** Routes whose traces carry no performance signal: an uptime probe and a per-minute ping. */
    public const array UNTRACED_ROUTES = ['ubermuda_health_check', 'api_bridge_heartbeat'];

    /** A card evaluation runs each time the facts of a card change, too often for the configured rate. */
    public const string EVALUATE_CARD_RATE_FLAG = 'observability.evaluate_card_trace_rate_permille';
    public const int EVALUATE_CARD_RATE_DEFAULT = 10;

    public function __construct(
        #[Autowire('%env(default::SENTRY_DSN)%')]
        private ?string $dsn,

        #[Autowire('%env(float:SENTRY_TRACES_SAMPLE_RATE)%')]
        private float $sampleRate,
        private FeatureFlagService $featureFlags,
    ) {
    }

    public function __invoke(SamplingContext $context): float
    {
        if (SentryDsnStatus::Valid !== SentryDsnStatus::of($this->dsn)) {
            return 0.0;
        }

        if (\in_array($context->getTransactionContext()?->getData()['route'] ?? null, self::UNTRACED_ROUTES, true)) {
            return 0.0;
        }

        return match ($context->getParentSampled()) {
            true => 1.0,
            false => 0.0,
            null => EvaluateCard::class === $context->getTransactionContext()?->getName()
                ? $this->evaluateCardRate()
                : $this->sampleRate,
        };
    }

    private function evaluateCardRate(): float
    {
        try {
            $permille = $this->featureFlags->getIntValue(self::EVALUATE_CARD_RATE_FLAG, self::EVALUATE_CARD_RATE_DEFAULT);
        } catch (\Throwable) {
            // The sampler must never fail a message, and the table can be missing or unreachable.
            $permille = self::EVALUATE_CARD_RATE_DEFAULT;
        }

        return min(1000, max(0, $permille)) / 1000;
    }
}
