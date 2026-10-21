<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** The site host is `loupe.example`, and the app answers on `app.loupe.example`, a sub-domain of it. */
final class SignedInCookieFlowTest extends WebTestCase
{
    private const string COOKIE = 'loupe_signed_in';

    private string $previousSiteHost = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->previousSiteHost = (string) ($_SERVER['SITE_HOST'] ?? '');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->setSiteHost($this->previousSiteHost);
        parent::tearDown();
    }

    private function setSiteHost(string $host): void
    {
        $_ENV['SITE_HOST'] = $_SERVER['SITE_HOST'] = $host;
    }

    private function client(string $siteHost): KernelBrowser
    {
        $this->setSiteHost($siteHost);

        $client = static::createClient();
        if ('localhost' !== $siteHost && 'unrelated.example' !== $siteHost) {
            $client->setServerParameter('HTTP_HOST', 'app.'.$siteHost);
        }

        return $client;
    }

    private function createUser(bool $admin = false): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = new User('Login User', 'cookie@example.com');
        AcceptedTerms::stamp($user, static::getContainer());
        $user->password = $hasher->hashPassword($user, 'SecurePassword1!');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        if ($admin) {
            $user->roles = ['ROLE_ADMIN'];
        }
        $em->persist($user);
        $em->flush();
        $em->clear();
    }

    /** @param array<string, string|bool> $extra */
    private function signIn(KernelBrowser $client, array $extra = []): void
    {
        $client->request(Request::METHOD_GET, '/login');
        $client->submitForm('Sign in', ['email' => 'cookie@example.com', 'password' => 'SecurePassword1!'] + $extra);
    }

    private function cookie(KernelBrowser $client): ?Cookie
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if (self::COOKIE === $cookie->getName()) {
                return $cookie;
            }
        }

        return null;
    }

    public function test_signing_in_sets_the_cookie_for_the_site_host(): void
    {
        $client = $this->client('loupe.example');
        $this->createUser();

        $this->signIn($client);

        $cookie = $this->cookie($client);
        self::assertInstanceOf(Cookie::class, $cookie);
        self::assertSame('1', $cookie->getValue());
        self::assertSame('loupe.example', $cookie->getDomain());
        self::assertSame(0, $cookie->getExpiresTime());
        self::assertFalse($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
    }

    public function test_an_administrator_gets_the_admin_value(): void
    {
        $client = $this->client('loupe.example');
        $this->createUser(admin: true);

        $this->signIn($client);

        self::assertSame('admin', $this->cookie($client)?->getValue());
    }

    public function test_remember_me_gives_the_cookie_the_remember_me_lifetime(): void
    {
        $client = $this->client('loupe.example');
        $this->createUser();

        $this->signIn($client, ['_remember_me' => true]);

        $cookie = $this->cookie($client);
        self::assertInstanceOf(Cookie::class, $cookie);
        self::assertEqualsWithDelta(time() + 2592000, $cookie->getExpiresTime(), 10);
    }

    public function test_a_failed_sign_in_sets_no_cookie(): void
    {
        $client = $this->client('loupe.example');
        $this->createUser();

        $client->request(Request::METHOD_GET, '/login');
        $client->submitForm('Sign in', ['email' => 'cookie@example.com', 'password' => 'WrongPassword!']);

        self::assertNull($this->cookie($client));
    }

    public function test_signing_out_clears_the_cookie(): void
    {
        $client = $this->client('loupe.example');
        $this->createUser();
        $this->signIn($client);
        $client->followRedirects();
        $client->request(Request::METHOD_GET, '/');
        $client->followRedirects(false);

        $client->submit($client->getCrawler()->filter('form[action="/logout"]')->form());

        $cookie = $this->cookie($client);
        self::assertInstanceOf(Cookie::class, $cookie);
        self::assertNull($cookie->getValue());
        self::assertSame('loupe.example', $cookie->getDomain());
        self::assertLessThan(time(), $cookie->getExpiresTime());
    }

    public function test_the_sign_in_page_clears_a_stale_cookie(): void
    {
        $client = $this->client('loupe.example');
        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie(self::COOKIE, '1'));

        $client->request(Request::METHOD_GET, '/login');

        self::assertResponseIsSuccessful();
        $cookie = $this->cookie($client);
        self::assertInstanceOf(Cookie::class, $cookie);
        self::assertNull($cookie->getValue());
        self::assertSame('loupe.example', $cookie->getDomain());
    }

    public function test_the_sign_in_page_sets_nothing_without_a_stale_cookie(): void
    {
        $client = $this->client('loupe.example');

        $client->request(Request::METHOD_GET, '/login');

        self::assertNull($this->cookie($client));
    }

    public function test_a_site_host_that_is_not_a_parent_of_the_app_host_sets_no_cookie(): void
    {
        $client = $this->client('unrelated.example');
        $this->createUser();

        $this->signIn($client);

        self::assertNull($this->cookie($client));
    }

    public function test_an_unsplit_instance_sets_no_cookie(): void
    {
        $client = $this->client('localhost');
        $this->createUser();

        $this->signIn($client);

        self::assertNull($this->cookie($client));
    }
}
