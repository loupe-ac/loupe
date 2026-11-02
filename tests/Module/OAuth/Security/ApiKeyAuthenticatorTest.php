<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Security;

use App\Audit\AuditChannel;
use App\Audit\LoupeAuditActorProvider;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Entity\GrantedCredential;
use App\Security\AuthenticatedCredential;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * No /api/v1 resource route exists yet, and an unmatched path answers 404
 * before the firewall runs. The public spec route stands in for a v1 path: it
 * is open to all, yet the firewall still reads a key sent with the request. A
 * live key gets 200 there and 403 on /api/projects, which needs the agent role.
 */
final class ApiKeyAuthenticatorTest extends WebTestCase
{
    private const string SPEC = '/api/v1/openapi.json';

    private const string SECRET = 'loupe_key_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->owner = new User(fullName: 'Riley Chen', email: 'api-key-auth@example.com', password: 'x');
        $this->owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($this->owner, static::getContainer());
        $this->em->persist($this->owner);
        $this->em->flush();
    }

    public function test_a_live_key_authenticates(): void
    {
        $this->store();

        $this->call(self::SPEC);
        self::assertResponseStatusCodeSame(200);

        $this->call('/api/projects');
        self::assertResponseStatusCodeSame(403);
    }

    public function test_an_unknown_key_is_refused_with_invalid_credentials_as_problem_json(): void
    {
        $this->call(self::SPEC, self::SECRET);

        self::assertResponseStatusCodeSame(401);
        self::assertSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('invalid_credentials', $this->body()['code']);
    }

    public function test_an_unknown_key_on_an_old_path_gets_the_flat_error(): void
    {
        $this->call('/api/projects', self::SECRET);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'unauthorized'], $this->body());
    }

    public function test_a_revoked_key_is_refused_with_invalid_credentials(): void
    {
        $key = $this->store();
        $key->revokedAt = new \DateTimeImmutable('-1 minute');
        $this->em->flush();

        $this->call(self::SPEC);

        self::assertResponseStatusCodeSame(401);
        self::assertSame('invalid_credentials', $this->body()['code']);
    }

    public function test_an_expired_key_is_refused_with_its_own_code(): void
    {
        $this->store(expiresAt: new \DateTimeImmutable('-1 second'));

        $this->call(self::SPEC);

        self::assertResponseStatusCodeSame(401);
        self::assertSame('api_key_expired', $this->body()['code']);
    }

    public function test_an_expired_key_on_an_old_path_keeps_its_code(): void
    {
        $this->store(expiresAt: new \DateTimeImmutable('-1 second'));

        $this->call('/api/projects');

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'api_key_expired'], $this->body());
    }

    public function test_a_key_that_ends_later_still_works(): void
    {
        $this->store(expiresAt: new \DateTimeImmutable('+1 day'));

        $this->call('/api/projects');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_key_of_a_suspended_owner_is_refused(): void
    {
        $this->store();
        $this->owner->suspendedAt = new \DateTimeImmutable();
        $this->owner->suspendedReason = 'Spamming.';
        $this->em->flush();

        $this->call(self::SPEC);

        self::assertResponseStatusCodeSame(401);
    }

    public function test_a_key_holds_only_the_public_scope(): void
    {
        $this->store();

        $this->call('/api/projects');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_a_key_is_refused_on_the_mcp_endpoint(): void
    {
        $this->store();

        $this->client->request(Request::METHOD_POST, '/mcp', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::SECRET,
            'CONTENT_TYPE' => 'application/json',
        ], content: '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}');

        self::assertResponseStatusCodeSame(401);
    }

    public function test_the_first_use_stamps_the_key_and_a_second_one_within_the_interval_does_not(): void
    {
        $key = $this->store();
        $id = (string) $key->id;

        $this->call(self::SPEC);
        $first = $this->lastUsedAt($id);
        self::assertNotNull($first);

        $recent = new \DateTimeImmutable('-1 minute');
        $this->setLastUsedAt($id, $recent);
        $this->call(self::SPEC);

        self::assertSame($recent->format('Y-m-d H:i:s'), $this->lastUsedAt($id));
    }

    public function test_a_use_after_the_interval_stamps_the_key_again(): void
    {
        $key = $this->store();
        $id = (string) $key->id;
        $this->setLastUsedAt($id, new \DateTimeImmutable('-10 minutes'));

        $this->call(self::SPEC);

        self::assertGreaterThan(new \DateTimeImmutable('-1 minute'), new \DateTimeImmutable((string) $this->lastUsedAt($id)));
    }

    public function test_a_refused_key_is_not_stamped(): void
    {
        $key = $this->store();
        $this->owner->suspendedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->call(self::SPEC);

        self::assertNull($this->lastUsedAt((string) $key->id));
    }

    public function test_the_credential_has_a_stable_handle_and_the_public_role(): void
    {
        $key = $this->store();
        $this->client->disableReboot();

        $this->call(self::SPEC);

        $tokens = static::getContainer()->get(TokenStorageInterface::class);
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $credential = AuthenticatedCredential::of($tokens->getToken());
        self::assertNotNull($credential);
        self::assertSame('api-key:'.$key->id, $credential->id);
        self::assertSame(['ROLE_API_PUBLIC'], $credential->roles);

        $provider = static::getContainer()->get(LoupeAuditActorProvider::class);
        self::assertInstanceOf(LoupeAuditActorProvider::class, $provider);
        $actor = $provider->currentActor();
        self::assertSame(AuditChannel::Api->value, $actor->channel);
        self::assertInstanceOf(GrantedCredential::class, $actor->credential);
        self::assertSame('api-key:'.$key->id, $actor->credential->handle);
    }

    private function store(?\DateTimeImmutable $expiresAt = null): ApiKey
    {
        $key = new ApiKey($this->owner, 'CI server', ApiKey::hashOf(self::SECRET), ApiKey::displayStartOf(self::SECRET), $expiresAt);
        $this->em->persist($key);
        $this->em->flush();

        return $key;
    }

    private function call(string $path, string $secret = self::SECRET): void
    {
        $this->client->request(Request::METHOD_GET, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function lastUsedAt(string $id): ?string
    {
        $value = $this->em->getConnection()->fetchOne('SELECT last_used_at FROM api_keys WHERE id = :id', ['id' => $id]);

        return false === $value || null === $value ? null : (string) $value;
    }

    private function setLastUsedAt(string $id, \DateTimeImmutable $at): void
    {
        $this->em->getConnection()->executeStatement('UPDATE api_keys SET last_used_at = :at WHERE id = :id', ['at' => $at->format('Y-m-d H:i:s'), 'id' => $id]);
    }
}
