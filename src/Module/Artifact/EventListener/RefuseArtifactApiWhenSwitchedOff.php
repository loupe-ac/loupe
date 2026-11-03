<?php

declare(strict_types=1);

namespace App\Module\Artifact\EventListener;

use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Artifact\Service\ArtifactApiProblems;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Answers `route_not_found` on every public artifact route while the feature is off.
 *
 * It runs after the firewall, so a caller without a credential still gets 401. It runs
 * before the idempotency claim, so a refused POST spends no key.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final readonly class RefuseArtifactApiWhenSwitchedOff
{
    /** The name prefix of every public artifact route. */
    public const string ROUTE_PREFIX = 'api_artifact_';

    public function __construct(
        private FeatureFlagService $featureFlags,
        private ArtifactApiProblems $problems,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');
        if (!$event->isMainRequest() || !\is_string($route) || !str_starts_with($route, self::ROUTE_PREFIX)) {
            return;
        }

        if (!$this->featureFlags->isEnabled(ArtifactInstallFlags::FLAG_ENABLED)) {
            throw $this->problems->routeNotFound();
        }
    }
}
