<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\GitHub\Service\PickerReturn;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\InitInstallWaits;
use App\Module\Project\Service\ProjectSetupSkips;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

final class PickerReturnTest extends TestCase
{
    private Project $project;

    private Session $session;

    private InitInstallWaits $waits;

    private string $init;

    private bool $mayManage = true;

    protected function setUp(): void
    {
        $this->project = new Project(new User(fullName: 'Riley', email: 'riley@example.com', password: 'x'), 'Return');
        new \ReflectionProperty(Project::class, 'id')->setValue($this->project, Uuid::v7());
        $this->project->setUp = false;
        $this->session = new Session(new MockArraySessionStorage());
        $this->waits = new InitInstallWaits(new ArrayAdapter());
        $this->init = (string) Uuid::v4();
    }

    public function test_a_waiting_init_and_an_added_repository_go_to_the_hand_off_page_before_the_setup_page(): void
    {
        $this->waits->mark($this->init, (string) $this->project->id);

        $target = $this->pickerReturn()->target($this->project, $this->init, ['acme/api', 'acme/web']);

        self::assertTrue($target->isHandoff());
        self::assertSame('app_github_init_handoff', $target->route);
        self::assertSame(['id' => (string) $this->project->id, 'repository' => 'acme/api,acme/web', 'init' => $this->init], $target->parameters);
    }

    public function test_a_waiting_init_and_a_return_that_added_nothing_go_to_the_setup_page(): void
    {
        $this->waits->mark($this->init, (string) $this->project->id);

        $target = $this->pickerReturn()->target($this->project, $this->init, []);

        self::assertSame('app_project_setup', $target->route);
        self::assertSame(['id' => (string) $this->project->id], $target->parameters);
    }

    public function test_an_init_wait_for_another_project_does_not_count(): void
    {
        $this->waits->mark($this->init, (string) Uuid::v7());

        self::assertSame('app_project_setup', $this->pickerReturn()->target($this->project, $this->init, ['acme/api'])->route);
    }

    public function test_a_project_that_is_not_set_up_goes_to_the_setup_page_from_every_tab(): void
    {
        self::assertSame('app_project_setup', $this->pickerReturn()->target($this->project, null, ['acme/api'], 'connect')->route);
    }

    public function test_a_skipped_project_goes_to_the_tab_the_picker_started_from(): void
    {
        $this->session->set(ProjectSetupSkips::SESSION_KEY, [(string) $this->project->id]);

        self::assertSame('app_project_repositories_settings', $this->pickerReturn()->target($this->project, null, [])->route);
        self::assertSame('app_project_connect', $this->pickerReturn()->target($this->project, null, [], 'connect')->route);
    }

    public function test_a_person_who_cannot_manage_never_counts_as_skipped(): void
    {
        $this->mayManage = false;
        $this->session->set(ProjectSetupSkips::SESSION_KEY, [(string) $this->project->id]);

        self::assertSame('app_project_setup', $this->pickerReturn()->target($this->project, null, [])->route);
    }

    public function test_a_project_that_is_set_up_goes_to_the_tab(): void
    {
        $this->project->setUp = true;

        $target = $this->pickerReturn()->target($this->project, null, ['acme/api']);

        self::assertSame('app_project_repositories_settings', $target->route);
        self::assertSame(['id' => (string) $this->project->id], $target->parameters);
    }

    public function test_the_lookup_by_id_follows_the_same_order_and_survives_a_missing_project(): void
    {
        self::assertSame('app_project_setup', $this->pickerReturn()->targetOfProjectId((string) $this->project->id, null, [])->route);
        self::assertSame('app_project_repositories_settings', $this->pickerReturn(found: false)->targetOfProjectId((string) $this->project->id, null, [])->route);
        self::assertSame('app_project_connect', $this->pickerReturn()->targetOfProjectId('not-a-uuid', null, [], 'connect')->route);
    }

    private function pickerReturn(bool $found = true): PickerReturn
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(fn (): bool => $this->mayManage);
        $projects = $this->createStub(ProjectRepository::class);
        $projects->method('find')->willReturn($found ? $this->project : null);
        $request = Request::create('/');
        $request->setSession($this->session);
        $stack = new RequestStack();
        $stack->push($request);

        return new PickerReturn($this->waits, new ProjectSetupSkips($checker), $stack, $projects);
    }
}
