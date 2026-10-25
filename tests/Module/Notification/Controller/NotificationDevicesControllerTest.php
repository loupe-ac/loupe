<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Controller;

use App\Module\Account\Entity\User;
use App\Module\Notification\Entity\PushDevice;
use App\Module\Notification\Repository\PushDeviceRepository;
use App\Module\Notification\Service\DeviceCookie;
use App\Module\Notification\Service\PushAvailability;
use App\Tests\Module\Notification\NotificationScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * No voter guards these routes. The handlers read the signed-in user's own
 * devices alone, so the tests below prove that another user's row is out of reach.
 */
final class NotificationDevicesControllerTest extends WebTestCase
{
    use NotificationScenario;

    private const string PAGE = '/account/notifications';

    private const string DEVICES = '/account/notifications/devices';

    public function test_the_page_renders_for_a_signed_in_user(): void
    {
        $client = $this->client();
        $client->loginUser($this->makeUser(static::getContainer(), 'page-owner@example.com'));

        $client->request(Request::METHOD_GET, self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-settings-nav');
    }

    public function test_the_page_needs_a_signed_in_user(): void
    {
        $client = $this->client();

        $client->request(Request::METHOD_GET, self::PAGE);

        self::assertResponseRedirects('/login');
    }

    public function test_a_subscription_is_stored_and_the_device_cookie_is_set(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'create-ok@example.com');
        $client->loginUser($user);

        $this->create($client, $this->fields());

        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Kitchen iPad', $body['label']);
        $cookie = $client->getResponse()->headers->getCookies()[0];
        self::assertSame(DeviceCookie::NAME, $cookie->getName());
        self::assertTrue($cookie->isHttpOnly());
        $stored = static::getContainer()->get(PushDeviceRepository::class)->findOneByEndpoint(self::ENDPOINT);
        self::assertNotNull($stored);
        self::assertSame((string) $stored->id, $body['id']);
        self::assertSame(DeviceCookie::hash((string) $cookie->getValue()), $stored->deviceTokenHash);
    }

    public function test_a_second_subscription_from_the_same_browser_reuses_the_row(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'create-twice@example.com');
        $client->loginUser($user);

        $this->create($client, $this->fields());
        $token = (string) $client->getResponse()->headers->getCookies()[0]->getValue();
        $first = json_decode((string) $client->getResponse()->getContent(), true)['id'];

        $client->getCookieJar()->set(new BrowserCookie(DeviceCookie::NAME, $token));
        $this->create($client, $this->fields(['endpoint' => 'https://web.push.apple.com/NEW']));

        self::assertResponseStatusCodeSame(201);
        self::assertSame($first, json_decode((string) $client->getResponse()->getContent(), true)['id']);
        self::assertSame(1, static::getContainer()->get(PushDeviceRepository::class)->count(['owner' => $user]));
    }

    public function test_a_stopped_row_comes_back_when_the_browser_subscribes_again(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'create-resubscribe@example.com');
        $token = new DeviceCookie()->generate();
        $device = $this->makeDevice(static::getContainer(), $user, 'https://web.push.apple.com/OLD', DeviceCookie::hash($token));
        $device->stoppedAt = new \DateTimeImmutable('2026-01-01');
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($user);
        $client->getCookieJar()->set(new BrowserCookie(DeviceCookie::NAME, $token));

        $this->create($client, $this->fields());

        self::assertResponseStatusCodeSame(201);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $stored = $em->find(PushDevice::class, $device->id);
        self::assertNotNull($stored);
        self::assertNull($stored->stoppedAt);
        self::assertSame(self::ENDPOINT, $stored->endpoint);
    }

    public function test_a_subscription_without_a_csrf_token_is_refused(): void
    {
        $client = $this->client();
        $client->loginUser($this->makeUser(static::getContainer(), 'create-csrf@example.com'));

        $this->create($client, $this->fields(), token: null);

        self::assertResponseStatusCodeSame(422);
        self::assertNull(static::getContainer()->get(PushDeviceRepository::class)->findOneByEndpoint(self::ENDPOINT));
    }

    public function test_a_subscription_is_refused_with_422_when_push_is_unavailable(): void
    {
        $client = $this->client(available: false);
        $client->loginUser($this->makeUser(static::getContainer(), 'create-off@example.com'));

        $this->create($client, $this->fields());

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('endpoint', $body['errors']);
        self::assertNotSame('', $body['message']);
        self::assertNull(static::getContainer()->get(PushDeviceRepository::class)->findOneByEndpoint(self::ENDPOINT));
    }

    public function test_an_endpoint_of_another_user_is_refused_with_409(): void
    {
        $client = $this->client();
        $other = $this->makeUser(static::getContainer(), 'create-taken-owner@example.com');
        $this->makeDevice(static::getContainer(), $other);
        $client->loginUser($this->makeUser(static::getContainer(), 'create-taken@example.com'));

        $this->create($client, $this->fields());

        self::assertResponseStatusCodeSame(409);
        self::assertArrayHasKey('endpoint', json_decode((string) $client->getResponse()->getContent(), true)['errors']);
        self::assertSame(1, static::getContainer()->get(PushDeviceRepository::class)->count(['endpoint' => self::ENDPOINT]));
    }

    public function test_an_endpoint_outside_the_allow_list_is_refused(): void
    {
        $client = $this->client();
        $client->loginUser($this->makeUser(static::getContainer(), 'create-policy@example.com'));

        $this->create($client, $this->fields(['endpoint' => 'https://10.0.0.5/admin']));

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('endpoint', json_decode((string) $client->getResponse()->getContent(), true)['errors']);
    }

    public function test_creating_devices_is_rate_limited_per_user(): void
    {
        $client = $this->client();
        static::getContainer()->set('limiter.notification_device_create', new RateLimiterFactory(
            ['id' => 'notification_device_create', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $client->loginUser($this->makeUser(static::getContainer(), 'create-limit@example.com'));

        $this->create($client, $this->fields());
        self::assertResponseStatusCodeSame(201);

        $this->create($client, $this->fields());
        self::assertResponseStatusCodeSame(429);
    }

    public function test_the_owner_removes_a_device(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'remove-ok@example.com');
        $device = $this->makeDevice(static::getContainer(), $user);
        $client->loginUser($user);

        $this->post($client, \sprintf('%s/%s/remove', self::DEVICES, self::idOf($device)));

        self::assertResponseRedirects(self::PAGE);
        self::assertNull(static::getContainer()->get(PushDeviceRepository::class)->findOneByEndpoint(self::ENDPOINT));
    }

    public function test_a_device_of_another_user_cannot_be_removed(): void
    {
        $client = $this->client();
        $other = $this->makeUser(static::getContainer(), 'remove-theirs@example.com');
        $device = $this->makeDevice(static::getContainer(), $other);
        $client->loginUser($this->makeUser(static::getContainer(), 'remove-thief@example.com'));

        $this->post($client, \sprintf('%s/%s/remove', self::DEVICES, self::idOf($device)));

        self::assertResponseStatusCodeSame(404);
        self::assertNotNull(static::getContainer()->get(PushDeviceRepository::class)->findOneByEndpoint(self::ENDPOINT));
    }

    public function test_a_removal_without_a_csrf_token_is_refused(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'remove-csrf@example.com');
        $device = $this->makeDevice(static::getContainer(), $user);
        $client->loginUser($user);

        $this->post($client, \sprintf('%s/%s/remove', self::DEVICES, self::idOf($device)), token: null);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull(static::getContainer()->get(PushDeviceRepository::class)->findOneByEndpoint(self::ENDPOINT));
    }

    public function test_a_test_push_is_queued_for_the_owner(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'test-ok@example.com');
        $device = $this->makeDevice(static::getContainer(), $user);
        $client->loginUser($user);

        $this->post($client, \sprintf('%s/%s/test', self::DEVICES, self::idOf($device)));

        self::assertResponseRedirects(self::PAGE);
        $flashes = self::flashes($client);
        self::assertArrayHasKey('success', $flashes);
    }

    public function test_a_test_push_for_a_device_of_another_user_is_a_404(): void
    {
        $client = $this->client();
        $other = $this->makeUser(static::getContainer(), 'test-theirs@example.com');
        $device = $this->makeDevice(static::getContainer(), $other);
        $client->loginUser($this->makeUser(static::getContainer(), 'test-thief@example.com'));

        $this->post($client, \sprintf('%s/%s/test', self::DEVICES, self::idOf($device)));

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_test_push_is_refused_when_push_is_unavailable(): void
    {
        $client = $this->client(available: false);
        $user = $this->makeUser(static::getContainer(), 'test-off@example.com');
        $device = $this->makeDevice(static::getContainer(), $user);
        $client->loginUser($user);

        $this->post($client, \sprintf('%s/%s/test', self::DEVICES, self::idOf($device)));

        self::assertResponseRedirects(self::PAGE);
        $flashes = self::flashes($client);
        self::assertArrayHasKey('error', $flashes);
        self::assertArrayNotHasKey('success', $flashes);
    }

    public function test_a_test_push_for_a_stopped_device_is_refused(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'test-stopped@example.com');
        $device = $this->makeDevice(static::getContainer(), $user);
        $device->stoppedAt = new \DateTimeImmutable();
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($user);

        $this->post($client, \sprintf('%s/%s/test', self::DEVICES, self::idOf($device)));

        self::assertResponseRedirects(self::PAGE);
        self::assertArrayHasKey('error', self::flashes($client));
    }

    public function test_test_pushes_are_rate_limited_per_user(): void
    {
        $client = $this->client();
        static::getContainer()->set('limiter.notification_test_push', new RateLimiterFactory(
            ['id' => 'notification_test_push', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $user = $this->makeUser(static::getContainer(), 'test-limit@example.com');
        $device = $this->makeDevice(static::getContainer(), $user);
        $client->loginUser($user);

        $this->post($client, \sprintf('%s/%s/test', self::DEVICES, self::idOf($device)));
        self::assertResponseRedirects(self::PAGE);

        $this->post($client, \sprintf('%s/%s/test', self::DEVICES, self::idOf($device)));
        self::assertResponseStatusCodeSame(429);
    }

    public function test_a_malformed_device_id_is_a_404(): void
    {
        $client = $this->client();
        $client->loginUser($this->makeUser(static::getContainer(), 'bad-id@example.com'));

        $this->post($client, self::DEVICES.'/not-a-uuid/remove');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_logout_removes_the_device_of_this_browser_and_clears_the_cookie(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'logout-owner@example.com');
        $other = $this->makeUser(static::getContainer(), 'logout-other@example.com');
        $token = new DeviceCookie()->generate();
        $mine = $this->makeDevice(static::getContainer(), $user, 'https://web.push.apple.com/MINE', DeviceCookie::hash($token));
        $theirs = $this->makeDevice(static::getContainer(), $other, 'https://web.push.apple.com/THEIRS', DeviceCookie::hash($token));
        $client->loginUser($user);
        $client->getCookieJar()->set(new BrowserCookie(DeviceCookie::NAME, $token));
        $mineId = $mine->id;
        $theirsId = $theirs->id;

        $client->request(Request::METHOD_GET, '/logout', ['_csrf_token' => 'csrf-token'], [], ['HTTP_REFERER' => 'http://localhost/account/notifications']);

        self::assertResponseRedirects();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(PushDevice::class, $mineId));
        self::assertNotNull($em->find(PushDevice::class, $theirsId));
        $cleared = array_values(array_filter(
            $client->getResponse()->headers->getCookies(),
            static fn ($cookie): bool => DeviceCookie::NAME === $cookie->getName(),
        ));
        self::assertCount(1, $cleared);
        self::assertLessThan(time(), $cleared[0]->getExpiresTime());
    }

    /** @return array<string, list<string>> */
    private static function flashes(KernelBrowser $client): array
    {
        $session = $client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);

        return $session->getFlashBag()->peekAll();
    }

    private function client(bool $available = true): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set(PushAvailability::class, self::availability($available));

        return $client;
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function fields(array $overrides = []): array
    {
        return $overrides + self::subscriptionKeys() + [
            'endpoint' => self::ENDPOINT,
            'contentEncoding' => 'aes128gcm',
            'label' => 'Kitchen iPad',
        ];
    }

    /** @param array<string, string> $fields */
    private function create(KernelBrowser $client, array $fields, ?string $token = 'csrf-token'): void
    {
        if (null !== $token) {
            $fields['token'] = $token;
        }

        // The same-origin sentinel passes the CSRF check when the referer matches.
        $client->request(Request::METHOD_POST, self::DEVICES, $fields, [], ['HTTP_REFERER' => 'http://localhost/account/notifications']);
    }

    private function post(KernelBrowser $client, string $url, ?string $token = 'csrf-token'): void
    {
        $client->request(Request::METHOD_POST, $url, null === $token ? [] : ['token' => $token], [], ['HTTP_REFERER' => 'http://localhost/account/notifications']);
    }
}
