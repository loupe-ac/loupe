<?php

declare(strict_types=1);

namespace App\Tests\Module\OpenRouter\EventListener;

use App\Module\OpenRouter\EventListener\DeleteOpenRouterKeyOnProjectDeleting;
use App\Module\Project\Event\ProjectDeleting;
use App\Tests\Module\OpenRouter\OpenRouterScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The listener runs alone here. Through ProjectDeleter the foreign key's
 * cascade would remove the row too, and hide a listener that did not.
 */
final class DeleteOpenRouterKeyOnProjectDeletingTest extends KernelTestCase
{
    use OpenRouterScenario;

    public function test_it_deletes_the_key_of_its_project_only(): void
    {
        self::bootKernel();
        $owner = $this->makeUser(self::getContainer(), 'deleting');
        $doomed = $this->makeProject(self::getContainer(), $owner);
        $spared = $this->makeProject(self::getContainer(), $owner);
        $this->makeKey(self::getContainer(), $doomed);
        $this->makeKey(self::getContainer(), $spared);
        $this->makeKey(self::getContainer(), $owner);
        $listener = self::getContainer()->get(DeleteOpenRouterKeyOnProjectDeleting::class);
        self::assertInstanceOf(DeleteOpenRouterKeyOnProjectDeleting::class, $listener);
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $count = static fn (string $column, ?string $id): int => (int) $connection->fetchOne('SELECT COUNT(*) FROM openrouter_keys WHERE '.$column.' = :id', ['id' => $id]);
        self::assertSame(1, $count('project_id', (string) $doomed->id));

        $listener(new ProjectDeleting($doomed));

        self::assertSame(0, $count('project_id', (string) $doomed->id));
        self::assertSame(1, $count('project_id', (string) $spared->id));
        self::assertSame(1, $count('user_id', (string) $owner->id));
    }
}
