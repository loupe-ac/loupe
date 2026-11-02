<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Controller;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Repository\ApiKeyRepository;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ApiKeysPageTest extends WebTestCase
{
    private const string PAGE = '/account/api-keys';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ApiKeyRepository $apiKeys;
    private OAuthScenario $scenario;
    private User $owner;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $apiKeys = $container->get(ApiKeyRepository::class);
        self::assertInstanceOf(ApiKeyRepository::class, $apiKeys);
        $this->apiKeys = $apiKeys;
        $this->scenario = new OAuthScenario($container);
        $this->owner = $this->scenario->createUser('api-keys-page-owner@example.com');
    }

    public function test_the_page_shows_the_empty_state_and_the_nav_entry(): void
    {
        $this->client->loginUser($this->owner);
        $this->client->request(Request::METHOD_GET, self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lp-empty-state', 'No API keys yet');
        self::assertSelectorTextContains('.lp-empty-state', 'Create a key to call the Loupe API from a script or a CI job.');
        self::assertSelectorTextContains('.lp-settings-nav', 'API keys');
        self::assertSelectorNotExists('[data-api-key]');
    }

    public function test_a_row_shows_the_name_the_start_the_expiry_and_the_last_use(): void
    {
        $live = $this->persistKey($this->owner, 'CI deploy', 'aaaa', new \DateTimeImmutable('+82 days 1 hour'), lastUsedAt: new \DateTimeImmutable('-2 hours'));
        $forever = $this->persistKey($this->owner, 'Dashboard', 'bbbb', null);
        $this->em->clear();

        $this->client->loginUser($this->owner);
        $this->client->request(Request::METHOD_GET, self::PAGE);

        self::assertResponseIsSuccessful();
        $row = '[data-api-key="'.$live->id.'"]';
        self::assertSelectorTextContains($row, 'CI deploy');
        self::assertSelectorTextContains($row, 'loupe_key_aaaa…');
        self::assertSelectorTextContains($row, 'in 83 days');
        self::assertSelectorTextContains($row, '2h ago');
        self::assertSelectorTextContains($row, 'Revoke');
        $other = '[data-api-key="'.$forever->id.'"]';
        self::assertSelectorTextContains($other, 'Never');
        self::assertSelectorTextContains($other, 'Never used');
    }

    public function test_the_page_lists_only_the_keys_of_the_person(): void
    {
        $stranger = $this->scenario->createUser('api-keys-page-stranger@example.com');
        $theirs = $this->persistKey($stranger, 'Their key', 'zzzz', null);
        $this->em->clear();

        $this->client->loginUser($this->owner);
        $this->client->request(Request::METHOD_GET, self::PAGE);

        self::assertSelectorNotExists('[data-api-key="'.$theirs->id.'"]');
    }

    public function test_a_created_key_is_shown_once_and_a_reload_shows_only_its_start(): void
    {
        $this->client->loginUser($this->owner);
        $crawler = $this->client->request(Request::METHOD_GET, self::PAGE);
        $this->client->submit($crawler->filter('form[name="create_api_key_form"]')->form([
            'create_api_key_form[name]' => 'CI deploy',
            'create_api_key_form[expiry]' => 'thirty-days',
        ]));

        self::assertResponseRedirects(self::PAGE);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $secret = $this->client->getCrawler()->filter('[data-testid="api-key-secret"]')->text();
        self::assertStringStartsWith(ApiKey::PREFIX, $secret);
        self::assertSelectorTextContains('[data-api-key-once]', 'Copy your new key');
        self::assertSelectorTextContains('[data-api-key-once]', 'CI deploy');

        $stored = $this->apiKeys->findOneBy(['keyHash' => ApiKey::hashOf($secret)]);
        self::assertNotNull($stored);
        self::assertSame('CI deploy', $stored->name);
        self::assertSelectorExists('[data-api-key="'.$stored->id.'"]');

        $this->client->request(Request::METHOD_GET, self::PAGE);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-api-key-once]');
        self::assertStringNotContainsString($secret, (string) $this->client->getResponse()->getContent());
        self::assertSelectorTextContains('[data-api-key="'.$stored->id.'"]', $stored->displayStart.'…');
    }

    public function test_a_blank_name_answers_422_with_the_dialog_open_and_creates_no_key(): void
    {
        $this->client->loginUser($this->owner);
        $crawler = $this->client->request(Request::METHOD_GET, self::PAGE);
        $this->client->submit($crawler->filter('form[name="create_api_key_form"]')->form([
            'create_api_key_form[name]' => '',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-modal-reopen-value="true"] form[name="create_api_key_form"]');
        self::assertSelectorNotExists('[data-api-key-once]');
        self::assertSame(0, $this->apiKeys->count([]));
    }

    public function test_a_name_over_the_limit_answers_422(): void
    {
        $this->client->loginUser($this->owner);
        $crawler = $this->client->request(Request::METHOD_GET, self::PAGE);
        $this->client->submit($crawler->filter('form[name="create_api_key_form"]')->form([
            'create_api_key_form[name]' => str_repeat('a', ApiKey::NAME_MAX_LENGTH + 1),
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->apiKeys->count([]));
    }

    public function test_revoking_a_key_removes_its_row_and_stops_the_key(): void
    {
        $key = $this->persistKey($this->owner, 'CI deploy', 'aaaa', null);
        $other = $this->persistKey($this->owner, 'Dashboard', 'bbbb', null);
        $keyId = (string) $key->id;
        $otherId = (string) $other->id;
        $this->em->clear();

        $this->client->loginUser($this->owner);
        $crawler = $this->client->request(Request::METHOD_GET, self::PAGE);
        $this->client->submit($crawler->filter('[data-api-key="'.$keyId.'"] form')->form());

        self::assertResponseRedirects(self::PAGE);
        $this->client->followRedirect();
        self::assertSelectorNotExists('[data-api-key="'.$keyId.'"]');
        self::assertSelectorExists('[data-api-key="'.$otherId.'"]');
        $this->em->clear();
        self::assertNotNull($this->em->find(ApiKey::class, $keyId)?->revokedAt);
        self::assertNull($this->em->find(ApiKey::class, $otherId)?->revokedAt);
    }

    public function test_an_expired_key_shows_a_chip_and_a_remove_button_that_deletes_it(): void
    {
        $key = $this->persistKey($this->owner, 'Old script', 'cccc', new \DateTimeImmutable('-3 days -1 hour'));
        $keyId = (string) $key->id;
        $this->em->clear();

        $this->client->loginUser($this->owner);
        $crawler = $this->client->request(Request::METHOD_GET, self::PAGE);
        $row = '[data-api-key="'.$keyId.'"]';
        self::assertSelectorTextContains($row.' .lp-status-chip--failed', 'Expired 3 days ago');
        self::assertSelectorTextContains($row, 'Remove');
        self::assertSelectorTextNotContains($row, 'Revoke');

        $this->client->submit($crawler->filter($row.' form')->form());

        self::assertResponseRedirects(self::PAGE);
        $this->em->clear();
        self::assertNull($this->em->find(ApiKey::class, $keyId));
    }

    public function test_removing_a_working_key_is_refused_with_a_message(): void
    {
        $key = $this->persistKey($this->owner, 'CI deploy', 'aaaa', null);
        $keyId = (string) $key->id;
        $this->em->clear();

        $this->client->loginUser($this->owner);
        $this->client->request(Request::METHOD_GET, self::PAGE);
        $this->client->request(Request::METHOD_POST, self::PAGE.'/'.$keyId.'/remove', ['_csrf_token' => 'csrf-token']);

        self::assertResponseRedirects(self::PAGE);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Revoke this key before you remove it.');
        self::assertNotNull($this->em->find(ApiKey::class, $keyId));
    }

    public function test_another_person_cannot_revoke_or_remove_a_key(): void
    {
        $stranger = $this->scenario->createUser('api-keys-page-stranger@example.com');
        $theirs = $this->persistKey($stranger, 'Their key', 'zzzz', new \DateTimeImmutable('-1 day'));
        $theirsId = (string) $theirs->id;
        $this->em->clear();

        $this->client->loginUser($this->owner);
        $this->client->request(Request::METHOD_GET, self::PAGE);
        $this->client->request(Request::METHOD_POST, self::PAGE.'/'.$theirsId.'/revoke', ['_csrf_token' => 'csrf-token']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request(Request::METHOD_POST, self::PAGE.'/'.$theirsId.'/remove', ['_csrf_token' => 'csrf-token']);
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        $stored = $this->em->find(ApiKey::class, $theirsId);
        self::assertNotNull($stored);
        self::assertNull($stored->revokedAt);
    }

    private function persistKey(User $owner, string $name, string $start, ?\DateTimeImmutable $expiresAt, ?\DateTimeImmutable $lastUsedAt = null): ApiKey
    {
        $secret = ApiKey::PREFIX.$start.bin2hex(random_bytes(30));
        $key = new ApiKey($owner, $name, ApiKey::hashOf($secret), ApiKey::displayStartOf($secret), $expiresAt);
        $key->lastUsedAt = $lastUsedAt;
        $this->em->persist($key);
        $this->em->flush();

        return $key;
    }
}
