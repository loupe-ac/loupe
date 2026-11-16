<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Mercure\CountChangeGate;
use App\Mercure\LiveUpdates;
use App\Mercure\UserTopicBuilder;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Service\InboxChangedPublisher;
use App\Module\Inbox\Service\InboxCountsSignal;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxFixtures;
use App\Tests\Support\CapturesLiveUpdates;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InboxCountsSignalTest extends KernelTestCase
{
    use CapturesLiveUpdates;
    use InboxFixtures;

    private EntityManagerInterface $em;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->captureLiveUpdates();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
        $this->switchFlag($em, LiveUpdates::FLAG, true);
        $this->project = $this->project($em, $this->owner($em, 'inbox-sidebar-signal'), 'inbox-sidebar-signal');
        $em->flush();
        $this->drainLiveUpdates();
    }

    public function test_a_change_of_the_open_rows_sends_one_signal_to_the_owner_with_the_project_only(): void
    {
        $this->changed();

        $this->item($this->em, $this->project, 1);
        $this->em->flush();
        $sent = $this->changed();

        self::assertSame(
            ['inbox.open_count_changed', InboxCountsSignal::TYPE],
            array_map(static fn (array $update): string => (string) $update['data']['type'], $sent),
        );
        $ownerId = $this->project->owner->id ?? throw new \LogicException('The owner has no id.');
        self::assertSame($this->service(UserTopicBuilder::class)->forInbox($ownerId), $sent[1]['topic']);
        self::assertSame(['type' => InboxCountsSignal::TYPE, 'projectId' => (string) $this->project->id, 'origin' => null], $sent[1]['data']);
    }

    public function test_a_change_that_leaves_the_count_sends_no_signal(): void
    {
        $this->item($this->em, $this->project, 1);
        $this->em->flush();
        $this->changed();

        $sent = $this->changed();

        self::assertSame(['inbox.open_count_changed'], array_map(static fn (array $update): string => (string) $update['data']['type'], $sent));
    }

    public function test_a_signal_moves_the_version_of_the_owner(): void
    {
        $ownerId = $this->project->owner->id ?? throw new \LogicException('The owner has no id.');
        $key = InboxCountsSignal::ownerVersionKey($ownerId);
        $gate = $this->service(CountChangeGate::class);
        $this->changed();
        $before = $gate->storedVersion($key);

        $this->changed();
        self::assertSame($before, $gate->storedVersion($key));

        $this->item($this->em, $this->project, 1);
        $this->em->flush();
        $this->changed();

        self::assertNotNull($gate->storedVersion($key));
        self::assertNotSame($before, $gate->storedVersion($key));
    }

    /** @return list<array{topic: string, data: array<string, mixed>}> */
    private function changed(): array
    {
        $this->service(InboxChangedPublisher::class)->changed($this->project);

        return array_values(array_filter(
            $this->drainLiveUpdates(),
            static fn (array $update): bool => str_starts_with((string) $update['data']['type'], 'inbox.') && 'inbox.changed' !== $update['data']['type'],
        ));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
