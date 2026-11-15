<?php

declare(strict_types=1);

namespace App\Module\Billing\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Billing\Command\ShowSubscribeCommand;
use App\Module\Billing\Command\ShowSubscribeHandler;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The subscription status of the checkout return page, which the open page reads again when the webhook lands. */
#[Route(
    '/billing/checkout/success/frame',
    name: 'app_billing_checkout_success_frame',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowCheckoutConfirmationFrameController extends AppController
{
    public function __construct(
        private readonly ShowSubscribeHandler $showSubscribe,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        return $this->render('@Billing/_checkout_confirmation_frame.html.twig', [
            'view' => ($this->showSubscribe)(new ShowSubscribeCommand($user)),
        ]);
    }
}
