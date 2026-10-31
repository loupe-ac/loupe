<?php

declare(strict_types=1);

namespace App\Tests\Module\OpenRouter\Repository;

use App\Tests\Module\OpenRouter\OpenRouterScenario;
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
        self::assertTrue($this->keys()->lockIfPresent($projectKey->id ?? throw new \LogicException('A stored key has an id.')));
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
        self::assertTrue($this->keys()->lockIfPresent($key->id ?? throw new \LogicException('A stored key has an id.')));
    }
}
