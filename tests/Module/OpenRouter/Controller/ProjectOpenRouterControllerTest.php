<?php

declare(strict_types=1);

namespace App\Tests\Module\OpenRouter\Controller;

use App\Mercure\ProjectTopicBuilder;
use App\Mercure\UserTopicBuilder;
use App\Module\OpenRouter\Entity\OpenRouterKeyState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\OpenRouter\OpenRouterScenario;
use App\Tests\Support\MercureCookies;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProjectOpenRouterControllerTest extends WebTestCase
{
    use OpenRouterScenario;
    use MercureCookies;

    private const string SECRET = 'sk-or-v1-0123456789abcdef-c4d5';

    private int $calls = 0;

    public function test_the_section_needs_a_signed_in_user(): void
    {
        $client = $this->client();
        $project = $this->makeProject(static::getContainer(), $this->makeUser(static::getContainer(), 'anon-owner'));

        foreach ([['GET', ''], ['POST', ''], ['POST', '/remove'], ['GET', '/status']] as [$method, $suffix]) {
            $client->request($method, $this->page($project).$suffix);
            self::assertResponseRedirects('/login');
        }
    }

    public function test_a_user_who_does_not_own_the_project_can_neither_see_nor_change_the_section(): void
    {
        $client = $this->client();
        $owner = $this->makeUser(static::getContainer(), 'authz-owner');
        $project = $this->makeProject(static::getContainer(), $owner);
        $this->makeKey(static::getContainer(), $project, 'sk-or-v1-project-secret-wxyz');
        $client->loginUser($this->makeUser(static::getContainer(), 'authz-member'));

        foreach ([['GET', ''], ['GET', '/status'], ['GET', '?replace=1']] as [$method, $suffix]) {
            $client->request($method, $this->page($project).$suffix);
            self::assertResponseStatusCodeSame(403, $method.' '.$suffix);
            self::assertStringNotContainsString('wxyz', (string) $client->getResponse()->getContent());
        }
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, $this->page($project), ['open_router_key_form' => ['secret' => self::SECRET]]);
        self::assertResponseStatusCodeSame(403);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, $this->page($project).'/remove');
        self::assertResponseStatusCodeSame(403);

        // Guard: nothing was probed, saved or removed by the refused requests.
        self::assertSame(0, $this->calls);
        $this->em()->clear();
        $stored = $this->keys()->findOneBy(['project' => $project]);
        self::assertNotNull($stored);
        self::assertSame('sk-or-v1-project-secret-wxyz', $stored->secret);
    }

    public function test_the_owner_sees_the_section_in_the_settings_navigation(): void
    {
        $client = $this->client();
        $owner = $this->makeUser(static::getContainer(), 'nav-owner');
        $project = $this->makeProject(static::getContainer(), $owner);
        $client->loginUser($owner);

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->page($project));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.lp-settings-nav__item--active[href="'.$this->page($project).'"]'));
        $general = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$project->id.'/edit');
        self::assertCount(1, $general->filter('.lp-settings-nav__item[href="'.$this->page($project).'"]'));
    }

    public function test_the_project_key_is_in_use_with_its_mask_and_the_hint_about_the_account_key(): void
    {
        $client = $this->client();
        $owner = $this->makeUser(static::getContainer(), 'own-key');
        $project = $this->makeProject(static::getContainer(), $owner);
        $this->makeKey(static::getContainer(), $owner, 'sk-or-v1-account-secret-a3f9');
        $this->makeKey(static::getContainer(), $project, 'sk-or-v1-project-secret-7c21');
        $client->loginUser($owner);

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->page($project));

        self::assertSame("Key in use: This project's key", trim($crawler->filter('[data-testid="openrouter-key-in-use"]')->text()));
        self::assertSame('sk-or-v1-…7c21', trim($crawler->filter('[data-testid="openrouter-key-mask"]')->text()));
        self::assertStringContainsString('Connected', $crawler->filter('[data-testid="openrouter-key-row"]')->text());
        self::assertSame('disclosure#toggle', $crawler->filter('[data-testid="openrouter-key-replace"]')->attr('data-action'));
        self::assertCount(1, $crawler->filter('[data-controller="modal"] [data-testid="openrouter-key-remove"][type="button"]'));
        self::assertStringContainsString('Remove the key of this project?', $crawler->filter('[data-testid="openrouter-remove-dialog"]')->text());
        self::assertCount(1, $crawler->filter('.lp-openrouter-replace:not(.open) [data-testid="openrouter-key-form"]'));
        $hint = $crawler->filter('.lp-openrouter-note[data-testid="openrouter-key-hint"]');
        self::assertStringContainsString('Remove the project key to use the key of your account again.', $hint->text());
        self::assertCount(1, $hint->filter('svg'));
        self::assertStringNotContainsString('a3f9', $crawler->filter('[data-testid="openrouter-key-row"]')->text());
    }

    public function test_the_account_key_is_in_use_when_the_project_has_none(): void
    {
        $client = $this->client();
        $owner = $this->makeUser(static::getContainer(), 'account-key');
        $project = $this->makeProject(static::getContainer(), $owner);
        $key = $this->makeKey(static::getContainer(), $owner, 'sk-or-v1-account-secret-a3f9');
        $key->state = OpenRouterKeyState::Refused;
        $key->refusedSince = new \DateTimeImmutable('2026-10-30 21:40:00');
        $this->em()->flush();
        $client->loginUser($owner);

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->page($project));

        self::assertSame('Key in use: Your account key', trim($crawler->filter('[data-testid="openrouter-key-in-use"]')->text()));
        self::assertSame('sk-or-v1-…a3f9', trim($crawler->filter('[data-testid="openrouter-key-mask"]')->text()));
        self::assertStringContainsString('Refused by OpenRouter', $crawler->filter('[data-testid="openrouter-key-row"]')->text());
        self::assertCount(1, $crawler->filter('[data-testid="openrouter-key-use-own"]'));
        self::assertCount(0, $crawler->filter('[data-testid="openrouter-key-remove"]'));
        self::assertCount(1, $crawler->filter('.lp-openrouter-replace:not(.open) [data-testid="openrouter-key-form"]'));
        self::assertCount(1, $crawler->filter('.lp-openrouter-note[data-testid="openrouter-key-hint"] svg'));
    }

    public function test_with_no_key_anywhere_the_page_offers_a_key_for_the_project_or_the_account(): void
    {
        $client = $this->client();
        $owner = $this->makeUser(static::getContainer(), 'no-key');
        $project = $this->makeProject(static::getContainer(), $owner);
        $this->makeKey(static::getContainer(), $this->makeUser(static::getContainer(), 'no-key-stranger'), 'sk-or-v1-stranger-0000');
        $client->loginUser($owner);

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->page($project));

        self::assertCount(1, $crawler->filter('[data-testid="openrouter-key-empty"]'));
        self::assertCount(0, $crawler->filter('[data-testid="openrouter-key-row"]'));
        self::assertSame($this->page($project).'?replace=1', $crawler->filter('[data-testid="openrouter-key-add-project"]')->attr('href'));
        self::assertSame('disclosure#toggle', $crawler->filter('[data-testid="openrouter-key-add-project"]')->attr('data-action'));
        self::assertSame('/account/openrouter', $crawler->filter('[data-testid="openrouter-key-add-account"]')->attr('href'));
    }

    public function test_the_page_subscribes_to_the_topics_of_the_project_and_its_owner_and_the_status_frame_does_not(): void
    {
        $client = $this->client();
        $owner = $this->makeUser(static::getContainer(), 'topics');
        $project = $this->makeProject(static::getContainer(), $owner);
        $client->loginUser($owner);

        $page = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->page($project));
        $topics = self::subscribedTopics($client->getResponse()) ?? [];
        $inPage = $page->filter('turbo-frame#openrouter-key');
        $fragment = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->page($project).'/status');

        $projectTopics = static::getContainer()->get(ProjectTopicBuilder::class);
        $userTopics = static::getContainer()->get(UserTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $projectTopics);
        self::assertInstanceOf(UserTopicBuilder::class, $userTopics);
        self::assertContains($projectTopics->forOpenRouter($project->requireId()), $topics);
        self::assertContains($userTopics->forOpenRouter($owner->id ?? throw new \LogicException('no id')), $topics);
        self::assertSame($inPage->outerHtml(), $fragment->filter('turbo-frame#openrouter-key')->outerHtml());
        self::assertNull(self::subscribedTopics($client->getResponse()));
    }

    public function test_a_working_key_is_saved_for_the_project_and_the_account_key_stays(): void
    {
        $client = $this->client(200);
        $owner = $this->makeUser(static::getContainer(), 'save-project');
        $project = $this->makeProject(static::getContainer(), $owner);
        $this->makeKey(static::getContainer(), $owner, 'sk-or-v1-account-secret-a3f9');
        $client->loginUser($owner);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->page($project).'?replace=1');
        $client->submitForm('Save the key', ['open_router_key_form[secret]' => self::SECRET]);

        self::assertResponseRedirects($this->page($project));
        $this->em()->clear();
        $stored = $this->keys()->findOneBy(['project' => $project]);
        self::assertNotNull($stored);
        self::assertSame(self::SECRET, $stored->secret);
        self::assertSame('sk-or-v1-account-secret-a3f9', $this->keys()->findOneBy(['user' => $owner])?->secret);
        $crawler = $client->followRedirect();
        self::assertSame('sk-or-v1-…c4d5', trim($crawler->filter('[data-testid="openrouter-key-mask"]')->text()));
    }

    public function test_a_key_that_is_not_a_management_key_is_refused_with_the_link_and_is_not_echoed(): void
    {
        $client = $this->client(403);
        $owner = $this->makeUser(static::getContainer(), 'save-403');
        $project = $this->makeProject(static::getContainer(), $owner);
        $client->loginUser($owner);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->page($project).'?replace=1');
        $crawler = $client->submitForm('Save the key', ['open_router_key_form[secret]' => self::SECRET]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('OpenRouter says this is not a management key.', $crawler->filter('[data-testid="openrouter-key-form"]')->text());
        self::assertCount(1, $crawler->filter('.lp-openrouter-replace.open [data-testid="openrouter-key-form"]'));
        self::assertCount(1, $crawler->filter('a[href="https://openrouter.ai/settings/management-keys"]'));
        self::assertStringNotContainsString(self::SECRET, (string) $client->getResponse()->getContent());
        self::assertNull($this->keys()->findOneBy(['project' => $project]));
    }

    public function test_remove_deletes_the_project_key_only_and_the_account_key_comes_back(): void
    {
        $client = $this->client();
        $owner = $this->makeUser(static::getContainer(), 'remove-project');
        $project = $this->makeProject(static::getContainer(), $owner);
        $this->makeKey(static::getContainer(), $owner, 'sk-or-v1-account-secret-a3f9');
        $this->makeKey(static::getContainer(), $project);
        $client->loginUser($owner);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->page($project));
        $client->submitForm('Remove the key');

        self::assertResponseRedirects($this->page($project));
        $this->em()->clear();
        self::assertNull($this->keys()->findOneBy(['project' => $project]));
        self::assertNotNull($this->keys()->findOneBy(['user' => $owner]));
        $crawler = $client->followRedirect();
        self::assertSame('Key in use: Your account key', trim($crawler->filter('[data-testid="openrouter-key-in-use"]')->text()));
    }

    private function page(Project $project): string
    {
        return '/projects/'.$project->id.'/settings/openrouter';
    }

    private function client(int $answer = 200): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('openrouter.client', new MockHttpClient(function () use ($answer): MockResponse {
            ++$this->calls;

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
