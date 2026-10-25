<?php

declare(strict_types=1);

namespace App\Module\Notification\Entity;

use App\Module\Account\Entity\User;
use App\Module\Notification\Repository\PushDeviceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** One browser or Home Screen app of a user that can receive a push. */
#[ORM\Entity(repositoryClass: PushDeviceRepository::class)]
#[ORM\Table(name: 'push_devices')]
class PushDevice
{
    public const int LABEL_MAX_LENGTH = 80;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastDeliveredAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $stoppedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $warningDismissedAt = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $owner,

        #[ORM\Column(type: Types::TEXT, unique: true)]
        public string $endpoint,

        #[ORM\Column]
        public string $publicKey,

        #[ORM\Column]
        public string $authToken,

        #[ORM\Column(length: 20)]
        public string $contentEncoding,

        #[ORM\Column(length: self::LABEL_MAX_LENGTH)]
        public string $label,

        #[ORM\Column(length: 64)]
        public string $deviceTokenHash,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }
}
