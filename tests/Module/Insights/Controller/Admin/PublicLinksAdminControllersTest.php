<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller\Admin;

use App\Module\Account\Entity\User;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Entity\PublicLinkTakedown;
use App\Module\Insights\Repository\PublicLinkTakedownRepository;
use App\Module\Insights\Service\PublicLinksAdminUserPanel;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class PublicLinksAdminControllersTest extends WebTestCase
{
    use InsightsScenario;

    private const string TOKEN = 'csrf-token';

    public function test_a_non_admin_is_refused_everywhere(): void
    {
        $client = static::createClient();
        $link = $this->sharedLink($this->scenarioProject('admin-refused'));
        $plain = $this->user($this->em(), 'admin-refused-plain-'.uniqid().'@example.com');
        $projectId = (string) $link->project->id;
        $code = $link->code;
        $client->loginUser($plain);

        $client->request(Request::METHOD_GET, '/admin/public-links');
        self::assertResponseStatusCodeSame(403);
        $client->request(Request::METHOD_GET, '/admin/public-links/'.$code.'/take-down');
        self::assertResponseStatusCodeSame(403);
        $client->request(Request::METHOD_POST, '/admin/public-links/'.$code.'/take-down', ['_csrf_token' => self::TOKEN]);
        self::assertResponseStatusCodeSame(403);
        $client->request(Request::METHOD_POST, '/admin/public-links/projects/'.$projectId.'/lift', ['_csrf_token' => self::TOKEN]);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(0, $this->takedownCount($projectId));
    }

    public function test_the_list_shows_taken_down_projects_first_with_their_actions(): void
    {
        $client = static::createClient();
        $admin = $this->admin();
        $plain = $this->sharedLink($this->scenarioProject('admin-list-a'));
        $taken = $this->sharedLink($this->scenarioProject('admin-list-z'));
        $this->seedTakedown($taken->project, $admin);
        $takenId = (string) $taken->project->id;
        $plainId = (string) $plain->project->id;
        $plainCode = $plain->code;
        $this->em()->clear();

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/public-links', ['q' => 'admin-list-']);

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('tr[data-project-id]');
        self::assertCount(2, $rows);
        self::assertSame($takenId, $rows->eq(0)->attr('data-project-id'));
        self::assertSame($plainId, $rows->eq(1)->attr('data-project-id'));
        self::assertStringContainsString('Taken down', $rows->eq(0)->filter('[data-testid="public-link-state"]')->text());
        self::assertCount(1, $rows->eq(0)->filter('[data-testid="public-link-lift"]'));
        self::assertCount(1, $rows->eq(0)->filter('td.text-right summary.admin-action-row--end'));
        self::assertCount(0, $rows->eq(0)->filter('[data-testid="public-link-take-down"]'));
        self::assertStringContainsString('Public', $rows->eq(1)->filter('[data-testid="public-link-state"]')->text());
        self::assertStringContainsString('/admin/public-links/'.$plainCode.'/take-down', (string) $rows->eq(1)->filter('[data-testid="public-link-take-down"]')->attr('href'));
        self::assertStringContainsString('Public links', $crawler->filter('nav, aside')->text());
    }

    public function test_a_code_search_finds_one_link_and_a_user_filter_finds_the_projects_of_one_owner(): void
    {
        $client = static::createClient();
        $admin = $this->admin();
        $project = $this->scenarioProject('admin-search');
        $first = $this->sharedLink($project);
        $this->sharedLink($project);
        $other = $this->sharedLink($this->scenarioProject('admin-search-other'));
        $ownerId = (string) $project->owner->id;
        $this->em()->clear();
        $client->loginUser($admin);

        $byCode = $client->request(Request::METHOD_GET, '/admin/public-links', ['q' => $first->code]);
        self::assertCount(1, $byCode->filter('tr[data-project-id]'));
        self::assertSame($first->code, $byCode->filter('tr[data-project-id]')->attr('data-link-code'));

        $byUser = $client->request(Request::METHOD_GET, '/admin/public-links', ['user' => $ownerId]);
        self::assertCount(1, $byUser->filter('tr[data-project-id]'));
        self::assertNotSame($other->code, $byUser->filter('tr[data-project-id]')->attr('data-link-code'));
    }

    public function test_the_list_says_whether_the_instance_flag_is_on_and_links_to_the_flags_page(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());

        $crawler = $client->request(Request::METHOD_GET, '/admin/public-links');
        $notice = $crawler->filter('[data-testid="public-links-flag"]');
        self::assertSame('true', $notice->attr('data-flag-on'));
        self::assertSame('/admin/feature-flags', $notice->filter('a')->attr('href'));

        $flags = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[PublicLink::FLAG]->value = false;
        $this->em()->flush();

        $crawler = $client->request(Request::METHOD_GET, '/admin/public-links');
        self::assertSame('false', $crawler->filter('[data-testid="public-links-flag"]')->attr('data-flag-on'));
        self::assertStringContainsString('switched off', $crawler->filter('[data-testid="public-links-flag"]')->text());
    }

    public function test_the_confirm_page_names_the_project_and_an_unknown_code_is_not_found(): void
    {
        $client = static::createClient();
        $link = $this->sharedLink($this->scenarioProject('admin-confirm'));
        $code = $link->code;
        $client->loginUser($this->admin());

        $crawler = $client->request(Request::METHOD_GET, '/admin/public-links/'.$code.'/take-down');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Project admin-confirm', $crawler->filter('[data-testid="take-down-confirm"]')->text());
        $form = $crawler->filter('[data-testid="take-down-confirm"] form');
        self::assertSame('/admin/public-links/'.$code.'/take-down', $form->attr('action'));
        self::assertCount(1, $form->filter('input[name="_csrf_token"]'));

        $client->request(Request::METHOD_GET, '/admin/public-links/aaaaaaaaaaaa/take-down');
        self::assertResponseStatusCodeSame(404);
        $client->request(Request::METHOD_POST, '/admin/public-links/aaaaaaaaaaaa/take-down', ['_csrf_token' => self::TOKEN]);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_take_down_stores_the_row_for_the_whole_project_and_a_repeat_changes_nothing(): void
    {
        $client = static::createClient();
        $admin = $this->admin();
        $project = $this->scenarioProject('admin-take');
        $link = $this->sharedLink($project);
        $this->sharedLink($project);
        $projectId = (string) $project->id;
        $adminId = (string) $admin->id;
        $code = $link->code;
        $this->em()->clear();
        $client->loginUser($admin);

        $this->post($client, '/admin/public-links/'.$code.'/take-down', '/admin/public-links');
        self::assertResponseRedirects('/admin/public-links');
        $client->followRedirect();
        self::assertStringContainsString('The public links of Project admin-take are taken down.', (string) $client->getResponse()->getContent());

        $this->em()->clear();
        $takedown = $this->takedownRepository()->findOneBy(['project' => $projectId]);
        self::assertInstanceOf(PublicLinkTakedown::class, $takedown);
        self::assertSame($adminId, (string) $takedown->takenDownBy?->id);

        $this->post($client, '/admin/public-links/'.$code.'/take-down', '/admin/public-links');
        self::assertResponseRedirects('/admin/public-links');
        self::assertSame(1, $this->takedownCount($projectId));
    }

    public function test_a_wrong_token_takes_nothing_down(): void
    {
        $client = static::createClient();
        $admin = $this->admin();
        $link = $this->sharedLink($this->scenarioProject('admin-csrf'));
        $projectId = (string) $link->project->id;
        $code = $link->code;
        $this->em()->clear();
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/admin/public-links');
        $client->request(Request::METHOD_POST, '/admin/public-links/'.$code.'/take-down', ['_csrf_token' => 'wrong']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->takedownCount($projectId));
    }

    public function test_a_return_to_inside_the_admin_area_is_honoured_and_any_other_is_dropped(): void
    {
        $client = static::createClient();
        $admin = $this->admin();
        $link = $this->sharedLink($this->scenarioProject('admin-return'));
        $code = $link->code;
        $client->loginUser($admin);

        $this->post($client, '/admin/public-links/'.$code.'/take-down', '/admin/public-links?q=admin-return');
        self::assertResponseRedirects('/admin/public-links?q=admin-return');

        $this->post($client, '/admin/public-links/'.$code.'/take-down', 'https://evil.example/');
        self::assertResponseRedirects('/admin/public-links');
    }

    public function test_a_lift_deletes_the_row_and_the_links_that_were_on_work_again(): void
    {
        $client = static::createClient();
        $admin = $this->admin();
        $on = $this->sharedLink($this->scenarioProject('admin-lift'));
        $off = $this->sharedLink($on->project);
        $off->enabled = false;
        $this->em()->flush();
        $this->seedTakedown($on->project, $admin);
        $projectId = (string) $on->project->id;
        $onCode = $on->code;
        $offCode = $off->code;
        $this->em()->clear();
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/s/'.$onCode);
        self::assertResponseStatusCodeSame(404);

        $this->post($client, '/admin/public-links/projects/'.$projectId.'/lift', '/admin/public-links');
        self::assertResponseRedirects('/admin/public-links');
        $client->followRedirect();
        self::assertStringContainsString('The takedown of Project admin-lift is lifted.', (string) $client->getResponse()->getContent());

        self::assertSame(0, $this->takedownCount($projectId));
        $client->request(Request::METHOD_GET, '/s/'.$onCode);
        self::assertResponseIsSuccessful();
        $client->request(Request::METHOD_GET, '/s/'.$offCode);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_lift_with_a_wrong_token_or_an_unknown_project_changes_nothing(): void
    {
        $client = static::createClient();
        $admin = $this->admin();
        $link = $this->sharedLink($this->scenarioProject('admin-lift-csrf'));
        $this->seedTakedown($link->project, $admin);
        $projectId = (string) $link->project->id;
        $this->em()->clear();
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/admin/public-links');
        $client->request(Request::METHOD_POST, '/admin/public-links/projects/'.$projectId.'/lift', ['_csrf_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->takedownCount($projectId));

        $this->post($client, '/admin/public-links/projects/00000000-0000-4000-8000-000000000000/lift', '/admin/public-links');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_user_panel_lists_the_projects_of_an_owner_and_abstains_for_a_user_with_no_link(): void
    {
        $client = static::createClient();
        $admin = $this->admin();
        $link = $this->sharedLink($this->scenarioProject('admin-panel'));
        $this->seedTakedown($link->project, $admin);
        $ownerId = (string) $link->project->owner->id;
        $bystander = $this->user($this->em(), 'admin-panel-none-'.uniqid().'@example.com');
        $bystanderId = (string) $bystander->id;
        $this->em()->clear();

        $panel = static::getContainer()->get(PublicLinksAdminUserPanel::class);
        self::assertInstanceOf(PublicLinksAdminUserPanel::class, $panel);
        $owner = $this->em()->find(User::class, $ownerId) ?? throw new \LogicException();
        $context = $panel->panelFor($owner)?->context;
        self::assertNotNull($context);
        self::assertSame(1, $context['total']);
        self::assertSame([['name' => 'Project admin-panel', 'items' => 1, 'liveItems' => 1, 'takenDown' => true]], $context['projects']);
        self::assertNull($panel->panelFor($this->em()->find(User::class, $bystanderId) ?? throw new \LogicException()));

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/users/'.$ownerId);
        self::assertCount(1, $crawler->filter('[data-testid="public-links-panel"]'));
        self::assertSame('/admin/public-links?user='.$ownerId, $crawler->filter('[data-testid="public-links-panel-link"]')->attr('href'));

        $crawler = $client->request(Request::METHOD_GET, '/admin/users/'.$bystanderId);
        self::assertCount(0, $crawler->filter('[data-testid="public-links-panel"]'));
    }

    private function post(KernelBrowser $client, string $path, string $returnTo): void
    {
        // A preceding authenticated GET gives BrowserKit history, so the stateless CSRF sentinel passes as same-origin.
        $client->request(Request::METHOD_GET, '/admin/public-links');
        $client->request(Request::METHOD_POST, $path, ['_csrf_token' => self::TOKEN, 'returnTo' => $returnTo]);
    }

    private function admin(): User
    {
        $admin = $this->user($this->em(), 'admin-'.uniqid().'@example.com');
        $admin->roles = ['ROLE_ADMIN'];
        $this->em()->flush();

        return $admin;
    }

    private function sharedLink(Project $project): PublicLink
    {
        $link = PublicLink::forGraph($this->seedSavedGraph($project, 'Graph '.uniqid()));
        $this->em()->persist($link);
        $this->em()->flush();

        return $link;
    }

    private function takedownCount(string $projectId): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM insights_public_link_takedowns WHERE project_id = ?', [$projectId]);
    }

    private function takedownRepository(): PublicLinkTakedownRepository
    {
        $repository = self::getContainer()->get(PublicLinkTakedownRepository::class);
        self::assertInstanceOf(PublicLinkTakedownRepository::class, $repository);

        return $repository;
    }
}
