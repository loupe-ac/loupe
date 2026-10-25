<?php

declare(strict_types=1);

namespace App\Module\Notification\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Notification\Command\ChangeNotificationPreferencesCommand;
use App\Module\Notification\Command\ChangeNotificationPreferencesHandler;
use App\Module\Notification\Form\ChangeNotificationPreferencesFormType;
use App\Module\Notification\Form\ChangeNotificationPreferencesRequest;
use App\Module\Notification\Service\PushAvailability;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The handler writes the rows of the signed-in user alone, so no voter is needed. */
#[Route(
    '/account/notifications/preferences',
    name: 'app_account_notifications_preferences',
    methods: ['POST'],
)]
final class ChangeNotificationPreferencesController extends AppController
{
    public function __construct(
        private readonly ChangeNotificationPreferencesHandler $changePreferences,
        private readonly PushAvailability $availability,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $form = $this->createForm(ChangeNotificationPreferencesFormType::class, new ChangeNotificationPreferencesRequest());
        $form->handleRequest($request);

        if (!$this->availability->isAvailable()) {
            $this->addFlash('error', $this->translator->trans('notification.preferences.flash.unavailable'));
        } elseif ($form->isSubmitted() && $form->isValid()) {
            ($this->changePreferences)(new ChangeNotificationPreferencesCommand($user, $form->getData()->toChoices()));
            $this->addFlash('success', $this->translator->trans('notification.preferences.flash.saved'));
        } else {
            $this->addFlash('error', $this->translator->trans('notification.preferences.flash.invalid'));
        }

        return $this->redirectToRoute('app_account_notifications', ['_fragment' => 'notification-preferences']);
    }
}
