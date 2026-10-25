<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Controller;

use App\Module\Notification\Entity\NotificationPreference;
use App\Module\Notification\NotificationChannel;
use App\Module\Notification\NotificationEvent;
use App\Module\Notification\Repository\NotificationPreferenceRepository;
use App\Module\Notification\Service\PushAvailability;
use App\Tests\Module\Notification\NotificationScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

final class NotificationPreferencesControllerTest extends WebTestCase
{
    use NotificationScenario;

    private const string PAGE = '/account/notifications';

    private const string PREFERENCES = '/account/notifications/preferences';

    private const string FORM = 'change_notification_preferences_form';

    private const array DEFAULTS_SHOWN = ['blocking-inbox-item__web-push' => '1', 'other-inbox-item__web-push' => '0'];

    public function test_the_grid_shows_one_row_per_event_with_the_defaults(): void
    {
        $client = $this->client();
        $client->loginUser($this->makeUser(static::getContainer(), 'grid-defaults@example.com'));

        $crawler = $client->request(Request::METHOD_GET, self::PAGE);

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-testid="notification-preference-row"]');
        self::assertCount(2, $rows);
        self::assertStringContainsString('Blocking inbox item', $rows->eq(0)->text());
        self::assertStringContainsString('Other inbox item', $rows->eq(1)->text());
        self::assertSelectorTextContains('.lp-pref-grid thead', 'Web push');
        self::assertNotNull($this->switchOf($crawler, 'blocking-inbox-item')->attr('checked'));
        self::assertNull($this->switchOf($crawler, 'other-inbox-item')->attr('checked'));
        self::assertNull($this->switchOf($crawler, 'blocking-inbox-item')->attr('disabled'));
        self::assertCount(1, $crawler->filter('form[action="'.self::PREFERENCES.'"][data-controller="submit-on-change"]'));
        self::assertCount(2, $crawler->filter('.lp-pref-grid input.lp-switch[role="switch"][aria-label]'));
    }

    public function test_the_grid_shows_the_stored_choices_of_this_user_alone(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'grid-stored@example.com');
        $other = $this->makeUser(static::getContainer(), 'grid-stored-other@example.com');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new NotificationPreference($user, NotificationEvent::BlockingInboxItem, NotificationChannel::WebPush, false));
        $em->persist(new NotificationPreference($user, NotificationEvent::OtherInboxItem, NotificationChannel::WebPush, true));
        $em->persist(new NotificationPreference($other, NotificationEvent::OtherInboxItem, NotificationChannel::WebPush, false));
        $em->flush();
        $client->loginUser($user);

        $crawler = $client->request(Request::METHOD_GET, self::PAGE);

        self::assertNull($this->switchOf($crawler, 'blocking-inbox-item')->attr('checked'));
        self::assertNotNull($this->switchOf($crawler, 'other-inbox-item')->attr('checked'));
    }

    public function test_an_instance_without_push_greys_the_grid_out_and_says_why(): void
    {
        $client = $this->client(false);
        $client->loginUser($this->makeUser(static::getContainer(), 'grid-off@example.com'));

        $crawler = $client->request(Request::METHOD_GET, self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="push-unavailable"]', 'Push is not set up on this server. Ask your administrator.');
        self::assertNotNull($this->switchOf($crawler, 'blocking-inbox-item')->attr('disabled'));
        self::assertNotNull($this->switchOf($crawler, 'other-inbox-item')->attr('disabled'));
    }

    public function test_the_owner_turns_a_row_on_and_the_row_is_stored(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'save-on@example.com');
        $client->loginUser($user);

        $this->save($client, ['blocking-inbox-item__web-push' => '1', 'other-inbox-item__web-push' => '1']);

        self::assertResponseRedirects(self::PAGE.'#notification-preferences');
        self::assertContains('Your notification choices are saved.', self::flashes($client)['success'] ?? []);
        $rows = static::getContainer()->get(NotificationPreferenceRepository::class)->findByOwner($user);
        self::assertCount(1, $rows);
        self::assertSame(NotificationEvent::OtherInboxItem, $rows[0]->event);
        self::assertTrue($rows[0]->enabled);
    }

    public function test_an_unchecked_cell_turns_a_default_on_row_off(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'save-off@example.com');
        $client->loginUser($user);

        $this->save($client, []);

        self::assertResponseRedirects(self::PAGE.'#notification-preferences');
        $rows = static::getContainer()->get(NotificationPreferenceRepository::class)->findByOwner($user);
        self::assertCount(1, $rows);
        self::assertSame(NotificationEvent::BlockingInboxItem, $rows[0]->event);
        self::assertFalse($rows[0]->enabled);
    }

    public function test_the_grid_renders_what_it_showed_beside_each_switch(): void
    {
        $client = $this->client();
        $client->loginUser($this->makeUser(static::getContainer(), 'grid-shown@example.com'));

        $crawler = $client->request(Request::METHOD_GET, self::PAGE);

        self::assertSame('1', $crawler->filter('input[type="hidden"][name="'.self::FORM.'[shown][blocking-inbox-item__web-push]"]')->attr('value'));
        self::assertSame('0', $crawler->filter('input[type="hidden"][name="'.self::FORM.'[shown][other-inbox-item__web-push]"]')->attr('value'));
    }

    public function test_a_page_from_an_older_tab_does_not_undo_a_later_choice(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'save-stale@example.com');
        $client->loginUser($user);

        $this->save($client, []);
        $this->save($client, ['blocking-inbox-item__web-push' => '1', 'other-inbox-item__web-push' => '1']);

        $repository = static::getContainer()->get(NotificationPreferenceRepository::class);
        $cells = [];
        foreach ($repository->findByOwner($user) as $row) {
            $cells[$row->event->value] = $row->enabled;
        }
        ksort($cells);
        self::assertSame(['blocking-inbox-item' => false, 'other-inbox-item' => true], $cells);
    }

    public function test_a_submission_cannot_change_the_choices_of_another_user(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'save-mine@example.com');
        $other = $this->makeUser(static::getContainer(), 'save-theirs@example.com');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new NotificationPreference($other, NotificationEvent::BlockingInboxItem, NotificationChannel::WebPush, false));
        $em->flush();
        $client->loginUser($user);

        $this->save($client, ['other-inbox-item__web-push' => '1', 'blocking-inbox-item__web-push' => '1']);

        $repository = static::getContainer()->get(NotificationPreferenceRepository::class);
        $theirs = $repository->findByOwner($other);
        self::assertCount(1, $theirs);
        self::assertFalse($theirs[0]->enabled);
        self::assertCount(1, $repository->findByOwner($user));
    }

    public function test_a_submission_without_a_csrf_token_changes_nothing(): void
    {
        $client = $this->client();
        $client->loginUser($this->makeUser(static::getContainer(), 'save-csrf@example.com'));

        $client->request(Request::METHOD_POST, self::PREFERENCES, [self::FORM => ['choices' => ['other-inbox-item__web-push' => '1']]], [], ['HTTP_REFERER' => 'http://localhost'.self::PAGE]);

        self::assertResponseRedirects(self::PAGE.'#notification-preferences');
        self::assertSame(0, static::getContainer()->get(NotificationPreferenceRepository::class)->count([]));
        self::assertNotEmpty(self::flashes($client)['error'] ?? []);
    }

    public function test_an_instance_without_push_refuses_a_stale_page(): void
    {
        $client = $this->client(false);
        $client->loginUser($this->makeUser(static::getContainer(), 'save-unavailable@example.com'));

        $this->save($client, ['other-inbox-item__web-push' => '1']);

        self::assertResponseRedirects(self::PAGE.'#notification-preferences');
        self::assertContains('Notifications are not available on this instance now.', self::flashes($client)['error'] ?? []);
        self::assertSame(0, static::getContainer()->get(NotificationPreferenceRepository::class)->count([]));
    }

    public function test_a_signed_out_visitor_cannot_save(): void
    {
        $client = $this->client();

        $this->save($client, ['other-inbox-item__web-push' => '1']);

        self::assertResponseRedirects('/login');
        self::assertSame(0, static::getContainer()->get(NotificationPreferenceRepository::class)->count([]));
    }

    private function client(bool $available = true): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set(PushAvailability::class, self::availability($available));

        return $client;
    }

    /**
     * @param array<string, string> $cells
     * @param array<string, string> $shown what the page showed, the defaults unless given
     */
    private function save(KernelBrowser $client, array $cells, array $shown = self::DEFAULTS_SHOWN): void
    {
        $client->request(
            Request::METHOD_POST,
            self::PREFERENCES,
            [self::FORM => ['choices' => $cells, 'shown' => $shown, '_token' => 'csrf-token']],
            [],
            ['HTTP_REFERER' => 'http://localhost'.self::PAGE],
        );
    }

    private function switchOf(Crawler $crawler, string $event): Crawler
    {
        return $crawler->filter('input[name="'.self::FORM.'[choices]['.$event.'__web-push]"]');
    }

    /** @return array<string, list<string>> */
    private static function flashes(KernelBrowser $client): array
    {
        $session = $client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);

        return $session->getFlashBag()->peekAll();
    }
}
