<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Entity;

use App\Module\Account\Entity\User;
use App\Module\OpenRouter\Repository\OpenRouterKeyRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * An OpenRouter management key. It belongs to a user, who owns it for every
 * project they own, or to one project, which then overrides the user key. A
 * database check keeps it to exactly one of the two.
 */
#[ORM\Entity(repositoryClass: OpenRouterKeyRepository::class)]
#[ORM\Table(name: 'openrouter_keys')]
class OpenRouterKey
{
    private const int LAST_FOUR_LENGTH = 4;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /** The characters the pages show to tell two keys apart. The key itself is never shown. */
    #[ORM\Column(name: 'last_four', length: self::LAST_FOUR_LENGTH)]
    public readonly string $lastFour;

    #[ORM\Column(length: 20, enumType: OpenRouterKeyState::class, options: ['default' => 'working'])]
    public OpenRouterKeyState $state = OpenRouterKeyState::Working;

    /** Set when OpenRouter first refused the key since it last worked. */
    #[ORM\Column(name: 'refused_since', nullable: true)]
    public ?\DateTimeImmutable $refusedSince = null;

    /** The last time a cost read with this key worked. */
    #[ORM\Column(name: 'last_read_at', nullable: true)]
    public ?\DateTimeImmutable $lastReadAt = null;

    public function __construct(
        #[ORM\Column(type: 'encrypted_string')]
        public readonly string $secret,

        #[ORM\JoinColumn(unique: true, nullable: true, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: User::class)]
        public readonly ?User $user = null,

        #[ORM\JoinColumn(unique: true, nullable: true, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: Project::class)]
        public readonly ?Project $project = null,

        #[ORM\Column(name: 'created_at')]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        if ((null === $user) === (null === $project)) {
            throw new \InvalidArgumentException('An OpenRouter key belongs to exactly one user or one project.');
        }

        $this->lastFour = self::lastFourOf($secret);
    }

    public static function lastFourOf(string $secret): string
    {
        return substr($secret, -self::LAST_FOUR_LENGTH);
    }
}
