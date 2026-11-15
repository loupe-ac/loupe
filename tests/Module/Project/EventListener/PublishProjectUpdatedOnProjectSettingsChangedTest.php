<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\EventListener;

use App\Doctrine\SearchLanguage;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\UpdateProjectAllowedOriginsCommand;
use App\Module\Project\Command\UpdateProjectAllowedOriginsHandler;
use App\Module\Project\Command\UpdateProjectCommand;
use App\Module\Project\Command\UpdateProjectHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\ProjectEventType;
use App\Module\Readiness\Command\HideReadinessGuideCommand;
use App\Module\Readiness\Command\HideReadinessGuideHandler;
use App\Module\Readiness\Command\RecordAgentGitHubLoginCommand;
use App\Module\Readiness\Command\RecordAgentGitHubLoginHandler;
use App\Module\Readiness\Command\UpdateReadinessSettingsCommand;
use App\Module\Readiness\Command\UpdateReadinessSettingsHandler;
use App\Tests\Support\CapturesLiveUpdates;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Drives each writer of a project setting and reads what reaches the board topic. */
final class PublishProjectUpdatedOnProjectSettingsChangedTest extends KernelTestCase
{
    use CapturesLiveUpdates;

    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->captureLiveUpdates();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = new User(fullName: 'Riley', email: 'settings-changed-'.uniqid().'@example.com', password: 'x');
        $em->persist($owner);
        $this->project = new Project($owner, 'settings-'.uniqid());
        $em->persist($this->project);
        $em->flush();
        $this->drainLiveUpdates();
    }

    public function test_saving_the_project_form_publishes_project_updated(): void
    {
        $handler = self::getContainer()->get(UpdateProjectHandler::class);
        self::assertInstanceOf(UpdateProjectHandler::class, $handler);

        $handler(new UpdateProjectCommand($this->project, $this->project->name, 'shop.example.com', SearchLanguage::English, ProjectEventType::ACTOR_HUMAN));

        self::assertSame([$this->message()], $this->updated());
    }

    public function test_saving_the_allowed_origins_publishes_project_updated(): void
    {
        $handler = self::getContainer()->get(UpdateProjectAllowedOriginsHandler::class);
        self::assertInstanceOf(UpdateProjectAllowedOriginsHandler::class, $handler);

        $handler(new UpdateProjectAllowedOriginsCommand($this->project, 'https://shop.example.com'));

        self::assertSame([$this->message()], $this->updated());
    }

    public function test_saving_the_readiness_guide_publishes_project_updated(): void
    {
        $handler = self::getContainer()->get(UpdateReadinessSettingsHandler::class);
        self::assertInstanceOf(UpdateReadinessSettingsHandler::class, $handler);

        $handler(new UpdateReadinessSettingsCommand($this->project, false));

        self::assertSame([$this->message()], $this->updated());
    }

    public function test_hiding_the_readiness_guide_publishes_project_updated(): void
    {
        $handler = self::getContainer()->get(HideReadinessGuideHandler::class);
        self::assertInstanceOf(HideReadinessGuideHandler::class, $handler);

        $handler(new HideReadinessGuideCommand($this->project));

        self::assertSame([$this->message()], $this->updated());
    }

    public function test_recording_the_agent_account_publishes_project_updated(): void
    {
        $handler = self::getContainer()->get(RecordAgentGitHubLoginHandler::class);
        self::assertInstanceOf(RecordAgentGitHubLoginHandler::class, $handler);

        $handler(new RecordAgentGitHubLoginCommand($this->project, 'loupe-agent'));

        self::assertSame([$this->message()], $this->updated());
    }

    public function test_a_save_the_handler_refuses_publishes_nothing(): void
    {
        $handler = self::getContainer()->get(UpdateProjectHandler::class);
        self::assertInstanceOf(UpdateProjectHandler::class, $handler);

        try {
            $handler(new UpdateProjectCommand($this->project, '   ', null, null, ProjectEventType::ACTOR_HUMAN));
            self::fail('A blank name must be refused.');
        } catch (\App\Exception\DomainErrors) {
            // The refusal is the guard for the empty list below.
        }

        self::assertSame([], $this->updated());
    }

    /** @return array{topic: string, data: array{type: string, projectId: string, origin: null}} */
    private function message(): array
    {
        $topics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);
        $id = $this->project->id ?? throw new \LogicException('Project has no id.');

        return [
            'topic' => $topics->forBoard($id),
            'data' => ['type' => 'project.updated', 'projectId' => (string) $id, 'origin' => null],
        ];
    }

    /** @return list<array{topic: string, data: array<string, mixed>}> */
    private function updated(): array
    {
        return array_values(array_filter(
            $this->drainLiveUpdates(),
            static fn (array $update): bool => 'project.updated' === ($update['data']['type'] ?? null),
        ));
    }
}
