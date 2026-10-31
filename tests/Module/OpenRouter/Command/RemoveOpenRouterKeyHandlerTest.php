<?php

declare(strict_types=1);

namespace App\Tests\Module\OpenRouter\Command;

use App\Module\OpenRouter\Command\RemoveOpenRouterKeyCommand;
use App\Module\OpenRouter\Command\RemoveOpenRouterKeyHandler;
use App\Tests\Module\OpenRouter\OpenRouterScenario;
use App\Tests\Module\OpenRouter\RecordedOpenRouterSignals;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\NullAuditActorProvider;

final class RemoveOpenRouterKeyHandlerTest extends KernelTestCase
{
    use OpenRouterScenario;

    private RecordedOpenRouterSignals $signals;

    private RecordingAuditor $audit;

    public function test_it_removes_the_account_key_and_signals_the_user_topic(): void
    {
        self::bootKernel();
        $user = $this->makeUser(self::getContainer(), 'remove-account');
        $this->makeKey(self::getContainer(), $user);

        $this->handler()(new RemoveOpenRouterKeyCommand($user));

        self::assertSame([], $this->keys()->findBy(['user' => $user]));
        $this->signals->assertSignalledUser($user->id);
        self::assertSame('account', $this->audit->record('openrouter.key_removed')->context['scope']);
    }

    public function test_removing_the_project_key_leaves_the_account_key(): void
    {
        self::bootKernel();
        $user = $this->makeUser(self::getContainer(), 'remove-project');
        $project = $this->makeProject(self::getContainer(), $user);
        $this->makeKey(self::getContainer(), $user);
        $this->makeKey(self::getContainer(), $project);

        $this->handler()(new RemoveOpenRouterKeyCommand($project));

        $this->em()->clear();
        self::assertSame([], $this->keys()->findBy(['project' => $project]));
        self::assertCount(1, $this->keys()->findBy(['user' => $user]));
        $this->signals->assertSignalledProject($project->id);
    }

    public function test_removing_when_there_is_no_key_changes_nothing_and_signals_nothing(): void
    {
        self::bootKernel();
        $user = $this->makeUser(self::getContainer(), 'remove-none');

        $this->handler()(new RemoveOpenRouterKeyCommand($user));

        $this->signals->assertNothingSignalled();
        self::assertSame([], $this->audit->records('openrouter.key_removed'));
    }

    private function handler(): RemoveOpenRouterKeyHandler
    {
        $this->signals = new RecordedOpenRouterSignals();
        $this->audit = new RecordingAuditor(new NullAuditActorProvider());

        return new RemoveOpenRouterKeyHandler($this->keys(), $this->signals->publisher, $this->audit->auditor);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
