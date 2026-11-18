<?php

declare(strict_types=1);

namespace App\Module\OAuth\Entity;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Repository\WorkerRunCredentialRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Names the run that one worker access token was issued for. The token itself
 * is an ordinary League access token, so this row says which bridge, which run
 * key and which session it belongs to.
 *
 * The access token id has no foreign key. The hourly purge deletes an expired
 * access token, and the row must outlive it, so a session that once held a
 * credential stays marked for as long as the row lives.
 */
#[ORM\Entity(repositoryClass: WorkerRunCredentialRepository::class)]
#[ORM\Index(columns: ['project_id', 'bridge_id', 'run_key'])]
#[ORM\Index(columns: ['session_id'])]
#[ORM\Index(columns: ['expires_at'])]
#[ORM\Table(name: 'oauth_worker_run_credentials')]
class WorkerRunCredential
{
    public const int ACCESS_TOKEN_ID_LENGTH = 80;

    public function __construct(
        #[ORM\Column(length: self::ACCESS_TOKEN_ID_LENGTH)]
        #[ORM\Id]
        public readonly string $accessTokenId,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $user,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(type: UuidType::NAME)]
        public readonly Uuid $bridgeId,

        #[ORM\Column(type: UuidType::NAME)]
        public readonly Uuid $runKey,

        #[ORM\Column(type: UuidType::NAME)]
        public readonly Uuid $sessionId,

        #[ORM\Column]
        public readonly \DateTimeImmutable $expiresAt,

        #[ORM\Column]
        public readonly \DateTimeImmutable $issuedAt = new \DateTimeImmutable(),

        #[ORM\Column(nullable: true)]
        public ?\DateTimeImmutable $revokedAt = null,
    ) {
    }
}
