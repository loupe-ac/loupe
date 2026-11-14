<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\EventListener;

use App\Module\Account\Entity\User;
use App\Module\GitHub\Service\GitHubInstallSession;
use App\Module\GitHub\Service\LivePickerSignIn;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Service\ProjectSetupSkips;
use App\Module\Readiness\EventListener\RequireProjectSetUpListener;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\DoctrineExtra\Encryption\EncryptionKeyProvider;
use Ubermuda\DoctrineExtra\Encryption\Encryptor;

final class RequireProjectSetUpListenerTest extends TestCase
{
    private Session $session;

    private Project $project;

    private GitHubInstallSession $installSession;

    private bool $mayManage = true;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->project = new Project(new User(fullName: 'Riley', email: 'riley@example.com', password: 'x'), 'Gate');
        new \ReflectionProperty(Project::class, 'id')->setValue($this->project, Uuid::v7());
        $this->project->setUp = false;
        $stack = new RequestStack();
        $stack->push($this->request('/'));
        $this->installSession = new GitHubInstallSession(
            $stack,
            new Encryptor(new EncryptionKeyProvider([base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES))])),
            new MockClock('2026-11-10 12:00:00'),
            new RecordingLogger(),
        );
    }

    public function test_a_viewer_who_cannot_manage_meets_the_gate_even_when_the_skip_list_holds_the_project(): void
    {
        $this->mayManage = false;
        $this->session->set(RequireProjectSetUpListener::SKIPPED_SESSION_KEY, [(string) $this->project->id]);

        $response = $this->gate($this->pageRequest('app_project_board'));

        self::assertSame('/projects/'.$this->project->id.'/setup', $response?->headers->get('Location'));
    }

    public function test_the_owner_who_skipped_passes(): void
    {
        $this->session->set(RequireProjectSetUpListener::SKIPPED_SESSION_KEY, [(string) $this->project->id]);

        self::assertNull($this->gate($this->pageRequest('app_project_board')));
    }

    public function test_the_owner_who_skipped_another_project_is_redirected_and_the_address_is_kept(): void
    {
        $this->session->set(RequireProjectSetUpListener::SKIPPED_SESSION_KEY, [(string) Uuid::v7()]);
        $request = $this->pageRequest('app_project_board');

        $response = $this->gate($request);

        self::assertNotNull($response);
        self::assertSame($request->getRequestUri(), $this->session->get(RequireProjectSetUpListener::INTENDED_PATH_SESSION_KEY));
    }

    public function test_a_set_up_project_passes(): void
    {
        $this->project->setUp = true;

        self::assertNull($this->gate($this->pageRequest('app_project_board')));
    }

    public function test_the_exempt_routes_pass(): void
    {
        foreach (['app_project_setup', 'app_project_setup_list', 'app_github_repositories_start', 'app_github_app_install', 'app_github_init_handoff'] as $route) {
            self::assertNull($this->gate($this->pageRequest($route)), $route);
        }
    }

    public function test_the_repositories_tab_opens_only_with_a_live_picker_sign_in(): void
    {
        self::assertNotNull($this->gate($this->pageRequest('app_project_repositories_settings')), 'Without a sign-in the tab stays behind the setup page.');

        $pending = $this->installSession->beginSignIn($this->project, null);
        $this->installSession->signIn($pending, 'ghu_token');

        self::assertNull($this->gate($this->pageRequest('app_project_repositories_settings')));
        self::assertNotNull($this->gate($this->pageRequest('app_project_settings')), 'Another settings page stays gated.');
    }

    public function test_a_sign_in_for_another_project_does_not_open_the_tab(): void
    {
        $other = new Project($this->project->owner, 'Other');
        new \ReflectionProperty(Project::class, 'id')->setValue($other, Uuid::v7());
        $this->installSession->signIn($this->installSession->beginSignIn($other, null), 'ghu_token');

        self::assertNotNull($this->gate($this->pageRequest('app_project_repositories_settings')));
    }

    public function test_a_person_who_cannot_view_the_project_passes_through(): void
    {
        self::assertNull($this->gate($this->pageRequest('app_project_board'), mayView: false));
    }

    public function test_a_project_that_does_not_exist_passes_through(): void
    {
        self::assertNull($this->gate($this->pageRequest('app_project_board'), found: false));
    }

    public function test_a_value_that_is_not_a_uuid_never_reaches_a_lookup(): void
    {
        $request = $this->request('/projects/my-slug/board');
        $request->attributes->set('project', 'my-slug');

        $projects = $this->createMock(ProjectRepository::class);
        $projects->expects($this->never())->method('find');

        self::assertNull($this->invoke($request, $projects, true));
    }

    public function test_a_post_and_a_turbo_stream_never_meet_the_gate(): void
    {
        $post = $this->pageRequest('app_project_board');
        $post->setMethod('POST');
        $stream = $this->pageRequest('app_project_board');
        $stream->headers->set('Accept', 'text/vnd.turbo-stream.html');

        self::assertNull($this->gate($post));
        self::assertNull($this->gate($stream));
    }

    public function test_the_page_a_turbo_form_post_redirects_to_meets_the_gate(): void
    {
        $followed = $this->pageRequest('app_project_board');
        $followed->headers->set('Accept', 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml');

        self::assertNotNull($this->gate($followed));
    }

    public function test_a_path_outside_the_projects_never_meets_the_gate(): void
    {
        $request = $this->request('/account');
        $request->attributes->set('projectId', (string) $this->project->id);

        self::assertNull($this->gate($request));
    }

    public function test_the_value_is_read_from_both_route_shapes(): void
    {
        $id = (string) $this->project->id;
        $owned = $this->request('/projects/'.$id);
        $owned->attributes->set('project', $id);
        $scoped = $this->request('/projects/'.$id.'/cards/1');
        $scoped->attributes->set('projectId', $id);

        self::assertSame($id, RequireProjectSetUpListener::projectValue($owned));
        self::assertSame($id, RequireProjectSetUpListener::projectValue($scoped));
        self::assertNull(RequireProjectSetUpListener::projectValue($this->request('/projects/new')));
    }

    private function pageRequest(string $route): Request
    {
        $request = $this->request('/projects/'.$this->project->id.'/page');
        $request->attributes->set('_route', $route);
        $request->attributes->set('project', (string) $this->project->id);

        return $request;
    }

    private function request(string $path): Request
    {
        $request = Request::create($path);
        $request->headers->set('Accept', 'text/html');
        $request->setSession($this->session);

        return $request;
    }

    private function gate(Request $request, bool $mayView = true, bool $found = true): ?Response
    {
        $projects = $this->createStub(ProjectRepository::class);
        $projects->method('find')->willReturn($found ? $this->project : null);

        return $this->invoke($request, $projects, $mayView);
    }

    private function invoke(Request $request, ProjectRepository $projects, bool $mayView): ?Response
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($this->project->owner, 'main', []));
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(
            fn (string $attribute): bool => match ($attribute) {
                ProjectVoter::VIEW => $mayView,
                ProjectVoter::MANAGE => $mayView && $this->mayManage,
                default => false,
            },
        );
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            static fn (string $name, array $parameters): string => '/projects/'.$parameters['id'].'/setup',
        );
        $listener = new RequireProjectSetUpListener($tokens, $checker, $projects, $urls, new LivePickerSignIn($this->installSession), new ProjectSetupSkips($checker));
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

        $listener($event);

        return $event->getResponse();
    }
}
