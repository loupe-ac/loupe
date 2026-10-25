<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Controller;

use App\Module\Notification\Service\DeviceCookie;
use App\Module\Notification\Service\PushAvailability;
use App\Tests\Module\Notification\NotificationScenario;
use App\Tests\Support\FeatureFlags;
use App\Tests\Support\MercureCookies;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\VAPID;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\HttpFoundation\Request;

final class ShowPushDeviceListControllerTest extends WebTestCase
{
    use NotificationScenario;
    use MercureCookies;

    private const string PAGE = '/account/notifications';

    private const string FRAGMENT = '/account/notifications/device-list';

    public function test_the_fragment_needs_a_signed_in_user(): void
    {
        $client = $this->client(true);

        $client->request(Request::METHOD_GET, self::FRAGMENT);

        self::assertResponseRedirects('/login');
    }

    public function test_the_fragment_is_the_frame_the_page_renders_and_sets_no_subscription(): void
    {
        $client = $this->client(true);
        $user = $this->makeUser(static::getContainer(), 'fragment-same@example.com');
        $token = new DeviceCookie()->generate();
        $stopped = $this->makeDevice(static::getContainer(), $user, 'https://web.push.apple.com/STOPPED', DeviceCookie::hash($token));
        $stopped->stoppedAt = new \DateTimeImmutable('2026-10-20 08:00:00');
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->makeDevice(static::getContainer(), $user, 'https://web.push.apple.com/HEALTHY', 'healthy-hash');
        $client->loginUser($user);
        $client->getCookieJar()->set(new BrowserCookie(DeviceCookie::NAME, $token));

        $page = $client->request(Request::METHOD_GET, self::PAGE);
        $inPage = $page->filter('turbo-frame#push-devices');
        self::assertCount(1, $inPage);
        self::assertCount(2, $inPage->filter('[data-device-id]'));

        $fragment = $client->request(Request::METHOD_GET, self::FRAGMENT);

        self::assertResponseIsSuccessful();
        $inFragment = $fragment->filter('turbo-frame#push-devices');
        self::assertCount(1, $inFragment);
        self::assertSame(self::withoutTokens($inPage->outerHtml()), self::withoutTokens($inFragment->outerHtml()));
        self::assertNull(self::subscribedTopics($client->getResponse()), 'a frame response replaces the cookie of its host page');
    }

    public function test_the_fragment_lists_only_the_devices_of_the_signed_in_user(): void
    {
        $client = $this->client(true);
        $user = $this->makeUser(static::getContainer(), 'fragment-mine@example.com');
        $other = $this->makeUser(static::getContainer(), 'fragment-other@example.com');
        $mine = $this->makeDevice(static::getContainer(), $user, 'https://web.push.apple.com/MINE', 'mine-hash');
        $theirs = $this->makeDevice(static::getContainer(), $other, 'https://web.push.apple.com/THEIRS', 'their-hash');
        $client->loginUser($user);

        $crawler = $client->request(Request::METHOD_GET, self::FRAGMENT);

        self::assertCount(1, $crawler->filter('[data-device-id="'.$mine->id.'"]'));
        self::assertCount(0, $crawler->filter('[data-device-id="'.$theirs->id.'"]'));
    }

    private static function withoutTokens(string $html): string
    {
        return (string) preg_replace('/(name="token"[^>]*value=")[^"]*"/', '$1"', $html);
    }

    private function client(bool $available): KernelBrowser
    {
        $keys = VAPID::createVapidKeys();
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set(PushAvailability::class, new PushAvailability(
            FeatureFlags::service([PushAvailability::FLAG => $available]),
            $keys['publicKey'],
            $keys['privateKey'],
        ));

        return $client;
    }
}
