<?php

declare(strict_types=1);

namespace App\Module\OAuth\Entity;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Repository\ApiKeyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A long-lived credential for the public API. The row keeps the SHA-256 hash of
 * the key and its first characters, and never the key itself. The key is shown
 * once, when it is created.
 */
#[ORM\Entity(repositoryClass: ApiKeyRepository::class)]
#[ORM\Table(name: 'api_keys')]
class ApiKey
{
    public const string PREFIX = 'loupe_key_';

    public const int NAME_MAX_LENGTH = 100;

    /** How many characters after the prefix the list shows. */
    private const int DISPLAY_CHARACTERS = 4;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $expiryNoticeSentAt = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $owner,

        #[ORM\Column(length: self::NAME_MAX_LENGTH)]
        public readonly string $name,

        #[ORM\Column(length: 64, unique: true)]
        public readonly string $keyHash,

        #[ORM\Column(length: 32)]
        public readonly string $displayStart,

        #[ORM\Column(nullable: true)]
        public readonly ?\DateTimeImmutable $expiresAt = null,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    public static function hashOf(string $key): string
    {
        return hash('sha256', $key);
    }

    public static function displayStartOf(string $key): string
    {
        return substr($key, 0, \strlen(self::PREFIX) + self::DISPLAY_CHARACTERS);
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return null !== $this->expiresAt && $this->expiresAt <= $now;
    }
}
