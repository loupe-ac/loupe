<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Controller\Admin;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Entity\ApiKey;
use App\Tests\Support\OAuthScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RevokeApiKeyAsAdminControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private OAuthScenario $scenario;
    private User $admin;
    private User $owner;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->scenario = new OAuthScenario($container);
        $this->admin = $this->scenario->createUser('api-key-admin@example.com');
        $this->admin->roles = ['ROLE_ADMIN'];
        $this->owner = $this->scenario->createUser('api-key-admin-owner@example.com');
        $this->em->flush();
    }

    public function test_the_user_page_lists_the_keys_with_a_revoke_action_for_live_keys_only(): void
    {
        $live = $this->persistKey($this->owner, 'CI deploy');
        $revoked = $this->persistKey($this->owner, 'Old key');
        $revoked->revokedAt = new \DateTimeImmutable('-1 day');
        $expired = $this->persistKey($this->owner, 'Expired key', new \DateTimeImmutable('-2 days'));
        $this->em->flush();
        $this->em->clear();

        $this->client->loginUser($this->admin);
        $this->client->request(Request::METHOD_GET, '/admin/users/'.$this->owner->id);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="api-keys-panel"]');
        self::assertSelectorTextContains('[data-api-key="'.$live->id.'"]', 'CI deploy');
        self::assertSelectorTextContains('[data-api-key="'.$live->id.'"]', 'loupe_key_');
        self::assertSelectorExists('[data-api-key="'.$live->id.'"] [data-testid="api-key-revoke"]');
        self::assertSelectorTextContains('[data-api-key="'.$revoked->id.'"]', 'Revoked');
        self::assertSelectorNotExists('[data-api-key="'.$revoked->id.'"] [data-testid="api-key-revoke"]');
        self::assertSelectorTextContains('[data-api-key="'.$expired->id.'"]', 'Expired');
        self::assertSelectorNotExists('[data-api-key="'.$expired->id.'"] [data-testid="api-key-revoke"]');
    }

    public function test_the_user_page_says_when_the_account_has_no_key(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request(Request::METHOD_GET, '/admin/users/'.$this->owner->id);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="api-keys-panel-empty"]');
    }

    public function test_an_admin_revokes_a_key_and_the_record_names_the_admin(): void
    {
        $this->client->disableReboot();
        $audit = RecordingAuditor::installedIn(static::getContainer());
        $key = $this->persistKey($this->owner, 'CI deploy');
        $keyId = (string) $key->id;
        $this->em->clear();

        $this->client->loginUser($this->admin);
        $this->post($keyId);

        self::assertResponseRedirects('/admin/users/'.$this->owner->id);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'The key no longer works.');

        $this->em->clear();
        self::assertNotNull($this->em->find(ApiKey::class, $keyId)?->revokedAt);
        self::assertSame('admin', $audit->record('oauth.api_key_revoked')->context['revokedBy'] ?? null);
    }

    public function test_a_second_revoke_flashes_the_error_and_changes_nothing(): void
    {
        $key = $this->persistKey($this->owner, 'CI deploy');
        $keyId = (string) $key->id;
        $this->em->clear();

        $this->client->loginUser($this->admin);
        $this->post($keyId);
        $this->em->clear();
        $first = $this->em->find(ApiKey::class, $keyId)?->revokedAt;
        self::assertNotNull($first);

        $this->post($keyId);
        self::assertResponseRedirects('/admin/users/'.$this->owner->id);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'This key is already revoked.');

        $this->em->clear();
        self::assertEquals($first, $this->em->find(ApiKey::class, $keyId)?->revokedAt);
    }

    public function test_an_account_without_the_admin_role_gets_403_and_the_key_stays_live(): void
    {
        $key = $this->persistKey($this->owner, 'CI deploy');
        $keyId = (string) $key->id;
        $stranger = $this->scenario->createUser('api-key-admin-stranger@example.com');
        $this->em->clear();

        $this->client->loginUser($stranger);
        $this->post($keyId);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->em->clear();
        self::assertNull($this->em->find(ApiKey::class, $keyId)?->revokedAt);
    }

    public function test_the_owner_without_the_admin_role_cannot_use_the_admin_route(): void
    {
        $key = $this->persistKey($this->owner, 'CI deploy');
        $keyId = (string) $key->id;
        $this->em->clear();

        $this->client->loginUser($this->owner);
        $this->post($keyId);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->em->clear();
        self::assertNull($this->em->find(ApiKey::class, $keyId)?->revokedAt);
    }

    private function post(string $keyId): void
    {
        // A preceding authenticated request establishes BrowserKit history, so
        // the stateless CSRF sentinel is accepted as same-origin.
        $this->client->request(Request::METHOD_GET, '/account/api-keys');
        $this->client->request(Request::METHOD_POST, '/admin/api-keys/'.$keyId.'/revoke', ['_csrf_token' => 'csrf-token']);
    }

    private function persistKey(User $owner, string $name, ?\DateTimeImmutable $expiresAt = null): ApiKey
    {
        $secret = ApiKey::PREFIX.bin2hex(random_bytes(32));
        $key = new ApiKey($owner, $name, ApiKey::hashOf($secret), ApiKey::displayStartOf($secret), $expiresAt);
        $this->em->persist($key);
        $this->em->flush();

        return $key;
    }
}
