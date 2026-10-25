<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Controller;

use App\Module\Notification\Service\DeviceCookie;
use App\Module\Notification\Service\PushAvailability;
use App\Tests\Module\Notification\NotificationScenario;
use App\Tests\Support\FeatureFlags;
use Minishlink\WebPush\VAPID;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\HttpFoundation\Request;

final class ShowNotificationSettingsControllerTest extends WebTestCase
{
    use NotificationScenario;

    private const string PAGE = '/account/notifications';

    private string $publicKey = '';

    private string $privateKey = '';

    public function test_a_set_up_instance_shows_the_devices_and_the_push_controls(): void
    {
        $client = $this->client(true);
        $user = $this->makeUser(static::getContainer(), 'page-available@example.com');
        $other = $this->makeUser(static::getContainer(), 'page-other@example.com');
        $token = new DeviceCookie()->generate();
        $mine = $this->makeDevice(static::getContainer(), $user, 'https://web.push.apple.com/MINE', DeviceCookie::hash($token));
        $this->makeDevice(static::getContainer(), $user, 'https://web.push.apple.com/SECOND', 'another-hash');
        $this->makeDevice(static::getContainer(), $other, 'https://web.push.apple.com/THEIRS', 'their-hash');
        $client->loginUser($user);
        $client->getCookieJar()->set(new BrowserCookie(DeviceCookie::NAME, $token));

        $crawler = $client->request(Request::METHOD_GET, self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-device-id]'));
        $mineRow = $crawler->filter('[data-device-id="'.$mine->id.'"]');
        self::assertCount(1, $mineRow);
        self::assertCount(1, $mineRow->filter('[data-this-device]'));
        self::assertCount(1, $crawler->filter('[data-this-device]'));
        self::assertStringContainsString('Chrome on macOS', $mineRow->text());
        self::assertStringContainsString('Added ', $mineRow->text());

        $test = $mineRow->filter('form[action="'.self::PAGE.'/devices/'.$mine->id.'/test"]');
        $remove = $mineRow->filter('form[action="'.self::PAGE.'/devices/'.$mine->id.'/remove"]');
        self::assertCount(1, $test);
        self::assertCount(1, $remove);
        foreach ([$test, $remove] as $form) {
            self::assertSame('post', strtolower((string) $form->attr('method')));
            $field = $form->filter('input[name="token"][data-controller="csrf-protection"]');
            self::assertCount(1, $field);
            self::assertNotSame('', (string) $field->attr('value'));
        }

        self::assertStringContainsString('Each browser or Home Screen app that gets your pushes', $crawler->text());
        self::assertStringContainsString('To add your phone, open this page in the Loupe Home Screen app on it.', $crawler->text());
    }

    public function test_the_page_hands_the_public_key_to_the_controller_and_never_the_private_one(): void
    {
        $client = $this->client(true);
        $client->loginUser($this->makeUser(static::getContainer(), 'page-keys@example.com'));

        $crawler = $client->request(Request::METHOD_GET, self::PAGE);

        $controller = $crawler->filter('[data-controller~="push-device"]');
        self::assertCount(1, $controller);
        self::assertSame($this->publicKey, $controller->attr('data-push-device-public-key-value'));
        self::assertSame(self::PAGE.'/devices', $controller->attr('data-push-device-create-url-value'));
        self::assertSame('/sw.js', $controller->attr('data-push-device-service-worker-url-value'));
        self::assertStringNotContainsString($this->privateKey, (string) $client->getResponse()->getContent());
        foreach (['off', 'on', 'blocked', 'ios-tab', 'not-supported'] as $panel) {
            self::assertCount(1, $controller->filter('[data-push-device-target="panel"][data-panel="'.$panel.'"]'), $panel);
        }
        self::assertCount(1, $controller->filter('form[data-push-device-target="form"] input[name="token"][data-controller="csrf-protection"]'));
        self::assertCount(1, $controller->filter('[data-action="push-device#turnOn"]'));
    }

    public function test_the_controller_knows_whether_this_browser_has_a_device_row(): void
    {
        $client = $this->client(true);
        $user = $this->makeUser(static::getContainer(), 'page-known@example.com');
        $client->loginUser($user);

        $crawler = $client->request(Request::METHOD_GET, self::PAGE);
        self::assertSame('false', $crawler->filter('[data-controller~="push-device"]')->attr('data-push-device-device-known-value'));

        $token = new DeviceCookie()->generate();
        $this->makeDevice(static::getContainer(), $user, 'https://web.push.apple.com/KNOWN', DeviceCookie::hash($token));
        $client->getCookieJar()->set(new BrowserCookie(DeviceCookie::NAME, $token));

        $crawler = $client->request(Request::METHOD_GET, self::PAGE);
        self::assertSame('true', $crawler->filter('[data-controller~="push-device"]')->attr('data-push-device-device-known-value'));
    }

    public function test_the_blocked_state_sits_in_the_row_of_this_browser(): void
    {
        $client = $this->client(true);
        $user = $this->makeUser(static::getContainer(), 'page-blocked@example.com');
        $client->loginUser($user);
        $userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/130.0 Safari/537.36';

        $crawler = $client->request(Request::METHOD_GET, self::PAGE, server: ['HTTP_USER_AGENT' => $userAgent]);
        $row = $crawler->filter('.lp-device-list > .lp-device-row[data-panel="blocked"]');
        self::assertCount(1, $row);
        self::assertStringContainsString('Chrome on macOS', $row->text());
        self::assertStringContainsString('Blocked by this browser', $row->text());

        $token = new DeviceCookie()->generate();
        $device = $this->makeDevice(static::getContainer(), $user, 'https://web.push.apple.com/BLOCKED', DeviceCookie::hash($token));
        $client->getCookieJar()->set(new BrowserCookie(DeviceCookie::NAME, $token));

        $crawler = $client->request(Request::METHOD_GET, self::PAGE, server: ['HTTP_USER_AGENT' => $userAgent]);
        self::assertCount(1, $crawler->filter('[data-panel="blocked"]'));
        self::assertCount(0, $crawler->filter('.lp-device-list > .lp-device-row[data-panel="blocked"]'));
        $panel = $crawler->filter('.lp-device-row[data-device-id="'.self::idOf($device)->toRfc4122().'"] > [data-panel="blocked"]');
        self::assertCount(1, $panel);
        self::assertStringContainsString('Blocked by this browser', $panel->text());
    }

    public function test_a_stopped_device_is_a_plain_row_with_its_label(): void
    {
        $client = $this->client(true);
        $user = $this->makeUser(static::getContainer(), 'page-stopped@example.com');
        $device = $this->makeDevice(static::getContainer(), $user);
        $device->stoppedAt = new \DateTimeImmutable('2026-10-20');
        static::getContainer()->get('doctrine.orm.entity_manager')->flush();
        $client->loginUser($user);

        $crawler = $client->request(Request::METHOD_GET, self::PAGE);

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-device-id="'.$device->id.'"]');
        self::assertCount(1, $row);
        self::assertStringContainsString('Chrome on macOS', $row->text());
    }

    public function test_a_user_sees_ask_your_administrator_when_push_is_not_set_up(): void
    {
        $client = $this->client(false);
        $client->loginUser($this->makeUser(static::getContainer(), 'page-off-user@example.com'));

        $crawler = $client->request(Request::METHOD_GET, self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ask your administrator', $crawler->text());
        self::assertSelectorNotExists('[data-controller~="push-device"]');
        self::assertSelectorNotExists('[data-action="push-device#turnOn"]');
        self::assertSelectorNotExists('[data-testid="push-docs-link"]');
        self::assertSelectorExists('[data-testid="push-unavailable"]');
        self::assertStringNotContainsString($this->privateKey, (string) $client->getResponse()->getContent());
    }

    public function test_an_admin_gets_the_reason_and_a_link_to_the_docs_when_push_is_not_set_up(): void
    {
        $client = $this->client(false);
        $admin = $this->makeUser(static::getContainer(), 'page-off-admin@example.com');
        $admin->roles = ['ROLE_ADMIN'];
        static::getContainer()->get('doctrine.orm.entity_manager')->flush();
        $client->loginUser($admin);

        $crawler = $client->request(Request::METHOD_GET, self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Ask your administrator', $crawler->text());
        self::assertStringContainsString('switch on the notifications.web_push.enabled flag', $crawler->text());
        $link = $crawler->filter('[data-testid="push-docs-link"]');
        self::assertCount(1, $link);
        self::assertStringEndsWith('/operating/', (string) $link->attr('href'));
        self::assertSelectorNotExists('[data-controller~="push-device"]');
    }

    public function test_a_device_row_still_shows_when_push_is_not_set_up(): void
    {
        $client = $this->client(false);
        $user = $this->makeUser(static::getContainer(), 'page-off-rows@example.com');
        $device = $this->makeDevice(static::getContainer(), $user);
        $client->loginUser($user);

        $crawler = $client->request(Request::METHOD_GET, self::PAGE);

        self::assertCount(1, $crawler->filter('[data-device-id="'.$device->id.'"]'));
    }

    public function test_the_settings_nav_lists_notifications_after_profile_and_marks_it_active(): void
    {
        $client = $this->client(true);
        $client->loginUser($this->makeUser(static::getContainer(), 'page-nav@example.com'));

        $crawler = $client->request(Request::METHOD_GET, self::PAGE);

        $items = $crawler->filter('.lp-settings-nav__item');
        self::assertGreaterThanOrEqual(2, $items->count());
        self::assertStringContainsString('Profile', $items->eq(0)->text());
        self::assertStringContainsString('Notifications', $items->eq(1)->text());
        self::assertSame(self::PAGE, $items->eq(1)->attr('href'));
        self::assertSame('page', $items->eq(1)->attr('aria-current'));
        self::assertCount(1, $crawler->filter('.lp-settings-nav__item[aria-current="page"]'));
    }

    public function test_the_account_item_of_the_sidebar_is_active_on_the_page(): void
    {
        $client = $this->client(true);
        $client->loginUser($this->makeUser(static::getContainer(), 'page-sidebar@example.com'));

        $crawler = $client->request(Request::METHOD_GET, self::PAGE);

        self::assertCount(1, $crawler->filter('.lp-sidebar__nav--secondary .lp-sidebar__link--active[href="/account/profile"]'));
    }

    public function test_the_sign_out_form_unsubscribes_this_browser_first(): void
    {
        $client = $this->client(true);
        $client->loginUser($this->makeUser(static::getContainer(), 'page-logout@example.com'));

        $crawler = $client->request(Request::METHOD_GET, self::PAGE);

        $form = $crawler->filter('form[action="/logout"]');
        self::assertCount(1, $form);
        self::assertStringContainsString('push-unsubscribe', (string) $form->attr('data-controller'));
        self::assertStringContainsString('submit->push-unsubscribe#unsubscribe', (string) $form->attr('data-action'));
    }

    private function client(bool $available): KernelBrowser
    {
        $keys = VAPID::createVapidKeys();
        $this->publicKey = $keys['publicKey'];
        $this->privateKey = $keys['privateKey'];
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set(PushAvailability::class, new PushAvailability(
            FeatureFlags::service([PushAvailability::FLAG => $available]),
            $this->publicKey,
            $this->privateKey,
        ));

        return $client;
    }
}
