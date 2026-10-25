<?php

declare(strict_types=1);

namespace App\Module\Notification\Entity;

use App\Module\Account\Entity\User;
use App\Module\Notification\NotificationChannel;
use App\Module\Notification\NotificationEvent;
use App\Module\Notification\Repository\NotificationPreferenceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** A choice of one user that differs from the default of its event. */
#[ORM\Entity(repositoryClass: NotificationPreferenceRepository::class)]
#[ORM\Table(name: 'notification_preferences')]
#[ORM\UniqueConstraint(name: 'uniq_notification_preference_cell', columns: ['owner_id', 'event', 'channel'])]
class NotificationPreference
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $owner,

        #[ORM\Column(length: 40, enumType: NotificationEvent::class)]
        public readonly NotificationEvent $event,

        #[ORM\Column(length: 40, enumType: NotificationChannel::class)]
        public readonly NotificationChannel $channel,

        #[ORM\Column]
        public bool $enabled,
    ) {
    }
}
