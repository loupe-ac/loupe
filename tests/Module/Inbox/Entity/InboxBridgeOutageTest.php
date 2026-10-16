<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Entity;

use App\Module\Inbox\Entity\InboxBridgeOutage;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InboxBridgeOutageTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_an_outage_belongs_to_a_notice(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'outage-kind'), 'outage-kind');
        $question = $this->item($this->em, $project);

        $this->expectException(\InvalidArgumentException::class);

        new InboxBridgeOutage($project, $question, new \DateTimeImmutable());
    }

    public function test_a_project_holds_one_open_outage_and_any_number_of_ended_ones(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'outage-unique'), 'outage-unique');
        $ended = new InboxBridgeOutage($project, $this->notice($project, 1), new \DateTimeImmutable('2026-10-16 10:00:00'));
        $ended->endedAt = new \DateTimeImmutable('2026-10-16 10:30:00');
        $this->em->persist($ended);
        $this->em->persist(new InboxBridgeOutage($project, $this->notice($project, 2), new \DateTimeImmutable('2026-10-16 11:00:00')));
        $this->em->flush();

        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_bridge_outages'));

        $this->em->persist(new InboxBridgeOutage($project, $this->notice($project, 3), new \DateTimeImmutable('2026-10-16 12:00:00')));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->em->flush();
    }

    private function notice(\App\Module\Project\Entity\Project $project, int $number): InboxItem
    {
        $notice = new InboxItem(project: $project, number: $number, kind: InboxItemKind::Notice, title: 'No bridge can take work', blocking: false);
        $this->em->persist($notice);

        return $notice;
    }
}
