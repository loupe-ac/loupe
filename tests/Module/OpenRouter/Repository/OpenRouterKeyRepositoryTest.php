<?php

declare(strict_types=1);

namespace App\Tests\Module\OpenRouter\Repository;

use App\Module\OpenRouter\Entity\OpenRouterKeyState;
use App\Tests\Module\OpenRouter\OpenRouterScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OpenRouterKeyRepositoryTest extends KernelTestCase
{
    use OpenRouterScenario;

    public function test_the_account_key_delete_returns_the_id_of_the_row_it_removed(): void
    {
        self::bootKernel();
        $user = $this->makeUser(self::getContainer(), 'delete-account');
        $project = $this->makeProject(self::getContainer(), $user);
        $key = $this->makeKey(self::getContainer(), $user);
        $projectKey = $this->makeKey(self::getContainer(), $project);

        self::assertEquals($key->id, $this->keys()->deleteReturningIdForUser($user->id ?? throw new \LogicException('A stored user has an id.')));
        self::assertNull($this->keys()->deleteReturningIdForUser($user->id));
        self::assertSame(OpenRouterKeyState::Working, $this->keys()->lockState($projectKey->id ?? throw new \LogicException('A stored key has an id.')));
    }

    public function test_the_project_key_delete_returns_the_id_of_the_row_it_removed(): void
    {
        self::bootKernel();
        $user = $this->makeUser(self::getContainer(), 'delete-project');
        $project = $this->makeProject(self::getContainer(), $user);
        $key = $this->makeKey(self::getContainer(), $user);
        $projectKey = $this->makeKey(self::getContainer(), $project);

        self::assertEquals($projectKey->id, $this->keys()->deleteReturningIdForProject($project->requireId()));
        self::assertNull($this->keys()->deleteReturningIdForProject($project->requireId()));
        self::assertSame(OpenRouterKeyState::Working, $this->keys()->lockState($key->id ?? throw new \LogicException('A stored key has an id.')));
    }

    public function test_the_lock_reads_the_stored_state_and_null_for_a_gone_key(): void
    {
        self::bootKernel();
        $user = $this->makeUser(self::getContainer(), 'lock-state');
        $key = $this->makeKey(self::getContainer(), $user);
        $keyId = $key->id ?? throw new \LogicException('A stored key has an id.');
        $key->state = OpenRouterKeyState::Refused;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertSame(OpenRouterKeyState::Refused, $this->keys()->lockState($keyId));
        $this->keys()->deleteReturningIdForUser($user->id ?? throw new \LogicException('A stored user has an id.'));
        self::assertNull($this->keys()->lockState($keyId));
    }
}
