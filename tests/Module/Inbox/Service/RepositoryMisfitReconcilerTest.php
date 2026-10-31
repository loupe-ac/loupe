<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Event\ForgeRepositoryCheckChanged;
use App\Module\Forge\Repository\ForgeRepositoryCheckRepository;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxRepositoryMisfit;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Messenger\NotifyInboxOwner;
use App\Module\Inbox\Messenger\NotifyInboxOwnerKind;
use App\Module\Inbox\Repository\InboxRepositoryMisfitRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BlockingRepositories;
use App\Tests\Module\Inbox\InboxFixtures;
use App\Tests\Module\Inbox\QueuedPushes;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class RepositoryMisfitReconcilerTest extends KernelTestCase
{
    use BlockingRepositories;
    use InboxFixtures;
    use QueuedPushes;

    private const string NOW = '2026-10-27 12:00:00';

    private EntityManagerInterface $em;
    private MockClock $clock;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $this->clock);
        $this->em = $this->service(EntityManagerInterface::class);
        $this->project = $this->project($this->em, $this->owner($this->em, 'repository-misfit'), 'repository-misfit');
        $this->em->flush();
        $this->switchFlag($this->em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
    }

    public function test_a_blocking_repository_opens_one_notice_that_names_it_and_counts_its_gaps(): void
    {
        $repository = $this->repositoryWithGaps($this->project, ['no_pull_request_rule', 'check_missing']);
        $this->deliver();

        $misfit = $this->onlyMisfit();
        $item = $misfit->item;
        self::assertSame(InboxItemKind::Notice, $item->kind);
        self::assertSame(InboxItemState::Open, $item->state);
        self::assertFalse($item->blocking);
        self::assertSame(InboxAskOrigin::Loupe, $item->origin);
        self::assertSame($repository->path.' does not fit the workflow', $item->title);
        self::assertSame(
            "New work is paused until the 2 blocking gaps are fixed.\n\n- {$repository->path}: 2 blocking gaps\n\nRunning work finishes. Open the guide to fix each gap. This item closes by itself when a check finds no blocking gap.",
            $item->body,
        );
        self::assertSame([['id' => (string) $repository->id, 'path' => $repository->path, 'gaps' => 2]], $misfit->repositories);
        self::assertNull($misfit->endedAt);
        self::assertEquals([new NotifyInboxOwner((string) $item->id, NotifyInboxOwnerKind::Notice)], $this->queuedPushes());
    }

    public function test_a_project_that_holds_nothing_opens_nothing(): void
    {
        $this->repositoryWithGaps($this->project, []);

        $this->deliver();

        self::assertSame([], $this->misfits());
    }

    public function test_a_repeated_event_changes_nothing_more(): void
    {
        $this->repositoryWithGaps($this->project);
        $this->deliver();
        $misfit = $this->onlyMisfit();
        $updatedAt = $misfit->item->updatedAt;
        $this->clock->sleep(60);

        $this->deliver();
        $this->deliver();

        $again = $this->onlyMisfit();
        self::assertSame($misfit->id?->toRfc4122(), $again->id?->toRfc4122());
        self::assertEquals($updatedAt, $again->item->updatedAt);
        self::assertCount(1, $this->queuedPushes());
    }

    public function test_several_blocking_repositories_are_named_and_counted_and_the_one_that_remains_is_named_alone(): void
    {
        $first = $this->repositoryWithGaps($this->project, ['no_pull_request_rule']);
        $second = $this->repositoryWithGaps($this->project, ['no_pull_request_rule', 'check_missing', 'deletion_allowed']);
        $this->deliver();
        $misfit = $this->onlyMisfit();
        $itemId = $misfit->item->id?->toRfc4122();

        self::assertSame($first->path.', '.$second->path.' do not fit the workflow', $misfit->item->title);
        self::assertStringStartsWith('New work is paused until the 4 blocking gaps are fixed.', (string) $misfit->item->body);
        self::assertStringContainsString("- {$first->path}: 1 blocking gap\n- {$second->path}: 3 blocking gaps", (string) $misfit->item->body);
        self::assertCount(2, $misfit->repositories);

        $this->fit($first);
        $this->deliver();

        $rewritten = $this->onlyMisfit();
        self::assertSame($itemId, $rewritten->item->id?->toRfc4122(), 'The same notice is rewritten.');
        self::assertSame($second->path.' does not fit the workflow', $rewritten->item->title);
        self::assertStringContainsString("- {$second->path}: 3 blocking gaps", (string) $rewritten->item->body);
        self::assertStringNotContainsString($first->path, (string) $rewritten->item->body);
        self::assertSame([['id' => (string) $second->id, 'path' => $second->path, 'gaps' => 3]], $rewritten->repositories);
        self::assertSame(InboxItemState::Open, $rewritten->item->state);
        self::assertCount(1, $this->queuedPushes(), 'A rewrite sends no second push.');
    }

    public function test_an_empty_summary_closes_the_open_notice_and_does_nothing_when_none_is_open(): void
    {
        $repository = $this->repositoryWithGaps($this->project);
        $this->deliver();
        $this->fit($repository);

        $this->deliver();

        $misfit = $this->onlyMisfit();
        self::assertSame(InboxItemState::Resolved, $misfit->item->state);
        self::assertNull($misfit->item->note);
        self::assertSame([InboxCloser::Loupe, InboxCloseCause::FactEnded], [$misfit->item->closedBy, $misfit->item->closeCause]);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $misfit->endedAt);

        $this->deliver();

        self::assertCount(1, $this->misfits(), 'Nothing opens or closes again.');
    }

    public function test_a_late_event_of_an_older_check_leaves_the_notice_closed(): void
    {
        $repository = $this->repositoryWithGaps($this->project);
        $this->fit($repository);

        // The hold-end event of the newer check arrives first, then the hold-start event of the older one.
        $this->deliver();
        $this->deliver();

        self::assertSame([], $this->misfits());
        self::assertSame([], $this->queuedPushes());

        $this->block($repository);
        $this->deliver();
        $this->fit($repository);
        $this->deliver();
        $this->deliver();

        self::assertSame(InboxItemState::Resolved, $this->onlyMisfit()->item->state);
    }

    public function test_a_notice_that_closed_elsewhere_ends_its_row_and_a_new_one_opens_while_the_hold_lasts(): void
    {
        $this->repositoryWithGaps($this->project);
        $this->deliver();
        $first = $this->onlyMisfit();
        $first->item->state = InboxItemState::Declined;
        $this->em->flush();
        $this->clock->sleep(60);

        $this->deliver();

        $misfits = $this->misfits();
        self::assertCount(2, $misfits);
        self::assertNotNull($misfits[0]->endedAt);
        self::assertCount(1, array_filter($misfits, static fn (InboxRepositoryMisfit $misfit): bool => null === $misfit->endedAt));
    }

    public function test_the_event_listener_reconciles_the_project_of_the_event(): void
    {
        $this->repositoryWithGaps($this->project);
        $this->em->clear();

        $dispatcher = $this->service(EventDispatcherInterface::class);
        $dispatcher->dispatch(new ForgeRepositoryCheckChanged($this->project->id ?? throw new \LogicException('Persisted.')));

        self::assertCount(1, $this->misfits());
    }

    public function test_a_project_with_the_inbox_off_opens_nothing(): void
    {
        $this->switchFlag($this->em, InboxInstallFlags::FLAG_INBOX_ENABLED, false);
        $this->repositoryWithGaps($this->project);

        $this->deliver();

        self::assertSame([], $this->misfits());
    }

    private function deliver(): void
    {
        $dispatcher = $this->service(EventDispatcherInterface::class);
        $dispatcher->dispatch(new ForgeRepositoryCheckChanged($this->project->id ?? throw new \LogicException('Persisted.')));
        $this->em->clear();
        $this->project = $this->em->find(Project::class, $this->project->id) ?? throw new \LogicException('Gone.');
    }

    private function fit(ForgeRepository $repository): void
    {
        $this->setGaps($repository, []);
    }

    private function block(ForgeRepository $repository): void
    {
        $this->setGaps($repository, ['no_pull_request_rule']);
    }

    /** @param list<string> $gaps */
    private function setGaps(ForgeRepository $repository, array $gaps): void
    {
        $checks = $this->service(ForgeRepositoryCheckRepository::class);
        $check = $checks->findOneOfRepository($repository) ?? throw new \LogicException('The repository has a check.');
        $check->items = [['key' => 'approval-gate', 'mark' => 'blocking', 'state' => [] === $gaps ? 'fits' : 'gap', 'gaps' => $gaps, 'parameters' => []]];
        $this->em->flush();
    }

    /** @return list<InboxRepositoryMisfit> */
    private function misfits(): array
    {
        $this->em->clear();

        return $this->service(InboxRepositoryMisfitRepository::class)->findBy(['project' => $this->project->id], ['startedAt' => 'ASC']);
    }

    private function onlyMisfit(): InboxRepositoryMisfit
    {
        $misfits = $this->misfits();
        self::assertCount(1, $misfits);

        return $misfits[0];
    }

    private function em(): EntityManagerInterface
    {
        return $this->em;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
