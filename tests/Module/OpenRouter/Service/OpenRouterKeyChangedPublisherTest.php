<?php

declare(strict_types=1);

namespace App\Tests\Module\OpenRouter\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Tests\Module\OpenRouter\RecordedOpenRouterSignals;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class OpenRouterKeyChangedPublisherTest extends TestCase
{
    public function test_an_account_key_signals_the_openrouter_topic_of_the_user(): void
    {
        $signals = new RecordedOpenRouterSignals();
        $user = $this->user();

        $signals->publisher->changed($user);

        $signals->assertNothingSentYet();
        $signals->assertSignalledUser($user->id);
    }

    public function test_a_project_key_signals_the_openrouter_topic_of_the_project(): void
    {
        $signals = new RecordedOpenRouterSignals();
        $project = $this->project($this->user());

        $signals->publisher->changed($project);

        $signals->assertNothingSentYet();
        $signals->assertSignalledProject($project->id);
    }

    public function test_a_user_without_an_id_is_a_logic_error(): void
    {
        $this->expectException(\LogicException::class);

        new RecordedOpenRouterSignals()->publisher->changed(new User(fullName: 'No Id', email: 'no-id@example.com', password: 'x'));
    }

    private function user(): User
    {
        $user = new User(fullName: 'Riley Chen', email: 'riley@example.com', password: 'x');
        new \ReflectionProperty(User::class, 'id')->setValue($user, Uuid::v7());

        return $user;
    }

    private function project(User $owner): Project
    {
        $project = new Project($owner, 'Signals');
        new \ReflectionProperty(Project::class, 'id')->setValue($project, Uuid::v7());

        return $project;
    }
}
