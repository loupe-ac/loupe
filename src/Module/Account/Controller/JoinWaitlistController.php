<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Command\JoinWaitlistCommand;
use App\Module\Account\Command\JoinWaitlistHandler;
use App\Module\Account\Form\WaitlistJoinFormType;
use App\Module\Account\Form\WaitlistJoinRequest;
use App\Module\Account\Service\RegistrationGate;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

#[Route(
    '/waitlist',
    name: 'app_waitlist_join',
    methods: ['GET', 'POST'],
)]
#[Route(
    '/waitlist/notify',
    name: 'app_waitlist_notify',
    defaults: ['notify' => true],
    methods: ['GET', 'POST'],
)]
#[Route(
    '/waitlist/modal',
    name: 'app_waitlist_modal',
    defaults: ['modal' => true],
    methods: ['GET', 'POST'],
)]
final class JoinWaitlistController extends AppController
{
    public function __construct(
        private readonly RegistrationGate $gate,
        private readonly JoinWaitlistHandler $joinWaitlist,
        private readonly TranslatorInterface $translator,
        private readonly Auditor $auditor,
        private readonly FormFactoryInterface $formFactory,

        #[Autowire(service: 'limiter.waitlist_join')]
        private readonly RateLimiterFactory $waitlistJoinLimiter,
    ) {
    }

    public function __invoke(Request $request, bool $notify = false, bool $modal = false): Response
    {
        // A waitlist only means something on an instance that intends to let
        // people in eventually. With sign-up switched off — or the install
        // wizard still pending — /register 404s, so redirecting there would
        // dead-end and collecting addresses would promise nothing.
        if (!$this->gate->allowsNewAccounts()) {
            // Only a submission is recorded, for the reason RegisterController
            // gives: a GET is a page view, and a crawler would write a row per
            // request.
            if ($request->isMethod('POST')) {
                $this->auditor->record('account.waitlist_denied', AuditOutcome::Refused);
            }

            throw $this->createNotFoundException();
        }

        // Reached after a join, or via the OAuth-at-cap redirect: the resolver
        // already added the provider email, so show the confirmation directly.
        // It comes before the open-gate redirect, which would hide it.
        if ($request->query->getBoolean('joined')) {
            return $this->render('@Account/registration/waitlist_joined.html.twig', ['modal' => $modal]);
        }

        // The home page forms always join the waitlist, even while sign-up has
        // free slots: the private beta lets people in from the list.
        if (!$notify && !$modal && $this->gate->isOpen()) {
            return $this->redirectToRoute('app_register');
        }

        // The home page Notify me form and waitlist dialog post here too. Each
        // keeps its own route, so a failed submit re-renders its own frame.
        $form = match (true) {
            $modal => $this->formFactory->createNamed(WaitlistJoinFormType::MODAL_NAME, WaitlistJoinFormType::class, new WaitlistJoinRequest(), [
                'action' => $this->generateUrl('app_waitlist_modal'),
            ]),
            $notify => $this->createForm(WaitlistJoinFormType::class, new WaitlistJoinRequest(), [
                'action' => $this->generateUrl('app_waitlist_notify'),
            ]),
            default => $this->createForm(WaitlistJoinFormType::class, new WaitlistJoinRequest()),
        };
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $limiter = $this->waitlistJoinLimiter->create($request->getClientIp() ?? 'unknown');
            if (!$limiter->consume(1)->isAccepted()) {
                $form->get('email')->addError(new FormError($this->translator->trans('account.waitlist.error.throttled')));

                return $this->renderFormResponse('@Account/registration/waitlist.html.twig', $form, ['notify' => $notify, 'modal' => $modal]);
            }

            $data = $form->getData();
            assert($data instanceof WaitlistJoinRequest);

            ($this->joinWaitlist)(new JoinWaitlistCommand(
                email: $data->email ?: throw new \LogicException('Email is required after form validation.'),
                picks: array_values($data->picks),
                other: $data->other,
            ));

            // Post/Redirect/Get: Turbo Drive throws "Form responses must redirect
            // to another location" for a direct 200 on a non-frame submission.
            // Always the same response, joined or already-listed, so the endpoint
            // enumerates nothing.
            return $this->redirectToRoute($modal ? 'app_waitlist_modal' : 'app_waitlist_join', ['joined' => 1]);
        }

        return $this->renderFormResponse('@Account/registration/waitlist.html.twig', $form, ['notify' => $notify, 'modal' => $modal]);
    }
}
