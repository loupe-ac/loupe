<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Service\InboxActionAccountPurger;
use App\Module\Inbox\Service\InboxActionExporter;
use App\Module\Project\Service\ProjectAccountPurger;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InboxActionAccountDataTest extends KernelTestCase
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

    public function test_the_purger_deletes_the_actions_of_the_user_only(): void
    {
        $owner = $this->owner($this->em, 'purge-owner');
        $requester = $this->owner($this->em, 'purge-requester');
        $item = $this->item($this->em, $this->project($this->em, $owner, 'purge'));
        $theirs = new InboxAction($item, InboxActionKind::Open, $requester);
        $mine = new InboxAction($item, InboxActionKind::Dismiss, $owner);
        $this->em->persist($theirs);
        $this->em->persist($mine);
        $this->em->flush();
        $theirsId = (string) $theirs->id;
        $mineId = (string) $mine->id;

        $purger = self::getContainer()->get(InboxActionAccountPurger::class);
        self::assertInstanceOf(InboxActionAccountPurger::class, $purger);
        $purger->purge($requester, new AccountDeletionCleanup());

        $connection = $this->em->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM inbox_actions WHERE id = :id', ['id' => $theirsId]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM inbox_actions WHERE id = :id', ['id' => $mineId]));
    }

    public function test_the_purger_runs_after_the_project_purger(): void
    {
        $projects = self::getContainer()->get(ProjectAccountPurger::class);
        self::assertInstanceOf(ProjectAccountPurger::class, $projects);

        self::assertGreaterThan($projects->deletionOrder(), new InboxActionAccountPurger($this->em->getConnection())->deletionOrder());
    }

    public function test_the_exporter_yields_every_field_of_the_actions_the_user_requested(): void
    {
        $owner = $this->owner($this->em, 'export-owner');
        $stranger = $this->owner($this->em, 'export-stranger');
        $item = $this->item($this->em, $this->project($this->em, $owner, 'export'));
        $commandId = Uuid::v7();
        $pauseId = Uuid::v7();
        $action = new InboxAction($item, InboxActionKind::ResumeRun, $owner, 'Go on', new \DateTimeImmutable('2026-10-13 09:00:00'));
        $action->bridgeCommandId = $commandId;
        $action->pauseId = $pauseId;
        $action->cancelledAt = new \DateTimeImmutable('2026-10-13 09:05:00');
        $plain = new InboxAction($item, InboxActionKind::Open, $owner, '', new \DateTimeImmutable('2026-10-13 10:00:00'));
        $foreign = new InboxAction($item, InboxActionKind::Dismiss, $stranger);
        $this->em->persist($action);
        $this->em->persist($plain);
        $this->em->persist($foreign);
        $this->em->flush();
        $this->em->clear();

        $exporter = self::getContainer()->get(InboxActionExporter::class);
        self::assertInstanceOf(InboxActionExporter::class, $exporter);
        $rows = iterator_to_array($exporter->export($owner), false);

        self::assertSame('inbox_actions.json', $exporter->filename());
        self::assertSame([
            [
                'id' => (string) $action->id,
                'itemId' => (string) $item->id,
                'kind' => 'resume-run',
                'note' => 'Go on',
                'requestedAt' => new \DateTimeImmutable('2026-10-13 09:00:00')->format(\DateTimeInterface::ATOM),
                'bridgeCommandId' => $commandId->toRfc4122(),
                'pauseId' => $pauseId->toRfc4122(),
                'cancelledAt' => new \DateTimeImmutable('2026-10-13 09:05:00')->format(\DateTimeInterface::ATOM),
            ],
            [
                'id' => (string) $plain->id,
                'itemId' => (string) $item->id,
                'kind' => 'open',
                'note' => '',
                'requestedAt' => new \DateTimeImmutable('2026-10-13 10:00:00')->format(\DateTimeInterface::ATOM),
                'bridgeCommandId' => null,
                'pauseId' => null,
                'cancelledAt' => null,
            ],
        ], $rows);
    }
}
