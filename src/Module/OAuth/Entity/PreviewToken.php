<?php

declare(strict_types=1);

namespace App\Module\OAuth\Entity;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Repository\PreviewTokenRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use League\Bundle\OAuth2ServerBundle\Model\AccessToken;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Marks one widget access token as a preview token: a sign-in a person minted
 * for a preview site, with no popup. The token itself is an ordinary widget
 * sign-in, so this row only says who minted it, for which project and card,
 * and when it last served a request. The row goes with its access token.
 *
 * The card id has no foreign key, because OAuth must not depend on Board.
 */
#[ORM\Entity(repositoryClass: PreviewTokenRepository::class)]
#[ORM\Index(columns: ['user_id', 'project_id', 'label'])]
#[ORM\Index(columns: ['card_id'])]
#[ORM\Table(name: 'oauth_preview_tokens')]
class PreviewToken
{
    public const int MAX_LABEL_LENGTH = 100;

    public function __construct(
        #[ORM\Id]
        #[ORM\JoinColumn(name: 'access_token_id', referencedColumnName: 'identifier', nullable: false, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: AccessToken::class)]
        public readonly AccessToken $accessToken,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $user,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(length: self::MAX_LABEL_LENGTH)]
        public readonly string $label,

        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        public readonly ?Uuid $cardId = null,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),

        #[ORM\Column(nullable: true)]
        public ?\DateTimeImmutable $lastUsedAt = null,
    ) {
    }
}
