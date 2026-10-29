<?php

declare(strict_types=1);

namespace App\Module\OAuth\Entity;

use Doctrine\ORM\Mapping as ORM;
use League\Bundle\OAuth2ServerBundle\Model\RefreshToken;

/**
 * Says which refresh token replaced which. The first rotation of a token
 * writes the row, and a retry of the same token only swaps the successor.
 * `rotatedAt` keeps the time of the first rotation, so repeated retries cannot
 * stretch the time a replaced token stays redeemable. The row goes with either
 * of its tokens.
 */
#[ORM\Entity]
#[ORM\Table(name: 'oauth_refresh_token_rotation')]
class RefreshTokenRotation
{
    public function __construct(
        #[ORM\Id]
        #[ORM\JoinColumn(name: 'previous_identifier', referencedColumnName: 'identifier', nullable: false, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: RefreshToken::class)]
        public readonly RefreshToken $previous,

        #[ORM\JoinColumn(name: 'successor_identifier', referencedColumnName: 'identifier', nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: RefreshToken::class)]
        public RefreshToken $successor,

        #[ORM\Column]
        public readonly \DateTimeImmutable $rotatedAt,
    ) {
    }
}
