<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * How long an open interactive run may show no sign of life before the sweep closes it.
 * Zero switches the sweep off. The exit hook and the bridge liveness check close runs whatever this flag says.
 */
final readonly class InteractiveStaleClose
{
    public const string FLAG = 'bridge.interactive_stale_after_seconds';

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

    private function seconds(): int
    {
        return $this->featureFlags->getIntValue(self::FLAG, $this->default);
    }
}
