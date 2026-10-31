<?php

declare(strict_types=1);

namespace App\Tests\Module\OpenRouter\Service;

use App\Module\OpenRouter\Service\OpenRouterKeyResolver;
use App\Tests\Module\OpenRouter\OpenRouterScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OpenRouterKeyResolverTest extends KernelTestCase
{
    use OpenRouterScenario;

    public function test_the_project_key_wins_over_the_account_key_of_the_owner(): void
    {
        self::bootKernel();
        $owner = $this->makeUser(self::getContainer(), 'resolver-both');
        $project = $this->makeProject(self::getContainer(), $owner);
        $this->makeKey(self::getContainer(), $owner, 'sk-or-v1-account-0001');
        $projectKey = $this->makeKey(self::getContainer(), $project, 'sk-or-v1-project-7c21');

        $resolved = $this->resolver()->resolve($project);

        self::assertNotNull($resolved);
        self::assertTrue($resolved->forProject);
        self::assertSame('7c21', $resolved->lastFour);
        self::assertTrue($resolved->id->equals($projectKey->id));
    }

    public function test_the_account_key_of_the_owner_is_the_fallback(): void
    {
        self::bootKernel();
        $owner = $this->makeUser(self::getContainer(), 'resolver-account');
        $project = $this->makeProject(self::getContainer(), $owner);
        $this->makeKey(self::getContainer(), $owner, 'sk-or-v1-account-a3f9');

        $resolved = $this->resolver()->resolve($project);

        self::assertNotNull($resolved);
        self::assertFalse($resolved->forProject);
        self::assertSame('a3f9', $resolved->lastFour);
    }

    public function test_the_key_of_another_user_is_never_used(): void
    {
        self::bootKernel();
        $owner = $this->makeUser(self::getContainer(), 'resolver-owner');
        $stranger = $this->makeUser(self::getContainer(), 'resolver-stranger');
        $project = $this->makeProject(self::getContainer(), $owner);
        $this->makeKey(self::getContainer(), $stranger);
        $this->makeKey(self::getContainer(), $this->makeProject(self::getContainer(), $stranger));

        self::assertNull($this->resolver()->resolve($project));
    }

    public function test_no_key_anywhere_resolves_to_nothing(): void
    {
        self::bootKernel();
        $project = $this->makeProject(self::getContainer(), $this->makeUser(self::getContainer(), 'resolver-none'));

        self::assertNull($this->resolver()->resolve($project));
    }

    private function resolver(): OpenRouterKeyResolver
    {
        return new OpenRouterKeyResolver($this->keys());
    }
}
