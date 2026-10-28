<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Whether the sweep closes an open interactive run that shows no sign of life, and after how long.
 * The exit hook and the bridge liveness check close runs whatever this flag says.
 */
final readonly class InteractiveStaleClose
{
    public const string FLAG = 'bridge.interactive_stale_close_enabled';

    public function __construct(
        private FeatureFlagService $featureFlags,

        #[Autowire(param: 'app.bridge.default_interactive_stale_close_enabled')]
        private bool $defaultEnabled,

        #[Autowire(param: 'app.bridge.interactive_stale_after')]
        private int $staleAfterSeconds,
    ) {
    }

    public function enabled(): bool
    {
        return $this->featureFlags->isEnabled(self::FLAG, $this->defaultEnabled);
    }

    public function quietBefore(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(\sprintf('-%d seconds', $this->staleAfterSeconds));
    }
}
