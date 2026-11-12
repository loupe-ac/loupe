<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Exception\DomainErrors;
use App\Module\GitHub\Service\GitHubInstallSession;
use App\Module\GitHub\Service\GitHubPickerListing;
use App\Module\GitHub\Service\GitHubUserApiFailed;
use App\Module\GitHub\View\GitHubRepositoryPickerView;
use Psr\Log\LoggerInterface;

final readonly class ShowGitHubRepositoryPickerHandler
{
    public function __construct(
        private GitHubInstallSession $installSession,
        private GitHubPickerListing $listing,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return ?GitHubRepositoryPickerView null when the owner has no live sign-in for this project
     *
     * @throws DomainErrors with the sign-in removed, when GitHub fails
     */
    public function __invoke(ShowGitHubRepositoryPickerCommand $command): ?GitHubRepositoryPickerView
    {
        $projectId = (string) $command->project->id;
        $token = $this->installSession->token($projectId);
        if (null === $token) {
            return null;
        }

        try {
            return $this->listing->list($command->project, $token, $this->installSession->pending()?->repository);
        } catch (GitHubUserApiFailed $e) {
            $this->logger->warning('github.user_api_failed', [
                'projectId' => $projectId,
                'reason' => $e->reason,
                'status' => $e->status,
            ]);
            $this->installSession->clear();

            throw new DomainErrors(['github' => 'github.app.flash.github_unavailable']);
        }
    }
}
