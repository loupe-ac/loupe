<?php

declare(strict_types=1);

namespace App\Module\Landing\EventListener;

use App\Module\Landing\Service\PublicPages;
use App\Site\SiteHost;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/** On a split instance the legal pages have one copy, on the site host. */
#[AsEventListener(priority: 16)]
final readonly class RedirectLegalPagesToSiteHost
{
    public function __construct(
        private SiteHost $siteHost,
        private PublicPages $publicPages,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->siteHost->isSplit() || $this->siteHost->owns($request->getHost())) {
            return;
        }

        $route = $request->attributes->get('_route');
        if (!\is_string($route) || !$this->publicPages->isLegalRoute($route)) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->publicPages->absoluteUrl($request->getPathInfo()),
            Response::HTTP_MOVED_PERMANENTLY,
        ));
    }
}
