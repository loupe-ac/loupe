<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Repository\ForgeRepositoryCheckRepository;
use App\Module\Forge\Settings\RepositoryRules;
use App\Module\Forge\Settings\RepositorySettings;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Command\BindWorkflowTemplateHandler;
use App\Module\Workflow\Entity\WorkflowRepositorySetting;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\AgentCredential;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class ListSitesGitHubFactsTest extends WebTestCase
{
    public function test_a_project_with_no_github_link_reports_no_app_and_no_repository(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        $owner = $this->user($em, 'github-facts-none@example.com');
        $em->persist(new Project($owner, 'github-facts-none'));
        $em->flush();

        $site = $this->onlySite($client, $owner);

        self::assertSame(['appInstalled' => false, 'repositories' => []], $site['github']);
    }

    public function test_the_facts_come_from_the_stored_check_and_the_resolved_setting(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        $owner = $this->user($em, 'github-facts-read@example.com');
        $project = new Project($owner, 'github-facts-read');
        $em->persist($project);
        $em->persist(new GitHubInstallation($project, random_int(1, \PHP_INT_MAX), 'acme', GitHubRepositorySelection::All));
        $em->flush();
        $bind = static::getContainer()->get(BindWorkflowTemplateHandler::class);
        self::assertInstanceOf(BindWorkflowTemplateHandler::class, $bind);
        $columns = [];
        foreach (['next', 'product-design', 'tech-design', 'implementation', 'in-review'] as $position => $slug) {
            $column = new BoardColumn(project: $project, label: $slug, slug: $slug, position: 10 + $position);
            $em->persist($column);
            $columns[$slug] = $column;
        }
        $em->flush();
        $bind(new BindWorkflowTemplateCommand($project, 'lifecycle', array_map(static fn (BoardColumn $column): Uuid => $column->id ?? throw new \LogicException('flushed'), $columns)));

        $read = $this->repository($em, $project, 'acme/read', new RepositorySettings('trunk', ['squash'], null, new RepositoryRules(true, true, null, [], [], false, false), [], [], false));
        $em->persist(new WorkflowRepositorySetting($project, $read->id ?? throw new \LogicException('flushed'), 'merge-approval', '"agent"'));
        $this->repository($em, $project, 'acme/limited', new RepositorySettings('main', ['squash'], null, new RepositoryRules(false, false, null, [], [], false, false), [], [], false, 'Upgrade to GitHub Pro'));
        $refused = $this->repository($em, $project, 'acme/refused', null);
        $this->check($em, $refused, $project)->failure = 'api_failed_http_status';
        $this->repository($em, $project, 'acme/unread', null, check: false);
        $em->flush();

        $site = $this->onlySite($client, $owner);

        self::assertTrue($site['github']['appInstalled']);
        $byPath = array_column($site['github']['repositories'], null, 'path');
        self::assertSame(
            ['path' => 'acme/read', 'defaultBranch' => 'trunk', 'rulesRead' => 'ok', 'reviewRequired' => true, 'mergeApproval' => 'agent', 'mergeApprovalSource' => 'stored'],
            $byPath['acme/read'],
        );
        self::assertSame('plan-limited', $byPath['acme/limited']['rulesRead']);
        self::assertFalse($byPath['acme/limited']['reviewRequired']);
        self::assertNull($byPath['acme/limited']['mergeApproval']);
        self::assertSame('unset', $byPath['acme/limited']['mergeApprovalSource']);
        self::assertSame('refused', $byPath['acme/refused']['rulesRead']);
        self::assertNull($byPath['acme/refused']['reviewRequired']);
        self::assertNull($byPath['acme/unread']['rulesRead']);
        self::assertNull($byPath['acme/unread']['defaultBranch']);
        self::assertCount(4, $byPath);
    }

    public function test_a_removed_installation_is_not_installed_and_another_forge_is_left_out(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        $owner = $this->user($em, 'github-facts-removed@example.com');
        $project = new Project($owner, 'github-facts-removed');
        $em->persist($project);
        $installation = new GitHubInstallation($project, random_int(1, \PHP_INT_MAX), 'acme', GitHubRepositorySelection::All);
        $installation->removedAt = new \DateTimeImmutable();
        $em->persist($installation);
        $em->persist(new ForgeRepository($project, 'gitlab', 'gl-1', 'acme/gitlab', ForgeRepositorySource::Hook));
        $em->flush();

        $site = $this->onlySite($client, $owner);

        self::assertSame(['appInstalled' => false, 'repositories' => []], $site['github']);
    }

    /** @return array<string, mixed> */
    private function onlySite(KernelBrowser $client, User $owner): array
    {
        $raw = AgentCredential::agentToken(static::getContainer(), $owner);
        $client->request(Request::METHOD_GET, '/api/v1/bridge/projects', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertCount(1, $data['sites']);

        return $data['sites'][0];
    }

    private function repository(EntityManagerInterface $em, Project $project, string $path, ?RepositorySettings $settings, bool $check = true): ForgeRepository
    {
        $repository = new ForgeRepository($project, 'github', 'ext-'.$path, $path, ForgeRepositorySource::Installation);
        $em->persist($repository);
        if ($check) {
            $this->check($em, $repository, $project)->settings = null === $settings ? null : json_decode(json_encode($settings, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        }
        $em->flush();

        return $repository;
    }

    private function check(EntityManagerInterface $em, ForgeRepository $repository, Project $project): ForgeRepositoryCheck
    {
        $checks = static::getContainer()->get(ForgeRepositoryCheckRepository::class);
        self::assertInstanceOf(ForgeRepositoryCheckRepository::class, $checks);
        $existing = $checks->findOneOfRepository($repository);
        if ($existing instanceof ForgeRepositoryCheck) {
            return $existing;
        }
        $check = new ForgeRepositoryCheck($repository, $project);
        $em->persist($check);

        return $check;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(fullName: 'Owner', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);

        return $user;
    }
}
