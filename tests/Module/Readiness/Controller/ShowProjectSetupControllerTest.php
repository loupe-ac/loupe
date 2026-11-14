<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Controller;

use App\Module\Bridge\Contract\InitRuns;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Project\Contract\ProjectMilestones;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Command\ProjectSetupState;
use App\Module\Readiness\Command\ProjectSetupView;
use App\Module\Readiness\Command\SetupMergeLine;
use App\Module\Readiness\Command\SetupMergeStatus;
use App\Tests\Module\Readiness\ReadinessScenario;
use App\Tests\Support\NotSetUp;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

final class ShowProjectSetupControllerTest extends WebTestCase
{
    use ReadinessScenario;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function test_c0_a_project_with_no_repository_shows_the_command_and_both_buttons(): void
    {
        $project = $this->gatedProject('setup-c0@example.com');

        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/setup');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="project-setup"][data-state="no-repository"]');
        self::assertSelectorTextContains('[data-testid="project-setup-command"]', 'loupe init --project '.$project->id);
        self::assertSelectorTextContains('.lp-project-setup__title', 'Set up Setup project');
        self::assertSelectorTextContains('.lp-project-setup__waiting', 'Waiting for loupe init');
        self::assertSelectorExists('a[href="/projects/'.$project->id.'/github/install"]');
        self::assertSelectorExists('form[action="/projects/'.$project->id.'/setup/skip"] input[name="_csrf_token"]');
        self::assertSelectorNotExists('[data-testid="project-setup-list"]');
    }

    public function test_c1_repositories_joined_and_no_init_run_ever_name_the_repositories(): void
    {
        $project = $this->gatedProject('setup-c1@example.com');
        $this->join($project, 'acme/api');
        $this->join($project, 'acme/web');

        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/setup');

        self::assertSelectorExists('[data-state="repositories-joined"]');
        self::assertSelectorTextContains('.lp-project-setup__title', 'acme/api and acme/web joined Setup project');
        self::assertSelectorTextContains('.lp-project-setup__lead', 'one of them. It sets up the bridge, checks the repository and asks who approves a merge.');
        self::assertSelectorExists('.lp-project-setup__header .lp-project-setup__icon');
        self::assertSelectorExists('[data-testid="project-setup-command"]');
        self::assertSelectorNotExists('a[href="/projects/'.$project->id.'/github/install"]');
        self::assertSelectorExists('form[action="/projects/'.$project->id.'/setup/skip"]');
    }

    public function test_c2_an_open_init_run_shows_the_list_with_the_bridge_waiting(): void
    {
        $project = $this->gatedProject('setup-c2@example.com');
        $this->join($project, 'acme/api');
        $this->launchInit($project);

        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/setup');

        self::assertSelectorExists('[data-state="init-running"]');
        self::assertSelectorTextContains('.lp-project-setup__title', 'loupe init is setting up Setup project');
        self::assertSelectorTextContains('[data-line="init"]', 'Done');
        self::assertSelectorTextContains('[data-line="init"]', 'loupe init started');
        self::assertSelectorTextContains('[data-line="bridge"]', 'Waiting');
        self::assertSelectorTextContains('[data-line="merge"]', 'Not asked yet');
        self::assertSelectorNotExists('[data-testid="project-setup-command"]');
    }

    public function test_c2b_an_init_run_that_closed_with_no_finish_says_init_stopped(): void
    {
        $project = $this->gatedProject('setup-c2b@example.com');
        $this->join($project, 'acme/api');
        $this->closeInit($project, $this->launchInit($project));

        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/setup');

        self::assertSelectorExists('[data-state="init-stopped"]');
        self::assertSelectorTextContains('.lp-project-setup__title', 'Setup project is not set up yet');
        self::assertSelectorTextContains('.lp-project-setup__lead', 'loupe init stopped before the setup was done. Run it again in the folder of acme/api. This page updates by itself.');
        self::assertSelectorTextContains('[data-testid="project-setup-command"]', 'loupe init --project');
        self::assertSelectorTextContains('[data-line="init"]', 'Waiting');
    }

    public function test_c2c_a_successful_init_with_no_bridge_shows_the_bridge_step_and_not_init_stopped(): void
    {
        $project = $this->gatedProject('setup-c2c@example.com');
        $this->join($project, 'acme/api');
        $this->closeInit($project, $this->launchInit($project));
        $this->milestones()->reach($project->requireId(), ProjectMilestones::INIT_FINISHED);

        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/setup');

        self::assertSelectorExists('[data-state="bridge-pending"]');
        self::assertSelectorNotExists('[data-state="init-stopped"]');
        self::assertSelectorTextContains('.lp-project-setup__title', 'Setup project is not set up yet');
        self::assertSelectorTextContains('.lp-project-setup__lead', 'loupe init finished. Start the bridge with loupe bridge run to finish the setup.');
        self::assertSelectorTextContains('[data-testid="project-setup-command"]', 'loupe bridge run');
        self::assertSelectorTextContains('[data-line="init"]', 'Done');
        self::assertSelectorTextContains('[data-line="bridge"]', 'Waiting');
        self::assertSelectorExists('form[action="/projects/'.$project->id.'/setup/skip"]');
    }

    public function test_c3_a_set_up_project_shows_every_line_done_and_a_link_to_the_project(): void
    {
        $project = $this->gatedProject('setup-c3@example.com');
        $this->join($project, 'acme/api');
        $this->milestones()->reach($project->requireId(), ProjectMilestones::INIT_FINISHED);
        $this->milestones()->reach($project->requireId(), ProjectMilestones::BRIDGE_CONNECTED);

        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/setup');

        self::assertSelectorExists('[data-state="done"]');
        self::assertSelectorTextContains('.lp-project-setup__title', 'Setup project is set up');
        self::assertSelectorTextContains('[data-line="repositories"]', 'Done');
        self::assertSelectorTextContains('[data-line="init"]', 'Done');
        self::assertSelectorTextContains('[data-line="bridge"]', 'Done');
        self::assertSelectorExists('a[href="/projects/'.$project->id.'"]');
        self::assertSelectorNotExists('form[action$="/setup/skip"]');
    }

    public function test_c4_a_viewer_who_cannot_manage_sees_the_list_and_no_command_or_button(): void
    {
        $project = $this->gatedProject('setup-c4@example.com');
        $this->join($project, 'acme/api');
        $view = new ProjectSetupView($this->em()->find(Project::class, $project->id) ?? throw new \LogicException('gone'), ProjectSetupState::Member, ['acme/api'], false, false, false, []);
        $twig = static::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render('@Readiness/setup/_project_setup_card.html.twig', ['setup' => $view]);

        self::assertStringContainsString('data-state="member"', $html);
        self::assertStringContainsString('The project owner finishes the setup with loupe init. You can open the project when it is done. This page updates by itself.', $html);
        self::assertStringContainsString('lp-project-setup__icon', $html);
        self::assertStringContainsString('data-testid="project-setup-list"', $html);
        self::assertStringNotContainsString('project-setup-command', $html);
        self::assertStringNotContainsString('<button', $html);
        self::assertStringNotContainsString('/setup/skip', $html);
    }

    public function test_c4_prints_the_merge_marker_once_for_each_repository_and_never_for_none(): void
    {
        $project = $this->gatedProject('setup-c4-lines@example.com');
        $this->join($project, 'acme/api');
        $entity = $this->em()->find(Project::class, $project->id) ?? throw new \LogicException('gone');
        $twig = static::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $template = '@Readiness/setup/_project_setup_card.html.twig';

        $filled = $twig->render($template, ['setup' => new ProjectSetupView($entity, ProjectSetupState::Member, ['acme/api'], false, false, false, [new SetupMergeLine('acme/api', SetupMergeStatus::NotAsked)])]);
        $empty = $twig->render($template, ['setup' => new ProjectSetupView($entity, ProjectSetupState::Member, [], false, false, false, [])]);

        self::assertSame(1, substr_count($filled, 'data-line="merge"'));
        self::assertSame(0, substr_count($empty, 'data-line="merge"'));
    }

    public function test_the_page_reloads_its_frame_on_the_setup_event(): void
    {
        $project = $this->gatedProject('setup-live@example.com');

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/setup');

        $live = $crawler->filter('[data-controller="worker-run-refresh"]');
        self::assertCount(1, $live);
        self::assertSame('["project.setup_changed"]', $live->attr('data-worker-run-refresh-events-value'));
        self::assertSame('/projects/'.$project->id.'/setup/list', $live->attr('data-worker-run-refresh-url-value'));
        self::assertSelectorExists('turbo-frame#project-setup-frame[data-worker-run-refresh-target="frame"]');
    }

    public function test_the_list_frame_renders_only_the_frame_and_follows_the_state(): void
    {
        $project = $this->gatedProject('setup-frame@example.com');
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/setup/list');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#project-setup-frame[data-state="no-repository"]');
        self::assertSelectorNotExists('.lp-auth');

        $this->join($project, 'acme/api');
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/setup/list');

        self::assertSelectorExists('turbo-frame#project-setup-frame[data-state="repositories-joined"]');
    }

    public function test_the_owner_skips_and_the_pages_open_again(): void
    {
        $project = $this->gatedProject('setup-skip@example.com');
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');
        self::assertResponseRedirects('/projects/'.$project->id.'/setup');

        $this->postSkip('/projects/'.$project->id.'/setup/skip');

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');
        self::assertResponseStatusCodeSame(200);
    }

    public function test_a_skip_with_no_saved_address_opens_the_start_page(): void
    {
        $project = $this->gatedProject('setup-skip-start@example.com');

        $this->postSkip('/projects/'.$project->id.'/setup/skip');

        self::assertResponseRedirects('/projects/'.$project->id);
    }

    public function test_a_skip_never_follows_an_address_of_another_project(): void
    {
        $project = $this->gatedProject('setup-skip-other@example.com');
        $other = $this->project($project->owner, 'Another project');
        NotSetUp::mark($this->em(), $other);
        $this->client->request(Request::METHOD_GET, '/projects/'.$other->id.'/settings/repositories');
        self::assertResponseRedirects('/projects/'.$other->id.'/setup');

        $this->postSkip('/projects/'.$project->id.'/setup/skip');

        self::assertResponseRedirects('/projects/'.$project->id);
    }

    public function test_a_person_who_cannot_manage_the_project_cannot_skip_it(): void
    {
        $project = $this->gatedProject('setup-skip-owner@example.com');
        $this->client->loginUser($this->user('setup-skip-stranger@example.com'));

        $this->postSkip('/projects/'.$project->id.'/setup/skip');

        self::assertResponseStatusCodeSame(403);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/setup');
        self::assertResponseStatusCodeSame(403);
    }

    public function test_a_skip_with_no_csrf_token_is_refused(): void
    {
        $project = $this->gatedProject('setup-skip-csrf@example.com');

        $this->client->request(Request::METHOD_POST, '/projects/'.$project->id.'/setup/skip');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_skip_route_checks_a_csrf_token(): void
    {
        $attributes = new \ReflectionClass(\App\Module\Readiness\Controller\SkipProjectSetupController::class)->getAttributes(\Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken::class);

        self::assertCount(1, $attributes);
        self::assertSame('project-setup-skip', $attributes[0]->newInstance()->tokenId);
        self::assertStringContainsString("- project-setup-skip\n", (string) file_get_contents(self::getContainer()->getParameter('kernel.project_dir').'/config/packages/csrf.yaml'));
    }

    /** Posts a valid same-origin CSRF token, so only the voter can refuse. */
    private function postSkip(string $path): void
    {
        $this->client->request(Request::METHOD_POST, $path, ['_csrf_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);
    }

    /** @param non-empty-string $email */
    private function gatedProject(string $email): Project
    {
        $owner = $this->user($email);
        $project = $this->project($owner, 'Setup project');
        NotSetUp::mark($this->em(), $project);
        $this->client->loginUser($owner);

        return $project;
    }

    private function join(Project $project, string $path): void
    {
        $this->em()->persist(new ForgeRepository($project, 'github', (string) random_int(1000, 999999), $path, ForgeRepositorySource::Installation, '1'));
        $this->em()->flush();
    }

    private function launchInit(Project $project): Uuid
    {
        $runs = static::getContainer()->get(InteractiveRuns::class);
        self::assertInstanceOf(InteractiveRuns::class, $runs);
        [$run] = $runs->recordLaunch($project, new WorkSubject(WorkSubject::PROJECT, $project->requireId()), null, Uuid::v4(), InitRuns::WORK_KIND, Uuid::v4());

        return $run->id ?? throw new \LogicException('A stored run has an id.');
    }

    private function closeInit(Project $project, Uuid $runId): void
    {
        $runs = static::getContainer()->get(InteractiveRuns::class);
        self::assertInstanceOf(InteractiveRuns::class, $runs);
        $runs->closeById($project, $runId);
    }

    private function milestones(): ProjectMilestones
    {
        $milestones = static::getContainer()->get(ProjectMilestones::class);
        self::assertInstanceOf(ProjectMilestones::class, $milestones);

        return $milestones;
    }
}
