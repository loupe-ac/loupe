<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller\Install;

use App\Module\Account\Entity\User;
use App\Module\Account\Service\InstallAccessGuard;
use App\Module\Account\Service\InstallationState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class UnlockControllerTest extends WebTestCase
{
    private const string TOKEN = 'secret-token';

    private function clientWithToken(string $token = self::TOKEN): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        self::getContainer()->set(
            InstallAccessGuard::class,
            new InstallAccessGuard(self::getContainer()->get(InstallationState::class), $token, 'test'),
        );

        return $client;
    }

    public function test_wizard_pages_redirect_to_the_form_while_locked(): void
    {
        $client = $this->clientWithToken();

        foreach (['/install', '/install/status', '/install/admin'] as $path) {
            $client->request(Request::METHOD_GET, $path);
            self::assertResponseRedirects('/install/unlock', null, $path);
        }
    }

    public function test_the_query_string_token_no_longer_unlocks(): void
    {
        $client = $this->clientWithToken();

        $client->request(Request::METHOD_GET, '/install?token='.self::TOKEN);

        self::assertResponseRedirects('/install/unlock');
    }

    public function test_shows_the_form_while_locked(): void
    {
        $client = $this->clientWithToken();

        $crawler = $client->request(Request::METHOD_GET, '/install/unlock');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('input[type="password"]')->count());
    }

    public function test_correct_token_unlocks_and_redirects_to_a_clean_url(): void
    {
        $client = $this->clientWithToken();
        $client->request(Request::METHOD_GET, '/install/unlock');

        $client->submitForm('Unlock', ['install_unlock_form[token]' => self::TOKEN]);

        self::assertResponseRedirects('/install');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function test_wrong_token_shows_the_form_again_with_an_error(): void
    {
        $client = $this->clientWithToken();
        $client->request(Request::METHOD_GET, '/install/unlock');

        $client->submitForm('Unlock', ['install_unlock_form[token]' => 'wrong']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'This token is not valid.');

        $client->request(Request::METHOD_GET, '/install');
        self::assertResponseRedirects('/install/unlock');
    }

    public function test_redirects_to_the_wizard_when_already_unlocked(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/install/unlock');

        self::assertResponseRedirects('/install');
    }

    public function test_404s_once_a_user_exists(): void
    {
        $client = $this->clientWithToken();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User(fullName: 'Existing User', email: 'existing@example.com');
        $user->password = 'irrelevant-hash';
        $em->persist($user);
        $em->flush();

        $client->request(Request::METHOD_GET, '/install/unlock');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_throttles_token_attempts_per_address(): void
    {
        $client = $this->clientWithToken();
        self::getContainer()->set('limiter.install_unlock', new RateLimiterFactory(
            ['id' => 'install_unlock', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '15 minutes'],
            new InMemoryStorage(),
        ));
        $client->request(Request::METHOD_GET, '/install/unlock');

        $client->submitForm('Unlock', ['install_unlock_form[token]' => 'wrong']);
        self::assertResponseStatusCodeSame(422);
        $client->submitForm('Unlock', ['install_unlock_form[token]' => 'wrong']);
        self::assertResponseStatusCodeSame(422);
        $client->submitForm('Unlock', ['install_unlock_form[token]' => self::TOKEN]);

        self::assertResponseStatusCodeSame(429);
        $client->request(Request::METHOD_GET, '/install');
        self::assertResponseRedirects('/install/unlock');
    }
}
