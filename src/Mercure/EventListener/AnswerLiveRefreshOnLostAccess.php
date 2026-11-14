<?php

declare(strict_types=1);

namespace App\Mercure\EventListener;

use App\Module\Account\EventListener\RequireNotSuspendedListener;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Gives a background refresh of a live region a plain answer when the viewer
 * lost access, so the page can say so. Without it the fetch follows a redirect
 * to the login page and reads a 200, or reads a 404 it takes for a failure.
 * It acts only on a request that sends X-Loupe-Live-Refresh: 1.
 */
final readonly class AnswerLiveRefreshOnLostAccess
{
    public const string LOST_HEADER = 'X-Loupe-Live-Lost';

    public const string SIGNED_OUT = 'signed-out';

    public const string PROJECT_DELETED = 'project-deleted';

    public function __construct(
        private ProjectRepository $projects,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /** Priority 7: after the firewall (8), which already answered a signed-out request. */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::isLiveRefresh($request)) {
            return;
        }

        $projectId = $this->projectId($request);
        if (null === $projectId || null !== $this->projects->find($projectId)) {
            return;
        }

        $event->setResponse(self::lost(Response::HTTP_GONE, self::PROJECT_DELETED));
    }

    /**
     * The firewall redirects a signed-out request to the login page, and
     * RequireNotSuspendedListener sends a suspended user to the suspended page.
     * Both mean the session ended for the page.
     */
    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        if (!$event->isMainRequest() || !self::isLiveRefresh($event->getRequest()) || !$response instanceof RedirectResponse) {
            return;
        }

        $target = parse_url($response->getTargetUrl(), \PHP_URL_PATH);
        if (!\is_string($target)) {
            return;
        }

        foreach (['app_login', RequireNotSuspendedListener::SUSPENDED_ROUTE] as $route) {
            if ($target === $this->urlGenerator->generate($route)) {
                $event->setResponse(self::lost(Response::HTTP_UNAUTHORIZED, self::SIGNED_OUT));

                return;
            }
        }
    }

    public static function isLiveRefresh(Request $request): bool
    {
        return '1' === $request->headers->get(SetMercureCookieOnResponse::LIVE_REFRESH_HEADER);
    }

    private static function lost(int $status, string $reason): Response
    {
        return new Response('', $status, [self::LOST_HEADER => $reason]);
    }

    private function projectId(Request $request): ?Uuid
    {
        foreach (['projectId', 'project'] as $attribute) {
            $value = $request->attributes->get($attribute);
            if (\is_string($value) && Uuid::isValid($value)) {
                return Uuid::fromString($value);
            }
        }

        return null;
    }
}
