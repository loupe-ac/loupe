<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\EventListener;

use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Board\Event\CardChanged;
use App\Module\Project\Entity\Project;
use App\Module\Project\Service\ProjectDeleter;
use App\Module\Review\Event\DocumentStatusChanged;
use App\Module\SiteReview\Command\AddCommentCommand;
use App\Module\SiteReview\Command\AddCommentHandler;
use App\Module\SiteReview\Event\SiteReviewCommentStatusChanged;
use App\Tests\Support\CapturesLiveUpdates;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

/** Each change that moves a row of the projects list tells the topic of the project owner. */
final class PublishProjectsChangedTest extends KernelTestCase
{
    use CapturesLiveUpdates;

    private EntityManagerInterface $em;
    private User $owner;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->captureLiveUpdates();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->owner = new User(fullName: 'Riley', email: 'projects-live-'.uniqid().'@example.com', password: 'x');
        $this->em->persist($this->owner);
        $this->project = new Project($this->owner, 'projects-live-'.uniqid());
        $this->em->persist($this->project);
        $this->em->flush();
    }

    public function test_a_new_project_publishes_once_on_the_owner_topic(): void
    {
        // The setUp flush created the project.
        self::assertSame([$this->message()], $this->changed());
    }

    public function test_a_rename_publishes(): void
    {
        $this->drainLiveUpdates();

        $this->project->name = 'Renamed '.uniqid();
        $this->em->flush();

        self::assertSame([$this->message()], $this->changed());
    }

    public function test_a_flush_that_changes_no_project_publishes_nothing(): void
    {
        // Guard: the create reached the hub.
        self::assertNotSame([], $this->changed());

        $this->em->flush();

        self::assertSame([], $this->changed());
    }

    public function test_a_deleted_project_publishes(): void
    {
        $this->drainLiveUpdates();
        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);

        $deleter->delete($this->project);

        self::assertSame([$this->message()], $this->changed());
    }

    public function test_a_card_change_publishes(): void
    {
        $this->drainLiveUpdates();

        $this->dispatch(new CardChanged($this->projectId(), Uuid::v7(), CardChanged::UPDATED, false));

        self::assertSame([$this->message()], $this->changed());
    }

    public function test_a_document_status_change_publishes(): void
    {
        $this->drainLiveUpdates();

        $this->dispatch(new DocumentStatusChanged($this->projectId(), Uuid::v7()));

        self::assertSame([$this->message()], $this->changed());
    }

    public function test_a_new_site_review_note_publishes(): void
    {
        $this->drainLiveUpdates();
        $add = self::getContainer()->get(AddCommentHandler::class);
        self::assertInstanceOf(AddCommentHandler::class, $add);

        $add(new AddCommentCommand($this->project, 'the launcher overlaps the footer', 'https://preview/x'));

        self::assertSame([$this->message()], $this->changed());
    }

    public function test_a_site_review_status_change_publishes(): void
    {
        $this->drainLiveUpdates();

        $this->dispatch(new SiteReviewCommentStatusChanged($this->projectId(), Uuid::v7()));

        self::assertSame([$this->message()], $this->changed());
    }

    public function test_an_event_of_a_project_that_is_gone_publishes_nothing(): void
    {
        $this->drainLiveUpdates();

        $this->dispatch(new CardChanged(Uuid::v7(), Uuid::v7(), CardChanged::DELETED, false));

        self::assertSame([], $this->changed());
    }

    private function dispatch(object $event): void
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch($event);
    }

    private function projectId(): Uuid
    {
        return $this->project->id ?? throw new \LogicException('Project has no id.');
    }

    /** @return array{topic: string, data: array{type: string, area: string, origin: null}} */
    private function message(): array
    {
        $topics = self::getContainer()->get(UserTopicBuilder::class);
        self::assertInstanceOf(UserTopicBuilder::class, $topics);

        return [
            'topic' => $topics->forAccount($this->owner->id ?? throw new \LogicException('Owner has no id.')),
            'data' => ['type' => 'account.changed', 'area' => 'projects', 'origin' => null],
        ];
    }

    /** @return list<array{topic: string, data: array<string, mixed>}> */
    private function changed(): array
    {
        return array_values(array_filter(
            $this->drainLiveUpdates(),
            static fn (array $update): bool => 'account.changed' === ($update['data']['type'] ?? null),
        ));
    }
}
