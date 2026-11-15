<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Entity\ApiKeyExpiry;
use App\Tests\Module\Inbox\InboxScenario;
use App\Tests\Support\BillingScenario;
use App\Tests\Support\MercureCookies;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Each account page keeps one region live, and a fragment route answers it. The region sits in the page
 * and in the fragment with the same markup, the page hears the account topic of its user, and the
 * fragment of a signed-out viewer answers 401 with the cause.
 */
final class AccountLiveRegionsTest extends WebTestCase
{
    use InboxScenario;
    use MercureCookies;

    private const array LIVE = ['HTTP_X_LOUPE_LIVE_REFRESH' => '1'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->setInboxFlag(true);
    }

    /** @return iterable<string, array{string, string, string, ?string}> */
    public static function regions(): iterable
    {
        yield 'data exports' => ['/account/data', 'account-exports', '/account/data/frame', 'exports'];
        yield 'billing settings' => ['/account/billing', 'billing-subscribe', '/account/billing/frame', 'billing'];
        yield 'billing return' => ['/billing/checkout/success', 'billing-checkout-status', '/billing/checkout/success/frame', 'billing'];
        yield 'api keys' => ['/account/api-keys', 'api-keys-list', '/account/api-keys/frame', 'api_keys'];
        yield 'connected apps' => ['/account/connected-apps', 'connected-apps-list', '/account/connected-apps/frame', 'apps'];
        yield 'account inbox' => ['/account/inbox', 'account-inbox-list', '/account/inbox/frame', null];
    }

    #[DataProvider('regions')]
    public function test_the_page_declares_its_region_and_hears_the_topic_and_the_fragment_matches(string $page, string $frameId, string $fragment, ?string $area): void
    {
        $user = $this->signedUpUser($this->em, 'regions');
        $this->client->loginUser($user);

        $crawler = $this->client->request(Request::METHOD_GET, $page);
        self::assertResponseIsSuccessful();
        $topics = self::subscribedTopics($this->client->getResponse()) ?? [];
        $inPage = $crawler->filter('turbo-frame#'.$frameId);

        self::assertCount(1, $inPage);
        self::assertSame('live-region', $inPage->attr('data-controller'));
        self::assertSame('frame', $inPage->attr('data-live-region-mode-value'));
        self::assertSame($fragment, $inPage->attr('data-live-region-url-value'));
        self::assertSame('morph', $inPage->attr('refresh'));
        self::assertNotNull($inPage->attr('data-live-content'));
        if (null === $area) {
            self::assertSame('["inbox.open_count_changed"]', $inPage->attr('data-live-region-types-value'));
            self::assertContains($this->topics()->forInbox($this->id($user)), $topics);
        } else {
            self::assertSame('["account.changed"]', $inPage->attr('data-live-region-types-value'));
            self::assertSame(json_encode(['area' => $area]), $inPage->attr('data-live-region-match-value'));
            self::assertContains($this->topics()->forAccount($this->id($user)), $topics);
        }

        $fragmentPage = $this->client->request(Request::METHOD_GET, $fragment, server: self::LIVE + ['HTTP_TURBO_FRAME' => $frameId]);

        self::assertResponseIsSuccessful();
        self::assertSame($inPage->outerHtml(), $fragmentPage->filter('turbo-frame#'.$frameId)->outerHtml());
        self::assertSame(1, $fragmentPage->filter('turbo-frame')->count(), 'the fragment holds the region alone');
        self::assertNull(self::subscribedTopics($this->client->getResponse()), 'a frame response replaces the cookie of its host page');
    }

    #[DataProvider('regions')]
    public function test_a_signed_out_live_refresh_of_the_fragment_gets_401_with_the_cause(string $page, string $frameId, string $fragment, ?string $area): void
    {
        $this->client->request(Request::METHOD_GET, $fragment, server: self::LIVE);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('X-Loupe-Live-Lost', 'signed-out');
    }

    #[DataProvider('regions')]
    public function test_a_signed_out_request_of_the_fragment_goes_to_the_login_page(string $page, string $frameId, string $fragment, ?string $area): void
    {
        $this->client->request(Request::METHOD_GET, $fragment);

        self::assertResponseRedirects('/login');
    }

    public function test_the_account_inbox_fragment_is_gone_while_the_inbox_is_off(): void
    {
        $this->client->loginUser($this->signedUpUser($this->em, 'inbox-off'));
        $this->setInboxFlag(false);

        $this->client->request(Request::METHOD_GET, '/account/inbox/frame', server: self::LIVE);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_account_inbox_fragment_lists_the_projects_of_the_viewer_only(): void
    {
        $viewer = $this->signedUpUser($this->em, 'inbox-viewer');
        $other = $this->signedUpUser($this->em, 'inbox-other');
        $mine = $this->inboxProject($this->em, $viewer);
        $theirs = $this->inboxProject($this->em, $other);
        foreach ([$mine, $theirs] as $project) {
            $this->askHolding($this->em, $project, [$this->todo($this->em, $project, 1)]);
        }
        $this->client->loginUser($viewer);

        $crawler = $this->client->request(Request::METHOD_GET, '/account/inbox/frame', server: self::LIVE);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-account-inbox-project]'));
        self::assertCount(1, $crawler->filter(\sprintf('[data-account-inbox-project="%s"]', $mine->id)));
    }

    public function test_the_api_keys_fragment_lists_the_keys_of_the_viewer_only(): void
    {
        $viewer = $this->signedUpUser($this->em, 'keys-viewer');
        $other = $this->signedUpUser($this->em, 'keys-other');
        $this->key($viewer, 'Mine');
        $this->key($other, 'Theirs');
        $this->client->loginUser($viewer);

        $crawler = $this->client->request(Request::METHOD_GET, '/account/api-keys/frame', server: self::LIVE);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-api-key]'));
        self::assertStringContainsString('Mine', $crawler->text());
        self::assertStringNotContainsString('Theirs', $crawler->text());
    }

    public function test_the_create_dialog_stays_outside_the_api_keys_region(): void
    {
        $this->client->loginUser($this->signedUpUser($this->em, 'keys-dialog'));

        $crawler = $this->client->request(Request::METHOD_GET, '/account/api-keys');

        self::assertCount(1, $crawler->filter('dialog'));
        self::assertCount(0, $crawler->filter('turbo-frame#api-keys-list dialog'));
    }

    public function test_the_billing_fragment_url_keeps_the_invite_token_so_a_morph_keeps_the_hidden_field(): void
    {
        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();
        $user = $scenario->verifiedUser('invitee');
        $entry = new WaitlistEntry($user->email);
        $token = $entry->issueInviteToken();
        $this->em->persist($entry);
        $this->em->flush();
        $this->client->loginUser($user);

        $page = $this->client->request(Request::METHOD_GET, '/account/billing', ['invite' => $token]);
        $url = (string) $page->filter('turbo-frame#billing-subscribe')->attr('data-live-region-url-value');
        $fragment = $this->client->request(Request::METHOD_GET, $url, server: self::LIVE);

        self::assertSame('/account/billing/frame?invite='.$token, $url);
        self::assertSame($token, $page->filter('form[action="/billing/checkout"] input[name="invite"]')->attr('value'));
        self::assertSame($token, $fragment->filter('form[action="/billing/checkout"] input[name="invite"]')->attr('value'));
    }

    public function test_the_billing_return_page_shows_a_pending_status_until_a_subscription_is_in_force(): void
    {
        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();
        $user = $scenario->verifiedUser('returning');
        $scenario->profile($user, new \DateTimeImmutable('+3 days'));
        $this->client->loginUser($user);

        $crawler = $this->client->request(Request::METHOD_GET, '/billing/checkout/success/frame', server: self::LIVE);

        self::assertResponseIsSuccessful();
        self::assertSame('Confirming', trim($crawler->filter('[data-testid="checkout-status"] .lp-billing-status')->text()));
    }

    public function test_the_profile_form_declares_a_band_region_and_its_fingerprint_route_answers_the_current_one(): void
    {
        $user = $this->signedUpUser($this->em, 'profile-band');
        $this->client->loginUser($user);

        $crawler = $this->client->request(Request::METHOD_GET, '/account/profile');
        $topics = self::subscribedTopics($this->client->getResponse()) ?? [];
        $region = $crawler->filter('[data-live-region-mode-value="band"]');
        $fingerprint = (string) $region->attr('data-live-fingerprint');

        self::assertCount(1, $region);
        self::assertSame('["account.changed"]', $region->attr('data-live-region-types-value'));
        self::assertSame(json_encode(['area' => 'profile']), $region->attr('data-live-region-match-value'));
        self::assertContains($this->topics()->forAccount($this->id($user)), $topics);
        $band = $region->filter('[data-live-region-target="band"]');
        self::assertCount(1, $band);
        self::assertNotNull($band->attr('hidden'));
        self::assertSame('_blank', $band->filter('a')->attr('target'));
        self::assertStringContainsString('noopener', (string) $band->filter('a')->attr('rel'));
        self::assertCount(0, $band->filter('form'), 'the band holds no form, so the form is never morphed');
        self::assertCount(1, $region->filter('form'));

        $this->client->request(Request::METHOD_GET, '/account/profile/fingerprint', server: self::LIVE);
        self::assertResponseIsSuccessful();
        self::assertSame($fingerprint, $this->client->getResponse()->getContent());

        $this->em->getConnection()->executeStatement('UPDATE users SET full_name = :name WHERE id = :id', ['name' => 'Changed Elsewhere', 'id' => (string) $user->id]);
        $this->em->clear();
        $this->client->request(Request::METHOD_GET, '/account/profile/fingerprint', server: self::LIVE);
        self::assertNotSame($fingerprint, $this->client->getResponse()->getContent());
    }

    public function test_a_signed_out_live_refresh_of_the_profile_fingerprint_gets_401(): void
    {
        $this->client->request(Request::METHOD_GET, '/account/profile/fingerprint', server: self::LIVE);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('X-Loupe-Live-Lost', 'signed-out');
    }

    public function test_an_invalid_profile_submit_keeps_the_region_with_the_stored_fingerprint(): void
    {
        $user = $this->signedUpUser($this->em, 'profile-invalid');
        $this->client->loginUser($user);
        $crawler = $this->client->request(Request::METHOD_GET, '/account/profile');
        $stored = (string) $crawler->filter('[data-live-region-mode-value="band"]')->attr('data-live-fingerprint');

        $crawler = $this->client->submitForm('Save', ['profile_form[fullName]' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($stored, $crawler->filter('[data-live-region-mode-value="band"]')->attr('data-live-fingerprint'));
    }

    private function key(User $owner, string $name): ApiKey
    {
        $secret = ApiKey::PREFIX.bin2hex(random_bytes(32));
        $key = new ApiKey($owner, $name, ApiKey::hashOf($secret), ApiKey::displayStartOf($secret), ApiKeyExpiry::Never->expiresAt(new \DateTimeImmutable()), new \DateTimeImmutable());
        $this->em->persist($key);
        $this->em->flush();

        return $key;
    }

    private function id(User $user): \Symfony\Component\Uid\Uuid
    {
        return $user->id ?? throw new \LogicException('A flushed user has an id.');
    }

    private function topics(): UserTopicBuilder
    {
        $topics = static::getContainer()->get(UserTopicBuilder::class);
        self::assertInstanceOf(UserTopicBuilder::class, $topics);

        return $topics;
    }
}
