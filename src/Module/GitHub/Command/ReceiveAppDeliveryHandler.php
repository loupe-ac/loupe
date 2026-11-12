<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Service\ForgeRepositories;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\GitHubRepositoryRef;
use App\Module\GitHub\InvalidGitHubDelivery;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\GitHub\Service\DeliveryAnnouncer;
use App\Module\GitHub\Service\RefusedDeliveries;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Every installation of the App signs with one secret, so the installation id
 * in the body names the installation. A delivery reaches a project only through
 * a repository row that names that installation. A delivery with no
 * installation, or for one Loupe does not know, is dropped. Only an
 * `installation` created event makes an unknown installation known.
 */
final readonly class ReceiveAppDeliveryHandler
{
    public function __construct(
        #[Autowire(env: 'GITHUB_APP_WEBHOOK_SECRET')]
        private string $appSecret,
        private GitHubInstallationRepository $gitHubInstallations,
        private ForgeRepositories $forgeRepositories,
        private DeliveryAnnouncer $announcer,
        private RefusedDeliveries $refusals,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReceiveAppDeliveryCommand $command): GitHubDeliveryOutcome
    {
        try {
            $delivery = GitHubDelivery::fromRequest($command->request, $this->appSecret);
        } catch (InvalidGitHubDelivery $e) {
            $this->refusals->record($e);

            return GitHubDeliveryOutcome::Refused;
        }

        $installationId = $delivery->installationId();
        $installation = null === $installationId ? null : $this->gitHubInstallations->findOneByInstallationId($installationId);
        if (null === $installation && null !== $installationId && 'installation' === $delivery->event && 'created' === $delivery->action()) {
            $installation = $this->record($installationId, $delivery);
        }

        if (null === $installation || null !== $installation->removedAt) {
            $this->dropped($delivery, null === $installationId ? 'no_installation' : 'unknown_installation');

            return GitHubDeliveryOutcome::Received;
        }

        match ($delivery->event) {
            'installation' => $this->installationChanged($installation, $delivery),
            'installation_repositories' => $this->repositoriesChanged($installation, $delivery),
            default => $this->owned($installation, $delivery),
        };

        return GitHubDeliveryOutcome::Received;
    }

    /** Creates the record from the signed payload. It claims no repository, because a person picks those. */
    private function record(int $installationId, GitHubDelivery $delivery): ?GitHubInstallation
    {
        $login = $delivery->accountLogin();
        if (null === $login) {
            return null;
        }

        $this->gitHubInstallations->insertIfAbsent($installationId, $login, $delivery->repositorySelection() ?? GitHubRepositorySelection::Selected, $delivery->accountId());

        return $this->gitHubInstallations->findOneByInstallationId($installationId);
    }

    private function installationChanged(GitHubInstallation $installation, GitHubDelivery $delivery): void
    {
        $this->refresh($installation, $delivery);

        switch ($delivery->action()) {
            case 'suspend':
                $installation->suspendedAt = new \DateTimeImmutable();
                break;
            case 'unsuspend':
                $installation->suspendedAt = null;
                break;
            case 'deleted':
                $installation->removedAt = new \DateTimeImmutable();
                $this->forgeRepositories->loseAccess(GitHubDelivery::FORGE, (string) $installation->installationId);
                break;
            case 'created':
                $this->em->flush();
                $this->reconnectAll($installation, $delivery->repositories('repositories'));
                break;
        }

        $this->em->flush();
    }

    private function repositoriesChanged(GitHubInstallation $installation, GitHubDelivery $delivery): void
    {
        $this->refresh($installation, $delivery);
        $this->em->flush();

        if (null === $installation->suspendedAt) {
            $this->reconnectAll($installation, $delivery->repositories('repositories_added'));
        }

        foreach ($delivery->repositories('repositories_removed') as $repository) {
            $this->forgeRepositories->loseAccess(GitHubDelivery::FORGE, (string) $installation->installationId, $repository->externalId());
        }
    }

    private function refresh(GitHubInstallation $installation, GitHubDelivery $delivery): void
    {
        $login = $delivery->accountLogin();
        if (null !== $login) {
            $installation->accountLogin = $login;
        }

        $accountId = $delivery->accountId();
        if (null !== $accountId) {
            $installation->accountId = $accountId;
        }

        $selection = $delivery->repositorySelection();
        if (null !== $selection) {
            $installation->repositorySelection = $selection;
        }
    }

    /**
     * A repository whose row says "No access" reconnects to this installation
     * only when both installations belong to one known GitHub account. A
     * transferred repository, or a reused login, keeps its row as it is.
     *
     * @param list<GitHubRepositoryRef> $repositories
     */
    private function reconnectAll(GitHubInstallation $installation, array $repositories): void
    {
        foreach ($repositories as $repository) {
            $row = $this->forgeRepositories->lostAccessRowOf(GitHubDelivery::FORGE, $repository->externalId());
            if (null === $row) {
                continue;
            }

            $previous = ctype_digit($row->sourceRef ?? '') ? $this->gitHubInstallations->findOneByInstallationId((int) $row->sourceRef) : null;
            if (null === $previous || null === $previous->accountId || $previous->accountId !== $installation->accountId) {
                $this->logger->info('github.reconnect_refused', [
                    'installationId' => $installation->installationId,
                    'repositoryId' => $repository->id,
                ]);
                continue;
            }

            $movedFrom = $this->forgeRepositories->reconnect($row, (string) $installation->installationId, $repository->fullName);
            if (null !== $movedFrom) {
                $this->announcer->moved($row->project, $movedFrom, $repository->fullName);
            }
        }
    }

    /**
     * A repository row of a project that names this installation decides who
     * receives the delivery. Without one, or with a "No access" row, the
     * delivery is dropped, and nothing is claimed.
     */
    private function owned(GitHubInstallation $installation, GitHubDelivery $delivery): void
    {
        $repository = $delivery->repository();
        if (null !== $installation->suspendedAt || null === $repository) {
            $this->dropped($delivery, null === $repository ? 'no_repository' : 'suspended');

            return;
        }

        $row = $this->forgeRepositories->installationOwnerOf(GitHubDelivery::FORGE, $repository->externalId());
        if (null === $row || null !== $row->accessLostAt || $row->sourceRef !== (string) $installation->installationId) {
            $this->dropped($delivery, 'not_picked');

            return;
        }

        $this->announcer->announce($row->project, $repository, $delivery, ForgeRepositorySource::Installation, $installation->installationId);
    }

    private function dropped(GitHubDelivery $delivery, string $reason): void
    {
        $this->logger->info('github.delivery_dropped', [
            'event' => $delivery->event,
            'installationId' => $delivery->installationId(),
            'reason' => $reason,
        ]);
    }
}
