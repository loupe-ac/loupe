<?php

declare(strict_types=1);

namespace App\Module\Artifact\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Runs after the nelmio referrer listener, which sets its policy with no check
 * for one already there. Without this the signed link would leave in a Referer.
 */
#[AsEventListener(priority: -100)]
final readonly class KeepNoReferrerOnArtifactContent
{
    public function __invoke(ResponseEvent $event): void
    {
        if ('app_artifact_content' === $event->getRequest()->attributes->get('_route')) {
            $event->getResponse()->headers->set('Referrer-Policy', 'no-referrer');
        }
    }
}
