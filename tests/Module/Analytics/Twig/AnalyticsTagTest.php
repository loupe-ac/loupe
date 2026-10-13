<?php

declare(strict_types=1);

namespace App\Tests\Module\Analytics\Twig;

use App\Module\Account\Entity\User;
use App\Module\Analytics\Twig\AnalyticsScript;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

/** The tag loads only for a signed-out visitor on a public page, and honours Do Not Track. */
final class AnalyticsTagTest extends WebTestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        $this->setAnalyticsEnv('', '');

        parent::tearDown();
    }

    public function test_a_signed_out_visitor_gets_the_tag_in_the_head_with_its_guards(): void
    {
        $client = $this->clientWithAnalytics();

        $client->request(Request::METHOD_GET, '/privacy');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('head script[data-website-id="website-id"]');
        self::assertSelectorNotExists('body script[data-website-id]');
        self::assertSelectorExists('script[data-do-not-track="true"]');
        self::assertSelectorExists('script[data-before-send="loupeAnalyticsBeforeSend"]');

        $paths = $client->getCrawler()->filter('script[data-website-id]')->attr('data-public-paths');
        self::assertIsString($paths);
        $decoded = json_decode($paths, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertContains('/privacy', $decoded);
        self::assertContains('/login', $decoded);
        self::assertNotContains('/projects', $decoded);
    }

    public function test_a_signed_in_user_gets_no_tag_on_a_public_page(): void
    {
        $client = $this->clientWithAnalytics();
        $user = new User('Tag User', 'tag-user@example.com');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->persist($user);
        $em->flush();
        $em->clear();
        $client->loginUser($user);

        $client->request(Request::METHOD_GET, '/privacy');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('title');
        self::assertSelectorNotExists('script[data-website-id]');
    }

    public function test_a_page_that_is_not_public_gets_no_tag(): void
    {
        $client = $this->clientWithAnalytics();

        $client->request(Request::METHOD_GET, '/no-such-page');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorNotExists('script[data-website-id]');
    }

    private function clientWithAnalytics(): KernelBrowser
    {
        $this->setAnalyticsEnv('https://analytics.test/script.js', 'website-id');
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->persist(new FeatureFlag(name: AnalyticsScript::ENABLED_FLAG, type: FeatureFlagType::Bool, value: true));
        $em->flush();

        return $client;
    }

    private function setAnalyticsEnv(string $scriptUrl, string $websiteId): void
    {
        $_ENV['ANALYTICS_SCRIPT_URL'] = $_SERVER['ANALYTICS_SCRIPT_URL'] = $scriptUrl;
        $_ENV['ANALYTICS_WEBSITE_ID'] = $_SERVER['ANALYTICS_WEBSITE_ID'] = $websiteId;
    }
}
