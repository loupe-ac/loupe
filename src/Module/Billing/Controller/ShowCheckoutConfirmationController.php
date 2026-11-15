<?php

declare(strict_types=1);

namespace App\Module\Billing\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Billing\Command\ShowSubscribeCommand;
use App\Module\Billing\Command\ShowSubscribeHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Where Stripe returns the browser after a completed Checkout. It renders a
 * confirmation page rather than redirecting into the app: the subscription
 * webhook may not have landed yet, and a redirect would bounce a user whose
 * trial has expired straight back to the paywall. The page is never treated as
 * proof of payment — only the webhook writes subscription state. The page
 * shows the status in a live region, so it changes when the webhook lands.
 */
#[Route(
    '/billing/checkout/success',
    name: 'app_billing_checkout_success',
    methods: ['GET'],
)]
final class ShowCheckoutConfirmationController extends AppController
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

        return $this->render('@Billing/show_checkout_confirmation.html.twig', [
            'view' => ($this->showSubscribe)(new ShowSubscribeCommand($user)),
        ]);
    }
}
