<?php

declare(strict_types=1);

namespace App\Module\Project\Controller\Wizard;

use App\Controller\AppController;
use App\Module\Account\Command\CompleteWizardCommand;
use App\Module\Account\Command\CompleteWizardHandler;
use App\Module\Account\Entity\User;
use App\Module\Project\Service\WizardState;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('wizard-continue')]
#[Route(
    '/welcome/continue',
    name: 'app_welcome_continue',
    methods: ['POST'],
)]
class ContinueWizardController extends AppController
{
    public function __construct(
        private readonly WizardState $wizardState,
        private readonly CompleteWizardHandler $completeWizardHandler,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        if ($this->wizardState->isCompleted($user)) {
            return $this->redirectToRoute('app_home');
        }

        $project = $this->wizardState->firstProject($user);
        if (null === $project) {
            return $this->redirectToRoute('app_welcome');
        }

        ($this->completeWizardHandler)(new CompleteWizardCommand($user));

        return $this->redirectToRoute('app_project_workshop', ['id' => (string) $project->id]);
    }
}
