<?php

declare(strict_types=1);

namespace App\Module\Landing\Controller\Dev;

use App\Controller\AppController;
use App\Module\Landing\Command\PreviewWaitlistJoinedCommand;
use App\Module\Landing\Command\PreviewWaitlistJoinedHandler;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only home page with one waitlist frame already swapped to its joined
 * state, for a signed preview link. It signs the reader out to show it.
 */
#[Route(
    '/dev/landing-preview/waitlist-{frame}',
    name: 'dev_landing_preview_waitlist_joined',
    requirements: ['frame' => 'notify|modal'],
    methods: ['GET'],
)]
#[When('dev')]
final class PreviewWaitlistJoinedController extends AppController
{
    public function __construct(
        private readonly PreviewWaitlistJoinedHandler $previewWaitlistJoined,
    ) {
    }

    /** @param 'notify'|'modal' $frame */
    public function __invoke(string $frame): Response
    {
        $view = ($this->previewWaitlistJoined)(new PreviewWaitlistJoinedCommand($frame));

        return $this->render('@Landing/preview_waitlist_joined.html.twig', [
            'notifyForm' => $view->notifyForm,
            'modalForm' => $view->modalForm,
            'waitlistJoined' => $view->frame,
        ]);
    }
}
