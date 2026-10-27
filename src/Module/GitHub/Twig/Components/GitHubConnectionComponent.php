<?php

declare(strict_types=1);

namespace App\Module\GitHub\Twig\Components;

use App\Module\GitHub\Entity\GitHubHook;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Repository\GitHubHookRepository;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\HookSecretKey;
use App\Module\GitHub\Service\OneTimeHookSecret;
use App\Module\Project\Entity\Project;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The GitHub App block and the webhook block of the Connections page. The
 * Project module names it in its template only, so it never imports this module.
 *
 * Props: `project`, passed as an object with `:project`.
 */
#[AsTwigComponent(name: 'GitHubConnection')]
final class GitHubConnectionComponent
{
    public Project $project;

    public bool $visible = false;

    public bool $appConfigured = false;

    public bool $hookAvailable = false;

    public ?GitHubHook $hook = null;

    /** Set on the render right after a create or a rotation, never after. */
    public ?string $newSecret = null;

    /** @var list<GitHubInstallation> */
    public array $installations = [];

    public function __construct(
        private readonly Security $security,
        private readonly GitHubHookRepository $gitHubHooks,
        private readonly GitHubInstallationRepository $gitHubInstallations,
        private readonly GitHubAppConfiguration $appConfiguration,
        private readonly HookSecretKey $hookSecretKey,
        private readonly OneTimeHookSecret $oneTimeHookSecret,
    ) {
    }

    public function mount(Project $project): void
    {
        $this->project = $project;
        $this->visible = $this->security->isGranted(GitHubConnectionVoter::MANAGE, $project);
        if (!$this->visible) {
            return;
        }

        $this->appConfigured = $this->appConfiguration->isConfigured();
        $this->hookAvailable = $this->hookSecretKey->isReadable();
        if ($this->appConfigured) {
            $this->installations = $this->gitHubInstallations->findByProject($project);
        }

        // Loading a hook decrypts its secret, which throws without the key or with a wrong one.
        if ($this->hookAvailable) {
            try {
                $this->hook = $this->gitHubHooks->findOneByProject($project);
            } catch (\RuntimeException) {
                $this->hookAvailable = false;

                return;
            }

            $this->newSecret = $this->oneTimeHookSecret->take($project);
        }
    }
}
