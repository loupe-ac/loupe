<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Keeps the record of an installation that GitHub lists for the signed-in owner. */
final readonly class GitHubInstallationRecords
{
    public function __construct(
        private GitHubInstallationRepository $gitHubInstallations,
        private EntityManagerInterface $em,
    ) {
    }

    public function sync(GitHubUserInstallation $listed): GitHubInstallation
    {
        $this->gitHubInstallations->insertIfAbsent($listed->id, $listed->accountLogin, $listed->repositorySelection, $listed->accountId);
        $installation = $this->gitHubInstallations->findOneByInstallationId($listed->id) ?? throw new \LogicException('The record exists after the insert.');
        $installation->accountLogin = $listed->accountLogin;
        $installation->accountId = $listed->accountId ?? $installation->accountId;
        $installation->repositorySelection = $listed->repositorySelection;
        $installation->removedAt = null;
        $this->em->flush();

        return $installation;
    }
}
