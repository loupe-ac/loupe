<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Controller;

use App\Module\Account\Entity\User;
use App\Module\Notification\Entity\PushDevice;
use App\Tests\Module\Notification\NotificationScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

/**
 * No voter guards these routes. The handlers read the signed-in user's own
 * devices alone, so the tests below prove that another user's row is out of reach.
 */
final class PushDeviceWarningControllerTest extends WebTestCase
{
    use NotificationScenario;

    private const string WARNING = '/account/notifications/warning';

    private const string DEVICES = '/account/notifications/devices';

    private const string SETTINGS = '/account/notifications';

    public function test_the_warning_needs_a_signed_in_user(): void
    {
        $client = $this->client();

        $client->request(Request::METHOD_GET, self::WARNING);

        self::assertResponseRedirects('/login');
    }

    public function test_with_no_stopped_device_the_frame_is_empty(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'warning-empty@example.com');
        $this->makeDevice(static::getContainer(), $user);
        $client->loginUser($user);

        $crawler = $client->request(Request::METHOD_GET, self::WARNING);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#push-device-warning[data-live-pill-target="frame"]'));
        self::assertCount(0, $crawler->filter('[data-device-id]'));
        self::assertSame('', trim($crawler->filter('turbo-frame#push-device-warning')->text()));
    }

    public function test_a_stopped_device_shows_a_warning_with_the_two_actions(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'warning-one@example.com');
        $device = $this->stoppedDevice($user, '2026-10-20 08:00:00');
        $client->loginUser($user);

        $crawler = $client->request(Request::METHOD_GET, self::WARNING);

        self::assertResponseIsSuccessful();
        $warning = $crawler->filter('turbo-frame#push-device-warning [data-device-id="'.$device->id.'"]');
        self::assertCount(1, $warning);
        self::assertStringContainsString('lp-flash--warning', (string) $warning->attr('class'));
        self::assertStringContainsString('Your push device Chrome on macOS stopped working on 20 Oct 2026, so it gets no pushes.', $warning->text());
        self::assertSame(self::SETTINGS, $warning->filter('a')->attr('href'));
        self::assertSame('Open notification settings', trim($warning->filter('a')->text()));
        $form = $warning->filter('form');
        self::assertCount(1, $form);
        self::assertSame('post', strtolower((string) $form->attr('method')));
        self::assertSame(self::DEVICES.'/'.$device->id.'/dismiss-warning', $form->attr('action'));
        self::assertSame('Dismiss', trim($form->filter('button')->text()));
        self::assertNotSame('', (string) $form->filter('input[name="token"]')->attr('value'));
        self::assertCount(0, $warning->filter('.lp-flash__dismiss'), 'the client-side dismiss would hide the warning without storing it');
    }

    public function test_the_form_returns_to_the_page_the_same_origin_referer_names(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'warning-referer@example.com');
        $this->stoppedDevice($user, '2026-10-20 08:00:00');
        $client->loginUser($user);

        $crawler = $client->request(Request::METHOD_GET, self::WARNING, [], [], ['HTTP_REFERER' => 'http://localhost/projects/abc/board?tab=1']);

        self::assertSame('/projects/abc/board?tab=1', $crawler->filter('form input[name="return"]')->attr('value'));
    }

    #[DataProvider('foreignReferers')]
    public function test_a_referer_from_another_origin_gives_no_return_field(string $referer): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'warning-foreign@example.com');
        $this->stoppedDevice($user, '2026-10-20 08:00:00');
        $client->loginUser($user);

        $crawler = $client->request(Request::METHOD_GET, self::WARNING, [], [], ['HTTP_REFERER' => $referer]);

        // Guard: the form is there, so the missing field is about the referer.
        self::assertCount(1, $crawler->filter('form[action$="/dismiss-warning"]'));
        self::assertCount(0, $crawler->filter('input[name="return"]'));
    }

    /** @return iterable<string, array{string}> */
    public static function foreignReferers(): iterable
    {
        yield 'other host' => ['https://evil.example/projects'];
        yield 'other scheme' => ['https://localhost/projects'];
        yield 'other port' => ['http://localhost:8080/projects'];
        yield 'host as userinfo' => ['http://localhost@evil.example/projects'];
        yield 'not a url' => ['/projects'];
        yield 'protocol relative' => ['//evil.example/projects'];
        yield 'backslash path' => ['http://localhost/\\evil.example'];
    }

    public function test_no_referer_gives_no_return_field(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'warning-none@example.com');
        $this->stoppedDevice($user, '2026-10-20 08:00:00');
        $client->loginUser($user);

        $crawler = $client->request(Request::METHOD_GET, self::WARNING);

        self::assertCount(1, $crawler->filter('form[action$="/dismiss-warning"]'));
        self::assertCount(0, $crawler->filter('input[name="return"]'));
    }

    public function test_a_dismissed_device_stays_hidden(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'warning-dismissed@example.com');
        $device = $this->stoppedDevice($user, '2026-10-20 08:00:00');
        $device->warningDismissedAt = new \DateTimeImmutable('2026-10-21 08:00:00');
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($user);

        $crawler = $client->request(Request::METHOD_GET, self::WARNING);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-device-id]'));
    }

    public function test_another_users_device_is_never_shown(): void
    {
        $client = $this->client();
        $other = $this->makeUser(static::getContainer(), 'warning-theirs@example.com');
        $this->stoppedDevice($other, '2026-10-20 08:00:00', 'https://web.push.apple.com/THEIRS');
        $mine = $this->makeUser(static::getContainer(), 'warning-mine@example.com');
        $client->loginUser($mine);

        $crawler = $client->request(Request::METHOD_GET, self::WARNING);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-device-id]'));
    }

    public function test_the_oldest_stop_comes_first(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'warning-order@example.com');
        $later = $this->stoppedDevice($user, '2026-10-22 08:00:00', 'https://web.push.apple.com/LATER', 'hash-later');
        $earlier = $this->stoppedDevice($user, '2026-10-19 08:00:00', 'https://web.push.apple.com/EARLIER', 'hash-earlier');
        $client->loginUser($user);

        $crawler = $client->request(Request::METHOD_GET, self::WARNING);

        self::assertSame([(string) $earlier->id, (string) $later->id], $crawler->filter('[data-device-id]')->each(static fn (Crawler $node): string => (string) $node->attr('data-device-id')));
    }

    public function test_a_device_label_is_escaped(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'warning-escape@example.com');
        $device = $this->stoppedDevice($user, '2026-10-20 08:00:00');
        $device->label = '<img src=x onerror=alert(1)>';
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($user);

        $crawler = $client->request(Request::METHOD_GET, self::WARNING);

        self::assertCount(0, $crawler->filter('img'));
        self::assertStringContainsString('<img src=x onerror=alert(1)>', $crawler->filter('[data-device-id]')->text());
    }

    public function test_the_owner_dismisses_a_warning_and_lands_on_the_settings_page(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'dismiss-ok@example.com');
        $device = $this->stoppedDevice($user, '2026-10-20 08:00:00');
        $client->loginUser($user);

        $this->dismiss($client, $device);

        self::assertResponseRedirects(self::SETTINGS);
        self::assertNotNull($this->reload($device)->warningDismissedAt);
        self::assertNotNull($this->reload($device)->stoppedAt);
        $client->request(Request::METHOD_GET, self::WARNING);
        self::assertCount(0, $client->getCrawler()->filter('[data-device-id]'));
    }

    public function test_a_dismiss_returns_to_a_relative_path_the_form_names(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'dismiss-return@example.com');
        $device = $this->stoppedDevice($user, '2026-10-20 08:00:00');
        $client->loginUser($user);

        $this->dismiss($client, $device, ['return' => '/projects/abc/board']);

        self::assertResponseRedirects('/projects/abc/board');
        self::assertNotNull($this->reload($device)->warningDismissedAt);
    }

    #[DataProvider('unsafeReturns')]
    public function test_a_dismiss_never_redirects_off_site(string $return): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'dismiss-open-redirect@example.com');
        $device = $this->stoppedDevice($user, '2026-10-20 08:00:00');
        $client->loginUser($user);

        $this->dismiss($client, $device, ['return' => $return]);

        self::assertResponseRedirects(self::SETTINGS);
        // Guard: the dismiss ran, so the fallback is about the field and not a refused request.
        self::assertNotNull($this->reload($device)->warningDismissedAt);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeReturns(): iterable
    {
        yield 'protocol relative' => ['//evil.example'];
        yield 'backslash' => ['/\\evil.example'];
        yield 'absolute' => ['https://evil.example/'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'tab' => ["/\t/evil.example"];
        yield 'relative' => ['evil'];
        yield 'empty' => [''];
    }

    public function test_a_return_that_is_not_text_is_refused_and_changes_nothing(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'dismiss-array@example.com');
        $device = $this->stoppedDevice($user, '2026-10-20 08:00:00');
        $client->loginUser($user);

        $client->request(Request::METHOD_POST, self::DEVICES.'/'.$device->id.'/dismiss-warning', ['token' => 'csrf-token', 'return' => ['/a']], [], ['HTTP_REFERER' => 'http://localhost/account/notifications']);

        self::assertResponseStatusCodeSame(400);
        self::assertNull($this->reload($device)->warningDismissedAt);
    }

    public function test_a_dismiss_without_a_csrf_token_is_refused_and_changes_nothing(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'dismiss-csrf@example.com');
        $device = $this->stoppedDevice($user, '2026-10-20 08:00:00');
        $client->loginUser($user);

        $this->dismiss($client, $device, token: null);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->reload($device)->warningDismissedAt);
    }

    public function test_a_device_of_another_user_reads_as_missing_and_stays_untouched(): void
    {
        $client = $this->client();
        $owner = $this->makeUser(static::getContainer(), 'dismiss-theirs@example.com');
        $device = $this->stoppedDevice($owner, '2026-10-20 08:00:00');
        $client->loginUser($this->makeUser(static::getContainer(), 'dismiss-thief@example.com'));

        $this->dismiss($client, $device);

        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->reload($device)->warningDismissedAt);
    }

    public function test_a_device_that_still_works_is_left_unchanged(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'dismiss-working@example.com');
        $device = $this->makeDevice(static::getContainer(), $user);
        $client->loginUser($user);

        $this->dismiss($client, $device);

        self::assertResponseRedirects(self::SETTINGS);
        self::assertNull($this->reload($device)->warningDismissedAt);
        self::assertNull($this->reload($device)->stoppedAt);
    }

    public function test_a_dismiss_needs_a_signed_in_user(): void
    {
        $client = $this->client();
        $owner = $this->makeUser(static::getContainer(), 'dismiss-anonymous@example.com');
        $device = $this->stoppedDevice($owner, '2026-10-20 08:00:00');

        $this->dismiss($client, $device);

        self::assertResponseRedirects('/login');
        self::assertNull($this->reload($device)->warningDismissedAt);
    }

    public function test_an_id_that_is_not_a_uuid_reads_as_missing(): void
    {
        $client = $this->client();
        $client->loginUser($this->makeUser(static::getContainer(), 'dismiss-not-uuid@example.com'));

        $client->request(Request::METHOD_POST, self::DEVICES.'/not-a-uuid/dismiss-warning', ['token' => 'csrf-token'], [], ['HTTP_REFERER' => 'http://localhost/account/notifications']);

        self::assertResponseStatusCodeSame(404);
    }

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function stoppedDevice(User $user, string $stoppedAt, string $endpoint = self::ENDPOINT, string $hash = 'hash-of-token'): PushDevice
    {
        $device = $this->makeDevice(static::getContainer(), $user, $endpoint, $hash);
        $device->stoppedAt = new \DateTimeImmutable($stoppedAt);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $device;
    }

    private function reload(PushDevice $device): PushDevice
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(PushDevice::class, $device->id);
        self::assertInstanceOf(PushDevice::class, $fresh);

        return $fresh;
    }

    /** @param array<string, string> $extra */
    private function dismiss(KernelBrowser $client, PushDevice $device, array $extra = [], ?string $token = 'csrf-token'): void
    {
        $fields = null === $token ? $extra : ['token' => $token] + $extra;

        // The same-origin sentinel passes the CSRF check when the referer matches.
        $client->request(Request::METHOD_POST, self::DEVICES.'/'.$device->id.'/dismiss-warning', $fields, [], ['HTTP_REFERER' => 'http://localhost/account/notifications']);
    }
}
