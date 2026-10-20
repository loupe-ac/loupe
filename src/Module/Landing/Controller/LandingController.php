<?php

declare(strict_types=1);

namespace App\Module\Landing\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Account\Form\WaitlistJoinFormType;
use App\Module\Account\Form\WaitlistJoinRequest;
use App\Module\Project\Command\ShowHomeCommand;
use App\Module\Project\Command\ShowHomeHandler;
use App\Routing\PinnedUrlGenerator;
use App\Site\SignedInCookie;
use App\Site\SiteHost;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/', name: 'app_home')]
class LandingController extends AppController
{
    public function __construct(
        private readonly ShowHomeHandler $showHome,
        private readonly SiteHost $siteHost,
        private readonly SignedInCookie $signedInCookie,
        private readonly PinnedUrlGenerator $appUrls,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            if (!$this->siteHost->servesSiteOn($request->getHost())) {
                return $this->redirectToRoute('app_login');
            }

            // The site host reads no session. The cookie says the app knows this browser.
            if (null !== $this->signedInCookie->signal($request)) {
                $redirect = $this->redirect($this->appUrls->generate('app_home'));
                $redirect->setPrivate();

                return $redirect;
            }

            return $this->render('@Landing/landing.html.twig', [
                'notifyForm' => $this->createForm(WaitlistJoinFormType::class, new WaitlistJoinRequest(), [
                    'action' => $this->generateUrl('app_waitlist_notify'),
                ])->createView(),
                'modalForm' => $this->formFactory->createNamed(WaitlistJoinFormType::MODAL_NAME, WaitlistJoinFormType::class, new WaitlistJoinRequest(), [
                    'action' => $this->generateUrl('app_waitlist_modal'),
                ])->createView(),
            ]);
        }

        $projects = ($this->showHome)(new ShowHomeCommand($user))->projects;

        if ([] === $projects && null === $user->wizardCompletedAt) {
            return $this->redirectToRoute('app_welcome');
        }

        if (1 === \count($projects)) {
            return $this->redirectToRoute('app_project_documents', ['id' => (string) $projects[0]->id]);
        }

        return $this->redirectToRoute('app_projects');
    }
}
