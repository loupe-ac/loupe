<?php

declare(strict_types=1);

namespace App\Api\EventListener;

use App\Api\Problem\ApiProblemException;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Review\Mcp\DocumentHighlightTool;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Answers `route_not_found` on a public route of a switched-off feature.
 *
 * It runs after the firewall, so a caller without a credential still gets 401. It runs before
 * the budget and the idempotency claim, so a refused request spends neither. A route joins the
 * map by its name, which a test checks for every inbox controller.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
final readonly class RefuseFlaggedApiWhenSwitchedOff
{
    /** Route name prefix to the flag that gates every route under it. */
    public const array FLAG_BY_PREFIX = [
        'api_artifact_' => ArtifactInstallFlags::FLAG_ENABLED,
        'api_inbox_' => InboxInstallFlags::FLAG_INBOX_ENABLED,
    ];

    /** Route name to the flag that gates it, for a route outside a prefix. */
    public const array FLAG_BY_ROUTE = [
        'api_project_inbox_ask_check' => InboxInstallFlags::FLAG_INBOX_ENABLED,
        'api_document_highlights' => DocumentHighlightTool::FLAG,
    ];

    public function __construct(
        private FeatureFlagService $featureFlags,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');
        if (!$event->isMainRequest() || !\is_string($route)) {
            return;
        }

        $flag = self::flagOf($route);
        if (null !== $flag && !$this->featureFlags->isEnabled($flag)) {
            throw ApiProblemException::routeNotFound();
        }
    }

    /** The flag that gates a route, or null when the route is not gated. */
    public static function flagOf(string $route): ?string
    {
        if (isset(self::FLAG_BY_ROUTE[$route])) {
            return self::FLAG_BY_ROUTE[$route];
        }
        foreach (self::FLAG_BY_PREFIX as $prefix => $flag) {
            if (str_starts_with($route, $prefix)) {
                return $flag;
            }
        }

        return null;
    }
}
