<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Service\CardEventActor;
use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Scope\ApiScope;
use App\Module\Workflow\Contract\Actor;
use App\Security\AuthenticatedCredential;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final class CardEventActorTest extends KernelTestCase
{
    private CardEventActor $actor;
    private User $owner;
    private ApiKey $key;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $actor = self::getContainer()->get(CardEventActor::class);
        self::assertInstanceOf(CardEventActor::class, $actor);
        $this->actor = $actor;
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->owner = new User(fullName: 'Riley', email: 'event-actor-'.uniqid().'@example.com', password: 'hashed');
        $em->persist($this->owner);
        $this->key = new ApiKey($this->owner, 'CI', ApiKey::hashOf('secret-'.uniqid()), 'loupe_key_abcd');
        $em->persist($this->key);
        $em->flush();
        $token = new PostAuthenticationToken($this->owner, 'api', [ApiScope::Public->role()]);
        $token->setAttribute(AuthenticatedCredential::ATTRIBUTE, new AuthenticatedCredential('api-key:'.$this->key->id?->toRfc4122(), [ApiScope::Public->role()]));
        $storage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $storage);
        $storage->setToken($token);
    }

    public function test_an_api_key_acts_as_its_owner(): void
    {
        self::assertSame($this->owner, $this->actor->userFor(Actor::ApiKey));
    }

    public function test_the_app_and_a_reviewer_act_as_no_account(): void
    {
        self::assertNull($this->actor->userFor(Actor::System));
        self::assertNull($this->actor->userFor(Actor::Reviewer));
    }

    public function test_an_api_key_row_carries_the_key(): void
    {
        $key = $this->actor->apiKeyFor(Actor::ApiKey);

        self::assertNotNull($key);
        self::assertEquals($this->key->id, $key->keyId);
        self::assertSame('CI', $key->keyName);
    }

    public function test_another_actor_carries_no_key(): void
    {
        self::assertNull($this->actor->apiKeyFor(Actor::Human));
        self::assertNull($this->actor->apiKeyFor(Actor::Agent));
        self::assertNull($this->actor->apiKeyFor(Actor::System));
    }
}
