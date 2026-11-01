<?php

declare(strict_types=1);

namespace App\Tests\Module\OpenRouter\Controller;

use App\Mercure\UserTopicBuilder;
use App\Module\OpenRouter\Entity\OpenRouterKeyState;
use App\Tests\Module\OpenRouter\OpenRouterScenario;
use App\Tests\Support\MercureCookies;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AccountOpenRouterControllerTest extends WebTestCase
{
    use OpenRouterScenario;
    use MercureCookies;

    private const string PAGE = '/account/openrouter';

    private const string SECRET = 'sk-or-v1-0123456789abcdef-b7e2';

    public function test_the_page_needs_a_signed_in_user(): void
    {
        $client = $this->client();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, self::PAGE);
        self::assertResponseRedirects('/login');
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, self::PAGE, ['open_router_key_form' => ['secret' => self::SECRET]]);
        self::assertResponseRedirects('/login');
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, self::PAGE.'/remove');
        self::assertResponseRedirects('/login');
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, self::PAGE.'/status');
        self::assertResponseRedirects('/login');
    }

    public function test_with_no_key_the_page_says_so_and_offers_the_form(): void
    {
        $client = $this->client();
        $client->loginUser($this->makeUser(static::getContainer(), 'page-empty'));

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="openrouter-key-empty"]'));
        self::assertCount(0, $crawler->filter('[data-testid="openrouter-key-row"]'));
        self::assertCount(1, $crawler->filter('[data-testid="openrouter-key-form"] input[type="password"]'));
        self::assertCount(1, $crawler->filter('.lp-settings-nav__item--active:contains("OpenRouter")'));
    }

    public function test_a_connected_key_shows_a_mask_and_that_no_cost_was_read_yet(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'page-connected');
        $this->makeKey(static::getContainer(), $user, 'sk-or-v1-the-whole-secret-a3f9');
        $client->loginUser($user);

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, self::PAGE);

        self::assertSame('sk-or-v1-…a3f9', trim($crawler->filter('[data-testid="openrouter-key-mask"]')->text()));
        self::assertStringContainsString('Connected', $crawler->filter('[data-testid="openrouter-key-row"]')->text());
        self::assertSame('No cost read yet', trim($crawler->filter('[data-testid="openrouter-key-last-read"]')->text()));
        self::assertCount(1, $crawler->filter('.lp-openrouter-replace:not(.open) [data-testid="openrouter-key-form"]'));
        self::assertSame('disclosure#toggle', $crawler->filter('[data-testid="openrouter-key-replace"]')->attr('data-action'));
        self::assertCount(1, $crawler->filter('[data-controller="modal"] [data-testid="openrouter-key-remove"][type="button"]'));
        $dialog = $crawler->filter('[data-testid="openrouter-remove-dialog"]');
        self::assertStringContainsString('Remove your OpenRouter key?', $dialog->text());
        self::assertSame(self::PAGE.'/remove', $dialog->filter('form')->attr('action'));
        self::assertStringNotContainsString('the-whole-secret', (string) $client->getResponse()->getContent());
    }

    public function test_the_last_cost_read_is_shown_when_there_is_one(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'page-read');
        $key = $this->makeKey(static::getContainer(), $user);
        $key->lastReadAt = new \DateTimeImmutable('-4 minutes');
        $this->em()->flush();
        $client->loginUser($user);

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, self::PAGE);

        self::assertStringContainsString('Last cost read', $crawler->filter('[data-testid="openrouter-key-last-read"]')->text());
        self::assertCount(1, $crawler->filter('[data-testid="openrouter-key-last-read"] time'));
    }

    public function test_a_refused_key_says_so_with_the_date_and_the_replace_button_leads(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'page-refused');
        $key = $this->makeKey(static::getContainer(), $user);
        $key->state = OpenRouterKeyState::Refused;
        $key->refusedSince = new \DateTimeImmutable('2026-10-30 21:40:00');
        $this->em()->flush();
        $client->loginUser($user);

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, self::PAGE);

        $row = $crawler->filter('[data-testid="openrouter-key-row"]');
        self::assertStringContainsString('Refused by OpenRouter', $row->text());
        self::assertStringContainsString('Since 2026-10-30 21:40. Runs since then show no cost.', $row->text());
        self::assertStringContainsString('lp-btn--primary', (string) $crawler->filter('[data-testid="openrouter-key-replace"]')->attr('class'));
    }

    public function test_replace_opens_the_form_and_cancel_closes_it(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'page-replace');
        $this->makeKey(static::getContainer(), $user);
        $client->loginUser($user);

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, self::PAGE.'?replace=1');

        self::assertCount(1, $crawler->filter('.lp-openrouter-replace.open [data-testid="openrouter-key-form"]'));
        self::assertSame('', (string) $crawler->filter('[data-testid="openrouter-key-form"] input[type="password"]')->attr('value'));
        self::assertSame('disclosure#toggle', $crawler->filter('[data-testid="openrouter-key-cancel"]')->attr('data-action'));
    }

    public function test_the_page_never_shows_the_key_of_another_user(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'page-mine');
        $this->makeKey(static::getContainer(), $this->makeUser(static::getContainer(), 'page-theirs'), 'sk-or-v1-theirs-9999');
        $client->loginUser($user);

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, self::PAGE);

        self::assertCount(1, $crawler->filter('[data-testid="openrouter-key-empty"]'));
        self::assertStringNotContainsString('9999', (string) $client->getResponse()->getContent());
    }

    public function test_the_page_subscribes_to_the_openrouter_topic_of_the_user_and_the_status_frame_does_not(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'page-topic');
        $this->makeKey(static::getContainer(), $user);
        $client->loginUser($user);

        $page = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, self::PAGE);
        $topics = self::subscribedTopics($client->getResponse());
        $inPage = $page->filter('turbo-frame#openrouter-key');
        $fragment = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, self::PAGE.'/status');

        self::assertResponseIsSuccessful();
        $builder = static::getContainer()->get(UserTopicBuilder::class);
        self::assertInstanceOf(UserTopicBuilder::class, $builder);
        self::assertContains($builder->forOpenRouter($user->id ?? throw new \LogicException('no id')), $topics ?? []);
        self::assertCount(1, $inPage);
        self::assertSame($inPage->outerHtml(), $fragment->filter('turbo-frame#openrouter-key')->outerHtml());
        self::assertNull(self::subscribedTopics($client->getResponse()), 'a frame response replaces the cookie of its host page');
    }

    public function test_a_working_key_is_saved_and_the_page_shows_its_mask(): void
    {
        $client = $this->client(200);
        $user = $this->makeUser(static::getContainer(), 'save-ok');
        $client->loginUser($user);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, self::PAGE);
        $client->submitForm('Save the key', ['open_router_key_form[secret]' => self::SECRET]);

        self::assertResponseRedirects(self::PAGE);
        $stored = $this->keys()->findOneBy(['user' => $user]);
        self::assertNotNull($stored);
        self::assertSame(self::SECRET, $stored->secret);
        $crawler = $client->followRedirect();
        self::assertSame('sk-or-v1-…b7e2', trim($crawler->filter('[data-testid="openrouter-key-mask"]')->text()));
    }

    #[DataProvider('refusals')]
    public function test_a_key_that_fails_the_test_is_not_stored_and_is_not_echoed_back(int|\Throwable $answer, string $message, bool $linksToManagementKeys): void
    {
        $client = $this->client($answer);
        $user = $this->makeUser(static::getContainer(), 'save-fail');
        $client->loginUser($user);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, self::PAGE);
        $crawler = $client->submitForm('Save the key', ['open_router_key_form[secret]' => self::SECRET]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, $crawler->filter('[data-testid="openrouter-key-form"]')->text());
        self::assertCount($linksToManagementKeys ? 1 : 0, $crawler->filter('a[href="https://openrouter.ai/settings/management-keys"]'));
        self::assertStringNotContainsString(self::SECRET, (string) $client->getResponse()->getContent());
        self::assertNull($this->keys()->findOneBy(['user' => $user]));
    }

    /** @return iterable<string, array{int|\Throwable, string, bool}> */
    public static function refusals(): iterable
    {
        yield 'not a management key' => [403, 'OpenRouter says this is not a management key.', true];
        yield 'refused' => [401, 'OpenRouter refused the key.', false];
        yield 'unreachable' => [new TransportException('timed out'), 'Loupe could not reach OpenRouter. Try again.', false];
    }

    public function test_a_blank_key_is_refused_without_a_call(): void
    {
        $client = $this->client(200);
        $user = $this->makeUser(static::getContainer(), 'save-blank');
        $client->loginUser($user);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, self::PAGE);
        $client->submitForm('Save the key', ['open_router_key_form[secret]' => '   ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->calls);
        self::assertNull($this->keys()->findOneBy(['user' => $user]));
    }

    public function test_remove_deletes_the_account_key_of_the_user_only(): void
    {
        $client = $this->client();
        $user = $this->makeUser(static::getContainer(), 'remove-mine');
        $other = $this->makeUser(static::getContainer(), 'remove-theirs');
        $this->makeKey(static::getContainer(), $user);
        $this->makeKey(static::getContainer(), $other);
        $client->loginUser($user);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, self::PAGE);
        $client->submitForm('Remove the key');

        self::assertResponseRedirects(self::PAGE);
        $this->em()->clear();
        self::assertNull($this->keys()->findOneBy(['user' => $user]));
        self::assertNotNull($this->keys()->findOneBy(['user' => $other]));
    }

    private int $calls = 0;

    private function client(int|\Throwable $answer = 200): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('openrouter.client', new MockHttpClient(function () use ($answer): MockResponse {
            ++$this->calls;
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return new MockResponse('{}', ['http_code' => $answer]);
        }, 'https://openrouter.ai/api/v1/'));

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
