<?php

declare(strict_types=1);

namespace App\Api\Idempotency\Entity;

use App\Api\Idempotency\IdempotencyState;
use App\Api\Idempotency\Repository\IdempotencyRecordRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The claim of one `Idempotency-Key` by one credential, and the answer that the
 * first request gave. The repository writes it through DBAL in short statements
 * of their own, so a refusal inside a request transaction cannot lose a claim.
 */
#[ORM\Entity(repositoryClass: IdempotencyRecordRepository::class)]
#[ORM\Index(name: 'idx_api_idempotency_expires_at', columns: ['expires_at'])]
#[ORM\Index(name: 'idx_api_idempotency_owner', columns: ['owner_id'])]
#[ORM\Table(name: 'api_idempotency_records')]
#[ORM\UniqueConstraint(name: 'uniq_api_idempotency_credential_key', columns: ['credential', 'idempotency_key'])]
class IdempotencyRecord
{
    public const int MAX_KEY_LENGTH = 255;

    public function __construct(
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        #[ORM\Id]
        public readonly Uuid $id,

        #[ORM\Column(length: 255)]
        public readonly string $credential,

        #[ORM\Column(length: self::MAX_KEY_LENGTH)]
        public readonly string $idempotencyKey,

        #[ORM\Column(type: UuidType::NAME)]
        public readonly Uuid $ownerId,

        #[ORM\Column(length: 64)]
        public readonly string $requestHash,

        #[ORM\Column(length: 20, enumType: IdempotencyState::class)]
        public IdempotencyState $state,

        #[ORM\Column(nullable: true)]
        public ?int $status,

        /** @var array<string, string>|null */
        #[ORM\Column(type: Types::JSON, nullable: true)]
        public ?array $headers,

        #[ORM\Column(type: Types::TEXT, nullable: true)]
        public ?string $body,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt,

        #[ORM\Column]
        public readonly \DateTimeImmutable $expiresAt,
    ) {
    }
}
