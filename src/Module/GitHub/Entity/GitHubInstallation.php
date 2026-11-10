<?php

declare(strict_types=1);

namespace App\Module\GitHub\Entity;

use App\Module\GitHub\Repository\GitHubInstallationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One installation of the GitHub App, a fact about a GitHub account. It
 * belongs to no project: a project uses it when one of its repository rows
 * names it. A removed installation keeps its row, so a late delivery for it is
 * recognised and dropped.
 */
#[ORM\Entity(repositoryClass: GitHubInstallationRepository::class)]
#[ORM\Table(name: 'github_installations')]
class GitHubInstallation
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $suspendedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $removedAt = null;

    public function __construct(
        #[ORM\Column(type: Types::BIGINT, unique: true)]
        public readonly int $installationId,

        #[ORM\Column(length: 255)]
        public string $accountLogin,

        #[ORM\Column(length: 20, enumType: GitHubRepositorySelection::class)]
        public GitHubRepositorySelection $repositorySelection,

        /** GitHub's numeric id of the account, which survives a rename. Null until a delivery or the backfill fills it. */
        #[ORM\Column(type: Types::BIGINT, nullable: true)]
        public ?int $accountId = null,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }
}
