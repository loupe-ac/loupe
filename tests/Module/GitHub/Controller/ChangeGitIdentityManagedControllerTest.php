<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Controller;

use App\Module\Project\Entity\Project;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Ubermuda\AuditBundle\AuditOutcome;

final class ChangeGitIdentityManagedControllerTest extends WebTestCase
{
    use GitHubConnectionsScenario;

    public function test_an_unchecked_switch_turns_the_setting_off_and_records_it(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $audit = RecordingAuditor::installedIn(static::getContainer());
        $owner = $this->signedUpUser('off');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $client->loginUser($owner);
        $this->submit($client, $project, []);

        self::assertResponseRedirects('/projects/'.$project->id.'/connect#git-identity');
        self::assertFalse($this->stored($project)->gitIdentityManaged);
        $record = $audit->record('github.git_identity_managed_changed');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertStringContainsString('Agents now use the git setup of each machine.', $client->followRedirect()->filter('body')->text());
    }

    public function test_a_checked_switch_turns_the_setting_on(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('on');
        $project = $this->projectOf($owner);
        $project->gitIdentityManaged = false;
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($owner);
        $this->submit($client, $project, ['managed' => '1']);

        self::assertResponseRedirects('/projects/'.$project->id.'/connect#git-identity');
        self::assertTrue($this->stored($project)->gitIdentityManaged);
    }

    public function test_the_turn_off_button_wins_over_a_checked_switch(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('turnoff');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $client->loginUser($owner);
        $this->submit($client, $project, ['managed' => '1', 'turnOff' => '']);

        self::assertResponseRedirects('/projects/'.$project->id.'/connect#git-identity');
        self::assertFalse($this->stored($project)->gitIdentityManaged);
    }

    public function test_a_wrong_token_changes_nothing(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('badtoken');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $client->loginUser($owner);
        $this->submit($client, $project, [], 'wrong');

        self::assertResponseRedirects('/projects/'.$project->id.'/connect#git-identity');
        self::assertTrue($this->stored($project)->gitIdentityManaged);
        self::assertStringContainsString('The form has expired', $client->followRedirect()->filter('body')->text());
    }

    public function test_a_stranger_is_forbidden_and_the_setting_stays(): void
    {
        $client = static::createClient();
        $project = $this->projectOf($this->signedUpUser('owner'));
        $stranger = $this->signedUpUser('stranger');
        $this->em()->clear();

        $client->loginUser($stranger);
        $this->submit($client, $project, []);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertTrue($this->stored($project)->gitIdentityManaged);
    }

    /** @param array<string, string> $fields */
    private function submit(KernelBrowser $client, Project $project, array $fields, string $token = 'csrf-token'): void
    {
        $client->request(
            Request::METHOD_POST,
            '/projects/'.$project->id.'/git-identity',
            ['change_git_identity_managed_form' => $fields + ['_token' => $token]],
            server: ['HTTP_ORIGIN' => 'http://localhost'],
        );
    }

    private function stored(Project $project): Project
    {
        $this->em()->clear();

        return $this->em()->find(Project::class, $project->id) ?? throw new \LogicException('The project is gone.');
    }
}
