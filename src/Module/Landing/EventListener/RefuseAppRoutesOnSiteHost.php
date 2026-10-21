<?php

declare(strict_types=1);

namespace App\Module\Landing\EventListener;

use App\Module\Landing\Service\PublicPages;
use App\Site\SiteHost;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The site host points at the same application, and the router matches any
 * route on any host. Only the site pages may answer there.
 */
#[AsEventListener(priority: 16)]
final readonly class RefuseAppRoutesOnSiteHost
{
    public function __construct(
        private SiteHost $siteHost,
        private PublicPages $publicPages,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->siteHost->isSiteRequest($request->getHost())) {
            return;
        }

        $route = $request->attributes->get('_route');
        if (!$this->publicPages->isSiteRoute(\is_string($route) ? $route : null)) {
            throw new NotFoundHttpException('Only the site pages are served on the site host.');
        }
    }
}
