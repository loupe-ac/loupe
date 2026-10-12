<?php

declare(strict_types=1);

namespace App\Module\Landing\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Account\Form\WaitlistJoinFormType;
use App\Module\Account\Form\WaitlistJoinRequest;
use App\Module\Landing\Service\HomePageGate;
use App\Module\Project\Command\ShowHomeCommand;
use App\Module\Project\Command\ShowHomeHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/', name: 'app_home')]
class LandingController extends AppController
{
    public function __construct(
        private readonly ShowHomeHandler $showHome,
        private readonly HomePageGate $homePageGate,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            if (!$this->homePageGate->shows()) {
                return $this->redirectToRoute('app_login');
            }

            return $this->render('@Landing/landing.html.twig', [
                'notifyForm' => $this->createForm(WaitlistJoinFormType::class, new WaitlistJoinRequest(), [
                    'action' => $this->generateUrl('app_waitlist_join'),
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
