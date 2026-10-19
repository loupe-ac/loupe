<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\SeedGitIdentitiesCommand;
use App\Module\Bridge\Command\SeedGitIdentitiesHandler;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\GitHub\Repository\GitHubUserConnectionRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SeedGitIdentitiesHandlerTest extends KernelTestCase
{
    public function test_it_seeds_one_bridge_for_each_state_and_can_run_again(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User(fullName: 'Riley', email: 'seed-git-identities-'.uniqid().'@example.com', password: 'x');
        AcceptedTerms::stamp($user, self::getContainer());
        $project = new Project($user, 'seed-git-identities-'.uniqid());
        $project->agentGitHubLogin = 'acme-agent';
        $em->persist($user);
        $em->persist($project);
        $em->flush();
        $handler = $this->handler($em);

        $names = $handler(new SeedGitIdentitiesCommand((string) $project->id));
        $handler(new SeedGitIdentitiesCommand((string) $project->id));

        self::assertSame(['seed-app', 'seed-agent', 'seed-separate', 'seed-owner', 'seed-unknown', 'seed-older-cli'], $names);
        $bridges = self::getContainer()->get(BridgeRepository::class)->findFollowingProject($project);
        self::assertCount(6, $bridges);
        $modes = [];
        foreach ($bridges as $bridge) {
            if ('seed-older-cli' === $bridge->name) {
                self::assertNull($bridge->gitIdentities);
                continue;
            }
            $identities = $bridge->gitIdentities ?? [];
            self::assertCount(1, $identities);
            $modes[(string) $bridge->name] = $identities[0]['mode'] ?? null;
        }
        self::assertSame('app_token', $modes['seed-app']);
        self::assertSame('agent_account', $modes['seed-agent']);
        self::assertSame('machine', $modes['seed-unknown']);
    }

    public function test_an_unknown_project_is_refused(): void
    {
        $this->expectException(DomainErrors::class);

        $this->handler(self::getContainer()->get(EntityManagerInterface::class))(new SeedGitIdentitiesCommand('nope'));
    }

    private function handler(EntityManagerInterface $em): SeedGitIdentitiesHandler
    {
        $container = self::getContainer();

        return new SeedGitIdentitiesHandler(
            $container->get(ProjectRepository::class),
            $container->get(BridgeRepository::class),
            $container->get(GitHubUserConnectionRepository::class),
            $em,
        );
    }
}
