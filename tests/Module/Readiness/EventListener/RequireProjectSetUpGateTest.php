<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\EventListener;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Project\Entity\Project;
use App\Module\Project\Service\InitInstallWaits;
use App\Module\Readiness\EventListener\RequireProjectSetUpListener;
use App\Tests\Module\Readiness\ReadinessScenario;
use App\Tests\Support\NotSetUp;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\DoctrineExtra\Encryption\Encryptor;

final class RequireProjectSetUpGateTest extends WebTestCase
{
    use ReadinessScenario;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function test_the_uuid_of_a_project_that_is_not_set_up_goes_to_the_setup_page_in_both_route_shapes(): void
    {
        $project = $this->gatedProject('gate-shapes@example.com');

        foreach (['/projects/'.$project->id, '/projects/'.$project->id.'/board/cards/'.Uuid::v7()] as $url) {
            $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $url);

            self::assertResponseRedirects('/projects/'.$project->id.'/setup', null, $url);
        }
    }

    public function test_the_setup_page_itself_shows(): void
    {
        $project = $this->gatedProject('gate-setup-page@example.com');

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$project->id.'/setup');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="project-setup"]', 'Gate project');
    }

    public function test_a_slug_a_name_and_an_unknown_uuid_get_the_404_of_the_page_with_no_redirect(): void
    {
        $this->gatedProject('gate-handles@example.com');
        $unknown = (string) Uuid::v7();

        foreach (['gate-project', 'Gate%20project', $unknown] as $handle) {
            foreach (['/projects/'.$handle, '/projects/'.$handle.'/board/cards/'.Uuid::v7()] as $url) {
                $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $url);

                self::assertResponseStatusCodeSame(404, $url);
            }
        }
    }

    public function test_the_hand_off_page_shows_while_init_waits_for_the_install(): void
    {
        $project = $this->gatedProject('gate-handoff@example.com');
        $managed = $this->em()->find(Project::class, $project->id) ?? throw new \LogicException('The project is gone.');
        $this->em()->persist(new ForgeRepository($managed, 'github', '101', 'acme/api', ForgeRepositorySource::Installation, '1'));
        $this->em()->flush();
        $init = (string) Uuid::v4();
        $waits = static::getContainer()->get(InitInstallWaits::class);
        self::assertInstanceOf(InitInstallWaits::class, $waits);
        $waits->mark($init, (string) $project->id);

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$project->id.'/github/init-handoff?repository=acme/api&init='.$init);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="github-init-handoff"]');
    }

    public function test_the_repositories_tab_stays_behind_the_setup_page_without_a_picker_sign_in(): void
    {
        $project = $this->gatedProject('gate-tab-closed@example.com');

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');

        self::assertResponseRedirects('/projects/'.$project->id.'/setup');
    }

    public function test_the_repositories_tab_opens_with_a_live_picker_sign_in(): void
    {
        $project = $this->gatedProject('gate-tab-open@example.com');
        static::getContainer()->set('github.api_client', new MockHttpClient(
            static fn (): MockResponse => new MockResponse('{"total_count":0,"installations":[]}', ['response_headers' => ['content-type' => 'application/json']]),
            'https://api.github.com',
        ));
        $this->writeSession([
            'github_app_install' => [
                'projectId' => (string) $project->id,
                'encryptedToken' => $this->encrypted('ghu_token'),
                'signedInAt' => time(),
            ],
        ]);

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');

        self::assertResponseIsSuccessful();
    }

    public function test_the_owner_who_skipped_the_gate_reaches_the_page(): void
    {
        $project = $this->gatedProject('gate-skipped@example.com');
        $this->writeSession([RequireProjectSetUpListener::SKIPPED_SESSION_KEY => [(string) $project->id]]);

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$project->id);

        self::assertResponseStatusCodeSame(200);
    }

    /** @param non-empty-string $email */
    private function gatedProject(string $email): Project
    {
        $owner = $this->user($email);
        $project = $this->project($owner, 'Gate project');
        NotSetUp::mark($this->em(), $project);
        $this->em()->clear();
        $this->client->loginUser($owner);

        return $project;
    }

    private function encrypted(string $token): string
    {
        $encryptor = static::getContainer()->get(Encryptor::class);
        self::assertInstanceOf(Encryptor::class, $encryptor);

        return $encryptor->encrypt($token);
    }

    /** @param array<string, mixed> $values */
    private function writeSession(array $values): void
    {
        $factory = static::getContainer()->get('session.factory');
        self::assertInstanceOf(SessionFactoryInterface::class, $factory);
        $session = $factory->createSession();
        $name = $session->getName();
        $cookie = $this->client->getCookieJar()->get($name);
        self::assertNotNull($cookie, 'loginUser starts the session.');
        $session->setId($cookie->getValue());
        $session->start();
        foreach ($values as $key => $value) {
            $session->set($key, $value);
        }
        $session->save();
        $this->client->getCookieJar()->set(new Cookie($name, $session->getId()));
    }
}
