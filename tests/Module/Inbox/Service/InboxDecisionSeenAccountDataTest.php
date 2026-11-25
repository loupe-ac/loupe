<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Inbox\Entity\InboxDecisionSeen;
use App\Module\Inbox\Service\InboxDecisionSeenAccountPurger;
use App\Module\Inbox\Service\InboxDecisionSeenExporter;
use App\Module\Project\Service\ProjectAccountPurger;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InboxDecisionSeenAccountDataTest extends KernelTestCase
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

    public function test_the_purger_deletes_the_seen_rows_of_the_user_only(): void
    {
        $owner = $this->owner($this->em, 'seen-purge-owner');
        $other = $this->owner($this->em, 'seen-purge-other');
        $project = $this->project($this->em, $owner, 'seen-purge');
        $card = $this->card($this->em, $project);
        $theirs = new InboxDecisionSeen($project, $other, $card);
        $mine = new InboxDecisionSeen($project, $owner, $card);
        $this->em->persist($theirs);
        $this->em->persist($mine);
        $this->em->flush();
        $theirsId = (string) $theirs->id;
        $mineId = (string) $mine->id;

        new InboxDecisionSeenAccountPurger($this->em->getConnection())->purge($other, new AccountDeletionCleanup());

        $connection = $this->em->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM inbox_decision_seen WHERE id = :id', ['id' => $theirsId]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM inbox_decision_seen WHERE id = :id', ['id' => $mineId]));
    }

    public function test_the_purger_runs_after_the_project_purger(): void
    {
        $projects = self::getContainer()->get(ProjectAccountPurger::class);
        self::assertInstanceOf(ProjectAccountPurger::class, $projects);

        self::assertGreaterThan($projects->deletionOrder(), new InboxDecisionSeenAccountPurger($this->em->getConnection())->deletionOrder());
    }

    public function test_the_exporter_yields_the_seen_rows_of_the_user_only(): void
    {
        $owner = $this->owner($this->em, 'seen-export-owner');
        $other = $this->owner($this->em, 'seen-export-other');
        $project = $this->project($this->em, $owner, 'seen-export');
        $card = $this->card($this->em, $project);
        $mine = new InboxDecisionSeen($project, $owner, $card, new \DateTimeImmutable('2026-11-01 09:00:00'));
        $this->em->persist($mine);
        $this->em->persist(new InboxDecisionSeen($project, $other, $card));
        $this->em->flush();
        $this->em->clear();

        $exporter = self::getContainer()->get(InboxDecisionSeenExporter::class);
        self::assertInstanceOf(InboxDecisionSeenExporter::class, $exporter);
        $rows = iterator_to_array($exporter->export($owner), false);

        self::assertSame('inbox_decision_seen.json', $exporter->filename());
        self::assertSame([[
            'id' => (string) $mine->id,
            'projectId' => (string) $project->id,
            'cardId' => (string) $card->id,
            'seenAt' => new \DateTimeImmutable('2026-11-01 09:00:00')->format(\DateTimeInterface::ATOM),
        ]], $rows);
    }
}
