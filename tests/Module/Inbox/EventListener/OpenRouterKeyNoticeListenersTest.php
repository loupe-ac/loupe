<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\EventListener;

use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxOpenRouterKeyNotice;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Repository\InboxOpenRouterKeyNoticeRepository;
use App\Module\OpenRouter\Event\OpenRouterKeyRefused;
use App\Module\OpenRouter\Event\OpenRouterKeyRemoved;
use App\Module\OpenRouter\Event\OpenRouterKeyWorks;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class OpenRouterKeyNoticeListenersTest extends KernelTestCase
{
    use InboxFixtures;

    private EventDispatcherInterface $events;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $events = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $this->events = $events;

        $this->project = $this->project($em, $this->owner($em, 'key-listeners'), 'key-listeners');
        $em->flush();
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
    }

    public function test_a_refused_key_opens_a_notice_and_a_working_key_closes_it(): void
    {
        $keyId = Uuid::v7();

        $this->events->dispatch(new OpenRouterKeyRefused($keyId, false, $this->project->requireId()));
        self::assertSame([null], $this->endedAts());

        $this->events->dispatch(new OpenRouterKeyWorks($keyId, false, $this->project->requireId()));
        $notice = $this->only();
        self::assertNotNull($notice->endedAt);
        self::assertSame(InboxItemState::Done, $notice->item->state);
    }

    public function test_a_removed_key_closes_its_notice_as_obsolete(): void
    {
        $keyId = Uuid::v7();
        $this->events->dispatch(new OpenRouterKeyRefused($keyId, true, $this->project->requireId()));

        $this->events->dispatch(new OpenRouterKeyRemoved($keyId));

        self::assertSame(InboxItemState::Obsolete, $this->only()->item->state);
    }

    /** @return list<?\DateTimeImmutable> */
    private function endedAts(): array
    {
        return array_map(static fn (InboxOpenRouterKeyNotice $notice): ?\DateTimeImmutable => $notice->endedAt, $this->repository()->findAll());
    }

    private function only(): InboxOpenRouterKeyNotice
    {
        $all = $this->repository()->findAll();
        self::assertCount(1, $all);

        return $all[0];
    }

    private function repository(): InboxOpenRouterKeyNoticeRepository
    {
        $repository = self::getContainer()->get(InboxOpenRouterKeyNoticeRepository::class);
        self::assertInstanceOf(InboxOpenRouterKeyNoticeRepository::class, $repository);

        return $repository;
    }
}
