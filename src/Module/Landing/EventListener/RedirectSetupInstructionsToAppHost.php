<?php

declare(strict_types=1);

namespace App\Module\Landing\EventListener;

use App\Routing\PinnedUrlGenerator;
use App\Site\SiteHost;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/** People guess /setup.md on the site host, but its commands must name the app host. */
#[AsEventListener(priority: 17)]
final readonly class RedirectSetupInstructionsToAppHost
{
    public function __construct(
        private SiteHost $siteHost,
        private PinnedUrlGenerator $appUrls,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->siteHost->isSiteRequest($request->getHost())) {
            return;
        }
        if ('app_setup_instructions' !== $request->attributes->get('_route')) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->appUrls->generate('app_setup_instructions', $request->query->all()),
            Response::HTTP_MOVED_PERMANENTLY,
        ));
    }
}
