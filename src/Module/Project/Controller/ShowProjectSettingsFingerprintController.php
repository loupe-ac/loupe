<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Service\ProjectSettingsFingerprint;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The current fingerprint of the project settings, which an open edit form compares after a reconnect. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/settings/fingerprint',
    name: 'app_project_settings_fingerprint',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
class ShowProjectSettingsFingerprintController extends AppController
{
    public function __construct(
        private readonly ProjectSettingsFingerprint $fingerprint,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        return new Response($this->fingerprint->of($project), Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
