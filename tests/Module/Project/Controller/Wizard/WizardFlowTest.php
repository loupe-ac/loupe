<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller\Wizard;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class WizardFlowTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function createUser(EntityManagerInterface $em, string $username, string $email): User
    {
        $user = new User(ucfirst($username), $email);
        $user->password = 'hashed-password-placeholder';
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);

        return $user;
    }

    public function test_connect_without_project_bounces_to_step_one(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'flowconnectnop', 'flow-connect-nop@example.com');
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/welcome/connect');

        self::assertResponseRedirects('/welcome');
    }

    public function test_connect_for_completed_user_bounces_home(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'flowconnectdone', 'flow-connect-done@example.com');
        $user->wizardCompletedAt = new \DateTimeImmutable();
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/welcome/connect');

        self::assertResponseRedirects('/');
    }

    public function test_connect_names_the_install_and_init_commands_of_the_project(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'flowconnectok', 'flow-connect-ok@example.com');
        $project = new Project($user, 'flow-project');
        $em->persist($project);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request(Request::METHOD_GET, '/welcome/connect');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('ol[data-wizard-step="2"]');
        self::assertCount(3, $crawler->filter('ol[data-wizard-step] > li.lp-stepper__step'));
        self::assertSame('curl -fsSL http://localhost/install.sh | sh', trim($crawler->filter('[data-testid="wizard-install-command"]')->text()));
        self::assertSame('loupe init --project '.$project->id, trim($crawler->filter('[data-testid="wizard-init-command"]')->text()));
        self::assertSelectorExists('form[action$="/welcome/skip"]');
        self::assertStringNotContainsString('YOUR_TOKEN', $crawler->text());
        self::assertStringNotContainsString('Authorization: Bearer', $crawler->text());
    }

    public function test_connect_shows_four_waiting_rows_and_a_disabled_continue_before_any_bridge(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'flowrows', 'flow-rows@example.com');
        $em->persist(new Project($user, 'flow-rows-project'));
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request(Request::METHOD_GET, '/welcome/connect');

        self::assertResponseIsSuccessful();
        $panel = $crawler->filter('[data-wizard-readiness][aria-live="polite"]');
        self::assertCount(1, $panel);
        self::assertSame(['cli', 'repository_linked', 'agent', 'bridge'], $panel->filter('[data-readiness-row]')->extract(['data-readiness-row']));
        self::assertSame(['0', '0', '0', '0'], $panel->filter('[data-readiness-row]')->extract(['data-readiness-done']));
        self::assertSame(['Waiting', 'Waiting', 'Waiting', 'Waiting'], $panel->filter('.lp-status-chip')->each(static fn ($chip): string => trim($chip->text())));
        self::assertNotNull($crawler->filter('[data-wizard-continue]')->attr('disabled'));

        $live = $crawler->filter('[data-controller="worker-run-refresh"]');
        self::assertCount(1, $live);
        self::assertSame('/welcome/connect/readiness', $live->attr('data-worker-run-refresh-url-value'));
        self::assertSame('["bridge.readiness_changed"]', $live->attr('data-worker-run-refresh-events-value'));
    }

    public function test_the_readiness_partial_renders_the_panel_alone(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'flowpartial', 'flow-partial@example.com');
        $em->persist(new Project($user, 'flow-partial-project'));
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request(Request::METHOD_GET, '/welcome/connect/readiness');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#wizard-machine-rows'));
        self::assertCount(4, $crawler->filter('[data-readiness-row]'));
        self::assertCount(0, $crawler->filter('ol[data-wizard-step]'));
    }

    public function test_the_readiness_partial_bounces_a_user_with_no_project_to_step_one(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'flowpartialnop', 'flow-partial-nop@example.com');
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/welcome/connect/readiness');

        self::assertResponseRedirects('/welcome');
    }

    public function test_the_widget_and_done_steps_are_gone(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'flowgone', 'flow-gone@example.com');
        $em->persist(new Project($user, 'flow-gone-project'));
        $em->flush();

        $client->loginUser($user);
        foreach (['/welcome/widget', '/welcome/done'] as $url) {
            $client->request(Request::METHOD_GET, $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
        $client->request(Request::METHOD_POST, '/welcome/done/finish');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_skip_from_connect_completes_and_lands_on_projects(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'flowskip', 'flow-skip@example.com');
        $em->persist(new Project($user, 'flow-skip-project'));
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/welcome/connect');
        $client->submitForm('Skip for now');

        self::assertResponseRedirects('/projects');

        $em->clear();
        $fresh = $em->find(User::class, $user->id);
        self::assertInstanceOf(User::class, $fresh);
        self::assertNotNull($fresh->wizardCompletedAt);
    }

    public function test_continue_completes_the_wizard_and_lands_on_the_workshop(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'flowcontinue', 'flow-continue@example.com');
        $project = new Project($user, 'flow-continue-project');
        $em->persist($project);
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/welcome/connect');
        // The button is disabled until a bridge is ready, so the form is posted by hand.
        $token = $client->getCrawler()->filter('form[action$="/welcome/continue"] input[name="_csrf_token"]')->attr('value');
        $client->request(Request::METHOD_POST, '/welcome/continue', ['_csrf_token' => $token]);

        self::assertResponseRedirects('/projects/'.$project->id);

        $em->clear();
        $fresh = $em->find(User::class, $user->id);
        self::assertInstanceOf(User::class, $fresh);
        self::assertNotNull($fresh->wizardCompletedAt);
    }

    public function test_continue_for_completed_user_redirects_home(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'flowcontinuedone', 'flow-continue-done@example.com');
        $em->persist(new Project($user, 'flow-continue-done-project'));
        $user->wizardCompletedAt = new \DateTimeImmutable();
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/projects');
        $client->request(Request::METHOD_POST, '/welcome/continue', ['_csrf_token' => 'csrf-token']);

        self::assertResponseRedirects('/');
    }

    public function test_continue_without_a_project_goes_back_to_step_one(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'flowcontinuenop', 'flow-continue-nop@example.com');
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/projects');
        $client->request(Request::METHOD_POST, '/welcome/continue', ['_csrf_token' => 'csrf-token']);

        self::assertResponseRedirects('/welcome');
        $em->clear();
        $fresh = $em->find(User::class, $user->id);
        self::assertInstanceOf(User::class, $fresh);
        self::assertNull($fresh->wizardCompletedAt);
    }

    public function test_skip_for_completed_user_redirects_home_without_error(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'flowskipdone', 'flow-skip-done@example.com');
        // Truncated to whole seconds up front: the DB column has no fractional
        // seconds, so comparing against a microsecond-precision value created
        // in PHP would fail on the round trip regardless of handler behaviour.
        $completedAt = new \DateTimeImmutable(new \DateTimeImmutable('-1 hour')->format('Y-m-d H:i:s'));
        $user->wizardCompletedAt = $completedAt;
        $em->flush();

        $client->loginUser($user);
        // A preceding GET establishes BrowserKit history so the Referer header
        // passes SameOriginCsrfTokenManager's same-origin check for the sentinel.
        $client->request(Request::METHOD_GET, '/projects');
        $client->request(Request::METHOD_POST, '/welcome/skip', ['_csrf_token' => 'csrf-token']);

        self::assertResponseRedirects('/');

        $em->clear();
        $fresh = $em->find(User::class, $user->id);
        self::assertInstanceOf(User::class, $fresh);
        self::assertEquals($completedAt, $fresh->wizardCompletedAt);
    }
}
