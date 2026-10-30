<?php

declare(strict_types=1);

namespace App\Module\OAuth\Entity;

use Doctrine\ORM\Mapping as ORM;
use League\Bundle\OAuth2ServerBundle\Model\RefreshToken;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Says which login a refresh token belongs to. A refresh copies the login of
 * the token it replaces, so the tag outlives every token of the login. The row
 * goes with its token.
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_oauth_refresh_token_login_login', columns: ['login_id'])]
#[ORM\Table(name: 'oauth_refresh_token_login')]
class RefreshTokenLogin
{
    public function __construct(
        #[ORM\Id]
        #[ORM\JoinColumn(name: 'refresh_token_identifier', referencedColumnName: 'identifier', nullable: false, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: RefreshToken::class)]
        public readonly RefreshToken $refreshToken,

        #[ORM\Column(name: 'login_id', type: UuidType::NAME)]
        public readonly Uuid $loginId,
    ) {
    }
}
