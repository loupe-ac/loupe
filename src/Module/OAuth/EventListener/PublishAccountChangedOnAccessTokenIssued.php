<?php

declare(strict_types=1);

namespace App\Module\OAuth\EventListener;

use App\Mercure\AccountArea;
use App\Mercure\AccountChangedPublisher;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use League\Bundle\OAuth2ServerBundle\Model\AccessToken;
use Symfony\Component\Uid\Uuid;

/**
 * The OAuth server fires no app event when it issues a token, so a Doctrine
 * listener on the stored token stands in. The publisher holds the signal until
 * the token commits, and a refresh that rolls back signals nothing.
 */
#[AsEntityListener(event: Events::postPersist, entity: AccessToken::class)]
final readonly class PublishAccountChangedOnAccessTokenIssued
{
    public function __construct(
        private AccountChangedPublisher $publisher,
    ) {
    }

    public function __invoke(AccessToken $token, PostPersistEventArgs $event): void
    {
        $userIdentifier = $token->getUserIdentifier();
        if (null === $userIdentifier || !Uuid::isValid($userIdentifier)) {
            return;
        }

        $this->publisher->changed(Uuid::fromString($userIdentifier), AccountArea::APPS);
    }
}
