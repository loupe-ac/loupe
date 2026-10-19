<?php

declare(strict_types=1);

namespace App\Module\Project\Controller\Wizard;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\ShowWizardMachineCommand;
use App\Module\Project\Command\ShowWizardMachineHandler;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The panel of the machine step, which the page reloads when the bridge readiness changes. */
#[Route(
    '/welcome/connect/readiness',
    name: 'app_welcome_readiness',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
class ShowWelcomeReadinessController extends AppController
{
    public function __construct(
        private readonly ShowWizardMachineHandler $showMachine,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $machine = ($this->showMachine)(new ShowWizardMachineCommand($user));

        if ($machine->completed) {
            return $this->redirectToRoute('app_home');
        }

        if (null === $machine->project) {
            return $this->redirectToRoute('app_welcome');
        }

        return $this->render('@Project/wizard/_machine_readiness.html.twig', [
            'wizardRows' => $machine->rows,
            'wizardReady' => $machine->ready,
        ]);
    }
}
