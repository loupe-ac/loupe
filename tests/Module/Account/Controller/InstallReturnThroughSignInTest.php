<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\SocialProvider;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\InstalledInstance;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\OAuth2ClientInterface;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

/**
 * The return from github.com carries its query on the setup URL. A person who is signed out, or who signs up
 * first, must come back to that address with the query intact.
 */
final class InstallReturnThroughSignInTest extends WebTestCase
{
    private const string SETUP = '/github/app/setup?installation_id=424242&setup_action=install';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    public function test_a_signed_in_form_sign_in_returns_to_the_setup_url_with_its_query(): void
    {
        $this->createUser(verified: true);

        $this->client->request(Request::METHOD_GET, self::SETUP);
        self::assertResponseRedirects('http://localhost/login');
        $this->client->followRedirect();
        $this->client->submitForm('Sign in', ['email' => 'install@example.com', 'password' => 'SecurePassword1!']);

        self::assertResponseRedirects('http://localhost'.self::SETUP);
    }

    public function test_an_unverified_sign_in_keeps_the_setup_url_for_the_email_confirmation(): void
    {
        $user = $this->createUser(verified: false);
        $token = $user->generateEmailVerificationToken();
        $this->flush($user);

        $this->client->request(Request::METHOD_GET, self::SETUP);
        $this->client->followRedirect();
        $this->client->submitForm('Sign in', ['email' => 'install@example.com', 'password' => 'SecurePassword1!']);
        self::assertResponseRedirects('http://localhost'.self::SETUP);
        $this->client->request(Request::METHOD_GET, self::SETUP);
        self::assertResponseRedirects('/register/check-email');

        $this->client->request(Request::METHOD_GET, '/register/verify?token='.$token);

        self::assertResponseRedirects('http://localhost'.self::SETUP);
    }

    public function test_a_sign_up_with_email_confirmation_in_the_same_browser_lands_on_the_install(): void
    {
        InstalledInstance::ensure(static::getContainer());

        $this->client->request(Request::METHOD_GET, self::SETUP);
        self::assertResponseRedirects('http://localhost/login');
        $this->client->request(Request::METHOD_GET, '/register');
        $this->client->submitForm('Create account', [
            'registration_form[email]' => 'signup@example.com',
            'registration_form[fullName]' => 'Riley Chen',
            'registration_form[plainPassword]' => 'SecurePassword1!',
            'registration_form[agreeTerms]' => true,
        ]);
        self::assertResponseRedirects('/register/check-email');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail('signup@example.com');
        self::assertNotNull($user);
        $token = $user->generateEmailVerificationToken();
        $em->flush();

        $this->client->request(Request::METHOD_GET, '/register/verify?token='.$token);

        self::assertResponseRedirects('http://localhost'.self::SETUP);
    }

    public function test_a_social_sign_up_lands_on_the_install(): void
    {
        $container = static::getContainer();
        InstalledInstance::ensure($container);
        $em = $container->get(EntityManagerInterface::class);
        $em->persist(new FeatureFlag(SocialProvider::Google->flagName(), FeatureFlagType::Bool, true));
        $em->flush();
        $this->stubGoogle('google-install-1', ['email' => 'social@example.com', 'email_verified' => true, 'name' => 'Social Person']);

        $this->client->request(Request::METHOD_GET, self::SETUP);
        self::assertResponseRedirects('http://localhost/login');
        $this->client->request(Request::METHOD_GET, '/oauth/google/check?code=stub-code&state=stub-state');

        self::assertResponseRedirects('http://localhost'.self::SETUP);
        self::assertNotNull($container->get(UserRepository::class)->findOneByEmail('social@example.com'));
    }

    private function createUser(bool $verified): User
    {
        $container = static::getContainer();
        $user = new User('Install User', 'install@example.com');
        AcceptedTerms::stamp($user, $container);
        $user->password = $container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'SecurePassword1!');
        if ($verified) {
            $user->emailVerifiedAt = new \DateTimeImmutable();
        }
        $this->flush($user);

        return $user;
    }

    private function flush(User $user): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();
    }

    /** @param array<string, mixed> $data */
    private function stubGoogle(string $providerUserId, array $data): void
    {
        $owner = new readonly class($providerUserId, $data) implements ResourceOwnerInterface {
            /** @param array<string, mixed> $data */
            public function __construct(private string $id, private array $data)
            {
            }

            public function getId(): string
            {
                return $this->id;
            }

            /** @return array<string, mixed> */
            public function toArray(): array
            {
                return $this->data;
            }
        };

        $oauthClient = $this->createStub(OAuth2ClientInterface::class);
        $oauthClient->method('getAccessToken')->willReturn(new AccessToken(['access_token' => 'stub-token']));
        $oauthClient->method('fetchUserFromToken')->willReturn($owner);
        $registry = $this->createStub(ClientRegistry::class);
        $registry->method('getClient')->willReturn($oauthClient);
        static::getContainer()->set('knpu.oauth2.registry', $registry);
    }
}
