<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Notification\Entity\PushDevice;
use App\Tests\Module\Inbox\InboxScenario;
use App\Tests\Module\Notification\NotificationScenario;
use App\Tests\Support\MercureCookies;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** Both inbox pages carry the stopped device warning above their content, outside the inbox-view frame. */
final class StoppedDeviceWarningOnInboxPagesTest extends WebTestCase
{
    use InboxScenario;
    use NotificationScenario;
    use MercureCookies;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    /** @return iterable<string, array{string}> */
    public static function pages(): iterable
    {
        yield 'the project inbox' => ['project'];
        yield 'the account inbox' => ['account'];
    }

    #[DataProvider('pages')]
    public function test_a_stopped_device_shows_the_warning_with_a_dismiss_that_returns_to_the_page(string $page): void
    {
        [$owner, $path] = $this->signedIn($page);
        $device = $this->stopped($owner);

        $crawler = $this->client->request(Request::METHOD_GET, $path);

        self::assertResponseIsSuccessful();
        $warning = $crawler->filter('[data-controller~="live-pill"] turbo-frame#push-device-warning [data-device-id="'.$device->id.'"]');
        self::assertCount(1, $warning);
        self::assertStringContainsString('Your push device Chrome on macOS stopped working on 20 Oct 2026', $warning->text());
        self::assertSame($path, $warning->filter('form input[name="return"]')->attr('value'));
        self::assertCount(0, $crawler->filter('turbo-frame#inbox-view turbo-frame#push-device-warning'), 'the warning stays outside the inbox view');
    }

    #[DataProvider('pages')]
    public function test_the_warning_wrapper_reloads_the_fragment_on_a_device_change(string $page): void
    {
        [$owner, $path] = $this->signedIn($page);
        $this->stopped($owner);

        $crawler = $this->client->request(Request::METHOD_GET, $path);

        $live = $crawler->filter('[data-controller~="live-pill"][data-live-pill-url-value="/account/notifications/warning"]');
        self::assertCount(1, $live);
        self::assertSame('["notifications.devices_changed"]', $live->attr('data-live-pill-events-value'));
        self::assertSame('turbo:frame-missing->live-pill#keepPill', $live->attr('data-action'));
        self::assertNull($live->attr('data-live-pill-project-value'));
        self::assertCount(1, $live->filter('turbo-frame#push-device-warning[data-live-pill-target="frame"]'));
    }

    #[DataProvider('pages')]
    public function test_a_dismissed_device_shows_no_warning(string $page): void
    {
        [$owner, $path] = $this->signedIn($page);
        $device = $this->stopped($owner);
        $device->warningDismissedAt = new \DateTimeImmutable('2026-10-21 08:00:00');
        $this->em->flush();

        $crawler = $this->client->request(Request::METHOD_GET, $path);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#push-device-warning'));
        self::assertCount(0, $crawler->filter('turbo-frame#push-device-warning [data-device-id]'));
    }

    #[DataProvider('pages')]
    public function test_a_healthy_device_shows_no_warning(string $page): void
    {
        [$owner, $path] = $this->signedIn($page);
        $this->makeDevice(static::getContainer(), $owner);

        $crawler = $this->client->request(Request::METHOD_GET, $path);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('turbo-frame#push-device-warning [data-device-id]'));
    }

    #[DataProvider('pages')]
    public function test_the_page_subscribes_to_the_notifications_topic(string $page): void
    {
        [$owner, $path] = $this->signedIn($page);

        $crawler = $this->client->request(Request::METHOD_GET, $path);

        $topics = static::getContainer()->get(UserTopicBuilder::class);
        self::assertInstanceOf(UserTopicBuilder::class, $topics);
        $topic = $topics->forNotifications($owner->id ?? throw new \LogicException('The owner has no id.'));
        self::assertContains($topic, self::subscribedTopics($this->client->getResponse()) ?? []);
        self::assertContains($topic, $crawler->filter('form#mercure-subscriptions input[data-mercure-topic]')->each(static fn ($input): ?string => $input->attr('value')));
    }

    public function test_the_warning_of_another_user_is_out_of_reach(): void
    {
        [$owner, $path] = $this->signedIn('account');
        $other = $this->signedUpUser($this->em, 'warning-other');
        $this->stopped($other, 'https://web.push.apple.com/THEIRS', 'their-hash');
        $this->makeDevice(static::getContainer(), $owner);

        $crawler = $this->client->request(Request::METHOD_GET, $path);

        self::assertCount(0, $crawler->filter('turbo-frame#push-device-warning [data-device-id]'));
    }

    /** @return array{User, string} */
    private function signedIn(string $page): array
    {
        $owner = $this->signedUpUser($this->em, 'inbox-warning');
        $project = $this->inboxProject($this->em, $owner);
        $this->question($this->em, $project, 1);
        $this->setInboxFlag(true);
        $this->client->loginUser($owner);

        return [$owner, 'project' === $page ? '/projects/'.$project->id.'/inbox' : '/account/inbox'];
    }

    private function stopped(User $owner, string $endpoint = self::ENDPOINT, string $hash = 'hash-of-token'): PushDevice
    {
        $device = $this->makeDevice(static::getContainer(), $owner, $endpoint, $hash);
        $device->stoppedAt = new \DateTimeImmutable('2026-10-20 08:00:00');
        $this->em->flush();

        return $device;
    }
}
