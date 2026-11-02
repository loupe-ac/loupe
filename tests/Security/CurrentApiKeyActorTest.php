<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Scope\ApiScope;
use App\Module\Workflow\Contract\Actor;
use App\Security\AuthenticatedCredential;
use App\Security\CurrentApiKeyActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Uid\Uuid;

final class CurrentApiKeyActorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CurrentApiKeyActor $current;
    private User $owner;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $current = self::getContainer()->get(CurrentApiKeyActor::class);
        self::assertInstanceOf(CurrentApiKeyActor::class, $current);
        $this->current = $current;

        $this->owner = new User(fullName: 'Riley', email: 'api-key-actor-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($this->owner);
        $this->em->flush();
    }

    public function test_a_key_credential_gives_the_key_id_and_name(): void
    {
        $key = $this->key('CI');
        $this->authenticateAs('api-key:'.$key->id?->toRfc4122());

        $actor = $this->current->current();

        self::assertNotNull($actor);
        self::assertEquals($key->id, $actor->keyId);
        self::assertSame('CI', $actor->keyName);
        self::assertSame(Actor::ApiKey, $this->current->actorKind(Actor::Agent));
    }

    public function test_another_credential_gives_no_actor_and_keeps_the_fallback_kind(): void
    {
        $this->authenticateAs('oauth:test:1');

        self::assertNull($this->current->current());
        self::assertSame(Actor::Agent, $this->current->actorKind(Actor::Agent));
        self::assertSame(Actor::Human, $this->current->actorKind(Actor::Human));
    }

    public function test_no_token_gives_no_actor(): void
    {
        self::assertNull($this->current->current());
        self::assertSame(Actor::Human, $this->current->actorKind(Actor::Human));
    }

    public function test_a_key_id_that_matches_no_row_gives_no_actor_but_still_the_api_key_kind(): void
    {
        $this->authenticateAs('api-key:'.Uuid::v7()->toRfc4122());

        self::assertNull($this->current->current());
        self::assertSame(Actor::ApiKey, $this->current->actorKind(Actor::Human));
    }

    public function test_a_malformed_key_id_gives_no_actor(): void
    {
        $this->authenticateAs('api-key:not-a-uuid');

        self::assertNull($this->current->current());
    }

    private function key(string $name): ApiKey
    {
        $key = new ApiKey($this->owner, $name, ApiKey::hashOf('secret-'.uniqid()), 'loupe_key_abcd');
        $this->em->persist($key);
        $this->em->flush();

        return $key;
    }

    private function authenticateAs(string $credentialId): void
    {
        $token = new PostAuthenticationToken($this->owner, 'api', [...$this->owner->getRoles(), ApiScope::Public->role()]);
        $token->setAttribute(AuthenticatedCredential::ATTRIBUTE, new AuthenticatedCredential($credentialId, [ApiScope::Public->role()]));
        $storage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $storage);
        $storage->setToken($token);
    }
}
