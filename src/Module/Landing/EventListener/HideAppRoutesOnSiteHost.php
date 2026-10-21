<?php

declare(strict_types=1);

namespace App\Module\Landing\EventListener;

use App\Site\SiteHost;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The router fails a wrong-method request before the site guard sees a route.
 * A 405 would name a route that the site host does not serve.
 */
#[AsEventListener]
final readonly class HideAppRoutesOnSiteHost
{
    public function __construct(
        private SiteHost $siteHost,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (
            !$event->isMainRequest()
            || !$event->getThrowable() instanceof MethodNotAllowedHttpException
            || !$this->siteHost->isSiteRequest($event->getRequest()->getHost())
        ) {
            return;
        }

        $event->setThrowable(new NotFoundHttpException('Only the site pages are served on the site host.', $event->getThrowable()));
    }
}
