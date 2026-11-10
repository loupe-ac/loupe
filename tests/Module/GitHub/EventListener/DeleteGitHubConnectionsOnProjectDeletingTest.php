<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\EventListener;

use App\Module\Account\Entity\User;
use App\Module\GitHub\Entity\GitHubHook;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\EventListener\DeleteGitHubConnectionsOnProjectDeleting;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The listener runs alone here. Through ProjectDeleter the foreign key's
 * cascade would remove the rows too, and hide a listener that did not.
 */
final class DeleteGitHubConnectionsOnProjectDeletingTest extends KernelTestCase
{
    public function test_it_deletes_the_hook_of_its_project_only_and_keeps_every_installation(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $listener = self::getContainer()->get(DeleteGitHubConnectionsOnProjectDeleting::class);
        self::assertInstanceOf(DeleteGitHubConnectionsOnProjectDeleting::class, $listener);

        $owner = new User(fullName: 'Riley', email: 'github-delete-'.uniqid().'@example.com', password: 'hashed');
        $doomed = new Project($owner, 'doomed-'.uniqid());
        $spared = new Project($owner, 'spared-'.uniqid());
        foreach ([
            $owner, $doomed, $spared,
            new GitHubHook($doomed, GitHubHook::newKey(), GitHubHook::newSecret()),
            new GitHubHook($spared, GitHubHook::newKey(), GitHubHook::newSecret()),
            new GitHubInstallation(8_000_001, 'acme', GitHubRepositorySelection::All),
            new GitHubInstallation(8_000_002, 'acme', GitHubRepositorySelection::Selected),
            new GitHubInstallation(8_000_003, 'acme', GitHubRepositorySelection::All),
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $conn = $em->getConnection();
        $hooks = static fn (Project $project): int => (int) $conn->fetchOne('SELECT COUNT(*) FROM github_hooks WHERE project_id = :id', ['id' => (string) $project->id]);
        $installations = static fn (): int => (int) $conn->fetchOne('SELECT COUNT(*) FROM github_installations WHERE installation_id BETWEEN 8000001 AND 8000003');
        self::assertSame(1, $hooks($doomed));
        self::assertSame(3, $installations());

        $listener(new ProjectDeleting($doomed));

        self::assertSame(0, $hooks($doomed));
        self::assertSame(1, $hooks($spared));
        self::assertSame(3, $installations(), 'An installation is shared, so a project deletion keeps it.');
    }
}
