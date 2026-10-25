<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Controller;

use App\Module\Account\Entity\User;
use App\Module\Notification\Contract\OpenPushTags;
use App\Module\Notification\Service\PresenceStore;
use App\Tests\Module\Notification\NotificationScenario;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class PresenceControllerTest extends WebTestCase
{
    use NotificationScenario;

    private const string PRESENCE = '/account/presence';

    private const string OPEN_TAGS = '/account/notifications/open-tags';

    public function test_a_post_records_the_presence_of_the_signed_in_user(): void
    {
        $client = static::createClient();
        $user = $this->makeUser(static::getContainer(), 'presence-ok@example.com');
        $other = $this->makeUser(static::getContainer(), 'presence-other@example.com');
        $client->loginUser($user);
        $store = static::getContainer()->get(PresenceStore::class);
        self::assertFalse($store->isPresent($user));

        $this->post($client);

        self::assertResponseStatusCodeSame(204);
        self::assertTrue($store->isPresent($user));
        self::assertFalse($store->isPresent($other), 'presence belongs to the user who posted');
    }

    public function test_a_post_without_a_session_is_sent_to_the_sign_in_page(): void
    {
        $client = static::createClient();

        $this->post($client);

        self::assertResponseRedirects('/login');
    }

    public function test_a_post_without_a_csrf_token_is_refused(): void
    {
        $client = static::createClient();
        $user = $this->makeUser(static::getContainer(), 'presence-csrf@example.com');
        $client->loginUser($user);

        $this->post($client, token: null);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse(static::getContainer()->get(PresenceStore::class)->isPresent($user));
    }

    public function test_a_get_is_not_allowed(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser(static::getContainer(), 'presence-get@example.com'));

        $client->request(Request::METHOD_GET, self::PRESENCE);

        self::assertResponseStatusCodeSame(405);
    }

    public function test_presence_posts_are_rate_limited_per_user(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.notification_presence', new RateLimiterFactory(
            ['id' => 'notification_presence', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $client->loginUser($this->makeUser(static::getContainer(), 'presence-limit@example.com'));

        $this->post($client);
        self::assertResponseStatusCodeSame(204);

        $this->post($client);
        self::assertResponseStatusCodeSame(429);
    }

    public function test_open_tags_lists_the_tags_the_source_gives_for_the_signed_in_user(): void
    {
        $client = static::createClient();
        $user = $this->makeUser(static::getContainer(), 'tags-ok@example.com');
        $source = new class implements OpenPushTags {
            /** @var list<User> */
            public array $seen = [];

            #[\Override]
            public function forUser(User $user): array
            {
                $this->seen[] = $user;

                return ['ask-1', 'item-2'];
            }
        };
        static::getContainer()->set(OpenPushTags::class, $source);
        $client->loginUser($user);

        $client->request(Request::METHOD_GET, self::OPEN_TAGS);

        self::assertResponseIsSuccessful();
        self::assertSame(['tags' => ['ask-1', 'item-2']], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertSame([$user->id], array_map(static fn (User $seen): mixed => $seen->id, $source->seen));
    }

    public function test_open_tags_needs_a_signed_in_user(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, self::OPEN_TAGS);

        self::assertResponseRedirects('/login');
    }

    private function post(KernelBrowser $client, ?string $token = 'csrf-token'): void
    {
        $client->request(Request::METHOD_POST, self::PRESENCE, null === $token ? [] : ['_csrf_token' => $token], [], ['HTTP_REFERER' => 'http://localhost/projects']);
    }
}
