<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * How long an open interactive run may show no sign of life before the sweep closes it.
 * Zero switches the sweep off. The exit hook and the bridge liveness check close runs whatever this flag says.
 *
 * A run of `loupe init` holds back the onboarding inbox items, so it must end soon after init stops renewing it.
 * It closes after a fixed short time, also while the flag is zero.
 */
final readonly class InteractiveStaleClose
{
    public const string FLAG = 'bridge.interactive_stale_after_seconds';

    /** Init renews its run every 60 seconds. */
    public const int INIT_STALE_AFTER_SECONDS = 300;

    public function __construct(
        private FeatureFlagService $featureFlags,

        #[Autowire(param: 'app.bridge.default_interactive_stale_after_seconds')]
        private int $default,
    ) {
    }

    public function enabled(): bool
    {
        return $this->seconds() > 0;
    }

    public function quietBefore(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(\sprintf('-%d seconds', $this->seconds()));
    }

    public function initQuietBefore(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(\sprintf('-%d seconds', self::INIT_STALE_AFTER_SECONDS));
    }

    private function seconds(): int
    {
        return $this->featureFlags->getIntValue(self::FLAG, $this->default);
    }
}
