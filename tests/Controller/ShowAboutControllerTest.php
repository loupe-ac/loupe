<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Account\Entity\User;
use App\Site\SiteLinks;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class ShowAboutControllerTest extends WebTestCase
{
    public function test_anonymous_visitor_reaches_the_source_offer(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/about');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="https://github.com/loupe-ac/loupe"]');
        self::assertSelectorNotExists('[data-testid="app-version"]');
        self::assertSelectorExists('a[href="https://loupe.ac/docs/"]');
    }

    public function test_signed_in_visitor_also_sees_the_build_version(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('Aboutviewer', 'about-viewer@example.com');
        AcceptedTerms::stamp($user, static::getContainer());
        $user->password = 'hashed-password-placeholder';
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/about');

        self::assertResponseIsSuccessful();
        // No release build produced this checkout, so the card says so rather
        // than inventing a version — see BuildIdentity.
        self::assertSelectorTextSame('[data-testid="app-version"]', 'Built from source');
        self::assertSelectorNotExists('[data-testid="update-status"]');
    }

    public function test_the_community_card_links_the_discord_server_the_flag_names(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $repository = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $repository);
        $flag = $repository->findAllIndexed()[SiteLinks::DISCORD_FLAG] ?? null;
        self::assertInstanceOf(FeatureFlag::class, $flag, 'A migration seeds the row.');
        $flag->value = 'https://discord.gg/loupe';
        $em->flush();

        $client->request(Request::METHOD_GET, '/about');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#about-community', 'Community');
        self::assertSelectorExists('.lp-about-item a[href="https://discord.gg/loupe"][target="_blank"][rel="noopener noreferrer"]');
    }

    public function test_the_community_card_is_hidden_while_the_discord_flag_is_empty(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/about');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#about-community');
    }
}
