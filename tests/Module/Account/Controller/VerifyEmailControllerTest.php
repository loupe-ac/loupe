<?php

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class VerifyEmailControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{User, string} the unverified user and their plain verification token
     */
    private function createUnverifiedUserWithToken(string $email = 'verify@example.com'): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User('Verify User', $email);
        AcceptedTerms::stamp($user, static::getContainer());
        $user->password = $hasher->hashPassword($user, 'SecurePassword1!');
        $token = $user->generateEmailVerificationToken();

        $em->persist($user);
        $em->flush();
        $em->clear();

        return [$user, $token];
    }

    private function startSessionWith(string $key, string $value): void
    {
        $session = static::getContainer()->get('session.factory')->createSession();
        $session->set($key, $value);
        $session->save();
        $this->client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    private function fetch(User $user): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fetched = $em->find(User::class, $user->id);
        self::assertNotNull($fetched);

        return $fetched;
    }

    private function confirm(string $token): void
    {
        $this->client->request(Request::METHOD_GET, '/register/verify?token='.$token);
        self::assertResponseIsSuccessful();
        $this->client->request(Request::METHOD_POST, '/register/verify', [
            'token' => $token,
            '_csrf_token' => 'csrf-token',
        ]);
    }

    private function assertSignedOut(): void
    {
        $this->client->request(Request::METHOD_GET, '/projects');
        self::assertResponseRedirects('/login');
    }

    public function test_the_link_shows_a_confirmation_page_and_changes_nothing(): void
    {
        [$user, $token] = $this->createUnverifiedUserWithToken();

        $crawler = $this->client->request(Request::METHOD_GET, '/register/verify?token='.$token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'verify@example.com');
        self::assertCount(1, $crawler->filter('form[action="/register/verify"] button[type="submit"]'));
        self::assertSame($token, $crawler->filter('input[name="token"]')->attr('value'));
        self::assertNotSame('', (string) $crawler->filter('input[name="_csrf_token"]')->attr('value'));

        $fetched = $this->fetch($user);
        self::assertFalse($fetched->isVerified());
        self::assertTrue($fetched->isEmailVerificationTokenValid($token));
        $this->assertSignedOut();
    }

    public function test_the_confirmation_verifies_and_burns_the_link(): void
    {
        [$user, $token] = $this->createUnverifiedUserWithToken();
        $this->startSessionWith('registration_email', 'verify@example.com');

        $this->confirm($token);

        self::assertResponseRedirects('/');
        $fetched = $this->fetch($user);
        self::assertTrue($fetched->isVerified());
        self::assertFalse($fetched->isEmailVerificationTokenValid($token));
    }

    public function test_the_browser_that_registered_is_signed_in(): void
    {
        [, $token] = $this->createUnverifiedUserWithToken();
        $this->startSessionWith('registration_email', 'verify@example.com');

        $this->confirm($token);

        $this->client->request(Request::METHOD_GET, '/projects');
        self::assertResponseStatusCodeSame(200);
    }

    public function test_a_browser_signed_in_to_the_same_account_stays_signed_in(): void
    {
        [$user, $token] = $this->createUnverifiedUserWithToken();
        $this->client->loginUser($this->fetch($user));

        $this->confirm($token);

        self::assertResponseRedirects('/');
        self::assertTrue($this->fetch($user)->isVerified());
        $this->client->request(Request::METHOD_GET, '/projects');
        self::assertResponseStatusCodeSame(200);
    }

    public function test_another_browser_confirms_without_a_sign_in_and_is_sent_to_the_sign_in_page(): void
    {
        [$user, $token] = $this->createUnverifiedUserWithToken();

        $this->confirm($token);

        self::assertResponseRedirects('/login');
        $fetched = $this->fetch($user);
        self::assertTrue($fetched->isVerified());
        self::assertFalse($fetched->isEmailVerificationTokenValid($token));
        $this->assertSignedOut();
        $this->client->request(Request::METHOD_GET, '/login');
        self::assertSelectorTextContains('body', 'Address confirmed. Please sign in.');
    }

    public function test_a_session_that_registered_another_address_is_not_signed_in(): void
    {
        [$user, $token] = $this->createUnverifiedUserWithToken();
        $this->startSessionWith('registration_email', 'someone-else@example.com');

        $this->confirm($token);

        self::assertResponseRedirects('/login');
        self::assertTrue($this->fetch($user)->isVerified());
        $this->assertSignedOut();
    }

    public function test_a_user_of_another_account_stays_in_it_and_goes_home(): void
    {
        [$user, $token] = $this->createUnverifiedUserWithToken();
        [$other] = $this->createUnverifiedUserWithToken('other@example.com');
        $other = $this->fetch($other);
        $other->emailVerifiedAt = new \DateTimeImmutable();
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($other);

        $this->confirm($token);

        self::assertResponseRedirects('/');
        self::assertTrue($this->fetch($user)->isVerified());
        $this->client->request(Request::METHOD_GET, '/account/profile');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'other@example.com');
    }

    public function test_a_stale_registration_session_does_not_replace_another_signed_in_account(): void
    {
        [$user, $token] = $this->createUnverifiedUserWithToken();
        [$other] = $this->createUnverifiedUserWithToken('other@example.com');
        $other = $this->fetch($other);
        $other->emailVerifiedAt = new \DateTimeImmutable();
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->startSessionWith('registration_email', 'verify@example.com');
        $this->client->loginUser($other);

        $this->confirm($token);

        self::assertResponseRedirects('/');
        self::assertTrue($this->fetch($user)->isVerified());
        $this->client->request(Request::METHOD_GET, '/account/profile');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'other@example.com');
    }

    public function test_a_post_without_a_csrf_token_does_not_verify(): void
    {
        [$user, $token] = $this->createUnverifiedUserWithToken();
        $this->startSessionWith('registration_email', 'verify@example.com');

        $this->client->request(Request::METHOD_POST, '/register/verify', ['token' => $token]);

        // A guest who fails the check goes to the sign-in page, as for any denied request.
        self::assertResponseRedirects('http://localhost/login');
        $fetched = $this->fetch($user);
        self::assertFalse($fetched->isVerified());
        self::assertTrue($fetched->isEmailVerificationTokenValid($token));
    }

    public function test_a_post_with_a_wrong_token_redirects_to_check_email_and_does_not_verify(): void
    {
        [$user, $token] = $this->createUnverifiedUserWithToken();
        $this->client->request(Request::METHOD_GET, '/register/verify?token='.$token);

        $this->client->request(Request::METHOD_POST, '/register/verify', [
            'token' => 'not-the-token',
            '_csrf_token' => 'csrf-token',
        ]);

        self::assertResponseRedirects('/register/check-email');
        self::assertFalse($this->fetch($user)->isVerified());
    }

    public function test_invalid_token_redirects_to_check_email_and_does_not_verify(): void
    {
        [$user] = $this->createUnverifiedUserWithToken();

        $this->client->request(Request::METHOD_GET, '/register/verify?token=not-the-token');

        self::assertResponseRedirects('/register/check-email');
        self::assertFalse($this->fetch($user)->isVerified());
    }

    public function test_missing_token_redirects_to_check_email(): void
    {
        $this->client->request(Request::METHOD_GET, '/register/verify');

        self::assertResponseRedirects('/register/check-email');
    }
}
