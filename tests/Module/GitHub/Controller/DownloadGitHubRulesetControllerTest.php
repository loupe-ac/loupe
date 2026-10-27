<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Controller;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Settings\RepositoryRules;
use App\Module\Forge\Settings\RepositorySettings;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class DownloadGitHubRulesetControllerTest extends WebTestCase
{
    use GitHubConnectionsScenario;

    public function test_the_owner_downloads_the_import_file_as_an_attachment(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('download');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->checkOf($repository, ['approval-gate', 'ci-checks', 'branch-protection'], appId: 4242, recent: ['lint', 'phpunit']);
        $this->em()->clear();

        $client->loginUser($owner);
        $this->fetch($client, $project->id, $repository->id);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=loupe-ruleset.json');
        $file = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('Loupe', $file['name']);
        self::assertSame(['~DEFAULT_BRANCH'], $file['conditions']['ref_name']['include']);
        $types = array_column($file['rules'], 'type');
        self::assertSame(['pull_request', 'required_status_checks', 'non_fast_forward', 'deletion'], $types);
        $checks = $file['rules'][1]['parameters']['required_status_checks'];
        self::assertSame(['context' => 'Loupe / Approval', 'integration_id' => 4242], $checks[0]);
        self::assertSame(['lint', 'phpunit'], array_column(\array_slice($checks, 1), 'context'));
    }

    public function test_a_check_with_nothing_to_import_is_not_found(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('nothing');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->checkOf($repository, ['merge-method'], appId: 4242, recent: []);
        $this->em()->clear();

        $client->loginUser($owner);
        $this->fetch($client, $project->id, $repository->id);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function test_a_repository_with_no_stored_check_is_not_found(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('unchecked');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->em()->clear();

        $client->loginUser($owner);
        $this->fetch($client, $project->id, $repository->id);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function test_a_stranger_is_forbidden_and_gets_no_file(): void
    {
        $client = static::createClient();
        $project = $this->projectOf($this->signedUpUser('owner'));
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->checkOf($repository, ['approval-gate'], appId: 4242, recent: []);
        $stranger = $this->signedUpUser('stranger');
        $this->em()->clear();

        $client->loginUser($stranger);
        $this->fetch($client, $project->id, $repository->id);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertStringNotContainsString('Loupe / Approval', (string) $client->getResponse()->getContent());
    }

    public function test_a_repository_of_another_project_is_not_found(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('mine');
        $project = $this->projectOf($owner);
        $theirs = $this->repositoryOf($this->projectOf($this->signedUpUser('theirs')), 'rival/app');
        $this->checkOf($theirs, ['approval-gate'], appId: 4242, recent: []);
        $this->em()->clear();

        $client->loginUser($owner);
        $this->fetch($client, $project->id, $theirs->id);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function fetch(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, ?Uuid $projectId, ?Uuid $repositoryId): void
    {
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/repositories/'.$repositoryId.'/loupe-ruleset.json');
    }

    /**
     * @param list<string> $keys
     * @param list<string> $recent
     */
    private function checkOf(ForgeRepository $repository, array $keys, ?int $appId, array $recent): ForgeRepositoryCheck
    {
        $check = new ForgeRepositoryCheck($repository, $repository->project);
        $check->checkedAt = new \DateTimeImmutable();
        $check->items = array_map(static fn (string $key): array => ['key' => $key, 'mark' => 'blocking', 'state' => 'gap', 'gaps' => ['no_pull_request_rule'], 'parameters' => []], $keys);
        $settings = new RepositorySettings('main', ['squash'], $appId, new RepositoryRules(false, false, null, [], [], false, false), [], $recent, false);
        $check->settings = json_decode(json_encode($settings, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        $this->em()->persist($check);
        $this->em()->flush();

        return $check;
    }
}
