<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Routing\PinnedUrlGenerator;
use App\Site\SiteHost;
use App\Site\SiteLinks;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class LandingControllerTest extends WebTestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        $this->setBlogProxyHost('');

        parent::tearDown();
    }

    /** @param non-empty-string $email */
    private function createUser(EntityManagerInterface $em, string $username, string $email): User
    {
        $user = new User(
            fullName: ucfirst($username),
            email: $email,
            password: 'hashed-password-placeholder',
        );
        AcceptedTerms::stamp($user, static::getContainer());
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);

        return $user;
    }

    public function test_anonymous_visitor_sees_the_home_page_where_the_site_role_applies(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-home');
        self::assertSelectorTextContains('h1', 'From ideas to features.');
        self::assertSelectorExists('a[href="/waitlist"]');
        self::assertSelectorNotExists('a[href="/register"]');
    }

    public function test_home_page_head_links_the_agent_setup_instructions(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        $link = $client->getCrawler()->filter('head link[rel="help"][type="text/markdown"]');
        self::assertCount(1, $link);
        self::assertStringEndsWith('/setup.md', (string) $link->attr('href'));
    }

    public function test_home_page_body_shows_the_full_setup_address_as_link_text(): void
    {
        $client = static::createClient();
        $setupUrl = static::getContainer()->get(PinnedUrlGenerator::class)->generate('app_setup_instructions');

        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        $line = $client->getCrawler()->filter('body [data-agent-setup]');
        self::assertCount(1, $line);
        self::assertStringContainsString('Using a coding agent? Ask it to read '.$setupUrl, $line->text());
        $link = $line->filter('a');
        self::assertSame($setupUrl, $link->attr('href'));
        self::assertSame($setupUrl, $link->text());
    }

    public function test_each_waitlist_button_names_its_click_event_and_still_opens_the_dialog(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-home[data-controller~="analytics"][data-controller~="modal"]');
        $buttons = $client->getCrawler()->filter('a[href="/waitlist"][data-action~="click->modal#open"]');
        self::assertCount(7, $buttons);
        $names = $buttons->each(static function ($button): string {
            self::assertSame('click->analytics#track click->modal#open', $button->attr('data-action'));
            self::assertSame('waitlist-click', $button->attr('data-analytics-name-param'));

            return (string) $button->attr('data-analytics-button-param');
        });
        sort($names);
        self::assertSame(
            ['closing', 'footer', 'hero', 'nav', 'open-source', 'requirements-missing', 'requirements-ready'],
            $names,
        );
    }

    public function test_home_page_shows_six_steps_eight_features_and_no_price(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(6, '.lp-home-step');
        self::assertSelectorCount(8, '.lp-home-feature');
        self::assertSelectorCount(3, '.lp-home-step .lp-tag');
        $text = strtolower($client->getCrawler()->filter('main')->text());
        foreach (['per month', 'free trial', 'pricing', 'per seat'] as $word) {
            self::assertStringNotContainsString($word, $text);
        }
    }

    public function test_home_page_names_the_design_canvas_and_not_the_standalone_claude_design_app(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#integrations .lp-home-tile:nth-child(2) .lp-home-tile__name', 'Design canvas');
        self::assertStringNotContainsString('claude design', strtolower($client->getCrawler()->filter('main')->text()));
    }

    public function test_home_page_footer_links_to_the_x_account_the_flag_names_and_not_to_bluesky(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $repository = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $repository);
        $flag = $repository->findAllIndexed()[SiteLinks::X_FLAG] ?? null;
        self::assertInstanceOf(FeatureFlag::class, $flag, 'A migration seeds the row.');
        $flag->value = 'https://x.com/loupehq';
        $em->flush();

        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-home-footer a[href="https://x.com/loupehq"]');
        self::assertSelectorNotExists('.lp-home-footer a[href*="x.com/ubermuda"]');
        self::assertSelectorNotExists('.lp-home-footer a[href*="bsky.app"]');
    }

    public function test_home_page_footer_has_no_x_link_while_the_flag_is_empty(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.lp-home-footer a[href*="x.com"]');
    }

    public function test_home_page_footer_links_to_the_discord_server_the_flag_names(): void
    {
        $client = static::createClient();
        $this->setLink(SiteLinks::DISCORD_FLAG, 'https://discord.gg/loupe');

        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-home-footer__social a[href="https://discord.gg/loupe"][target="_blank"][rel="noopener noreferrer"][aria-label="Loupe on Discord"]');
        self::assertSelectorNotExists('.lp-home-nav a[href="https://discord.gg/loupe"]');
    }

    public function test_home_page_footer_has_no_discord_link_while_the_flag_is_empty(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.lp-home-footer a[aria-label="Loupe on Discord"]');
    }

    private function setLink(string $flag, string $value): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $repository = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $repository);
        $row = $repository->findAllIndexed()[$flag] ?? null;
        self::assertInstanceOf(FeatureFlag::class, $row, 'A migration seeds the row.');
        $row->value = $value;
        $em->flush();
    }

    public function test_home_page_footer_links_the_blog_after_the_changelog_when_a_blog_host_is_set(): void
    {
        $this->setBlogProxyHost('blog.example.com');
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.lp-home-footer__list li:has(a[href$="/changelog/"]) + li a[href="/blog"]', 'Blog');
        self::assertStringNotContainsString('blog.example.com', (string) $client->getResponse()->getContent());
    }

    public function test_home_page_footer_has_no_blog_link_when_no_blog_host_is_set(): void
    {
        $this->setBlogProxyHost('');
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-home-footer a[href$="/changelog/"]');
        self::assertSelectorNotExists('a[href="/blog"]');
    }

    public function test_home_page_notify_form_posts_the_picks_to_the_waitlist(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#waitlist-notify form[action="/waitlist/notify"]');
        self::assertSelectorCount(10, 'input.lp-home-chip__input[type="checkbox"][name="waitlist_join_form[picks][]"]');
        self::assertSelectorExists('input[name="waitlist_join_form[email]"]');
        self::assertSelectorExists('input[name="waitlist_join_form[other]"]');
        self::assertSelectorExists('.lp-home-ask__body[hidden]');
    }

    public function test_every_waitlist_button_opens_the_one_waitlist_dialog(): void
    {
        $client = static::createClient();

        $crawler = $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        $ctas = $crawler->filter('a[href="/waitlist"]');
        self::assertGreaterThan(0, $ctas->count());
        self::assertSame($ctas->count(), $crawler->filter('a[href="/waitlist"][data-action~="click->modal#open"]')->count());
        self::assertSelectorCount(1, '[data-controller~="modal"] dialog.lp-dialog');
        self::assertSelectorExists('dialog turbo-frame#waitlist-modal form[action="/waitlist/modal"] input[name="waitlist_modal_form[email]"]');
        self::assertSelectorNotExists('turbo-frame#waitlist-modal input[type="checkbox"]');
    }

    public function test_anonymous_visitor_is_sent_to_login_where_there_is_no_site_host(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set(SiteHost::class, new SiteHost('', 'https://loupe.example.com'));

        $client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/login');
    }

    public function test_anonymous_visitor_sees_the_page_on_the_one_host_of_both_roles(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set(SiteHost::class, new SiteHost('loupe.ac', 'https://loupe.ac'));

        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-home');
    }

    public function test_authenticated_user_never_sees_the_landing_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'home-landing', 'home-landing@example.com');
        $user->wizardCompletedAt = new \DateTimeImmutable();
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/projects');
    }

    public function test_fresh_user_is_sent_to_the_wizard(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'home-fresh', 'home-fresh@example.com');
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/welcome');
    }

    public function test_wizard_completed_user_with_no_projects_lands_on_the_projects_list(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'home-none', 'home-none@example.com');
        $user->wizardCompletedAt = new \DateTimeImmutable();
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/projects');
    }

    public function test_mid_wizard_user_with_project_goes_to_its_documents(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'home-midwizard', 'home-midwizard@example.com');
        $project = new Project($user, 'mid-wizard-project');
        $em->persist($project);
        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/projects/'.$projectId.'/documents');
    }

    public function test_user_with_one_project_lands_on_its_documents(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'home-one', 'home-one@example.com');
        $project = new Project($user, 'only-project');
        $em->persist($project);
        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/projects/'.$projectId.'/documents');
    }

    public function test_user_with_multiple_projects_lands_on_the_projects_list(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'home-many', 'home-many@example.com');
        $em->persist(new Project($user, 'first'));
        $em->persist(new Project($user, 'second'));
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/projects');
    }

    private function setBlogProxyHost(string $host): void
    {
        $_ENV['BLOG_PROXY_HOST'] = $_SERVER['BLOG_PROXY_HOST'] = $host;
    }
}
