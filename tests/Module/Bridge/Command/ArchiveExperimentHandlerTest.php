<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\ArchiveExperimentCommand;
use App\Module\Bridge\Command\ArchiveExperimentHandler;
use App\Module\Bridge\Command\ListExperimentsCommand;
use App\Module\Bridge\Command\ListExperimentsHandler;
use App\Module\Bridge\Command\UnarchiveExperimentCommand;
use App\Module\Bridge\Command\UnarchiveExperimentHandler;
use App\Module\Bridge\Entity\ExperimentPin;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\ExperimentArchiveRepository;
use App\Module\Bridge\Repository\ExperimentPinRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\AuditActorProviderInterface;

final class ArchiveExperimentHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    /** The archive and every run of these tests land in this one second. */
    private const string SECOND = '2026-09-13 12:00:00';

    private EntityManagerInterface $em;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = $this->em();
        $this->project = $this->project($this->em, $this->user($this->em, 'archive-'.uniqid().'@example.com'), 'Archive');
    }

    public function test_a_run_recorded_before_the_archive_in_the_same_second_stays_hidden(): void
    {
        $this->recordRun('trial');

        $this->archive('trial');

        self::assertSame(['trial'], $this->names(archived: true));
        self::assertSame([], $this->names(archived: false));
    }

    public function test_a_run_recorded_after_the_archive_in_the_same_second_brings_the_experiment_back(): void
    {
        $this->recordRun('trial');
        $this->archive('trial');

        $this->recordRun('trial');

        self::assertSame(['trial'], $this->names(archived: false));
        self::assertSame([], $this->names(archived: true));
    }

    public function test_a_run_that_names_the_experiment_after_the_archive_stays_hidden_when_it_was_recorded_before(): void
    {
        $this->recordRun('trial');
        $early = $this->recordRun(null);
        $this->archive('trial');

        $early->experiment = 'trial';
        $this->em->flush();

        self::assertSame(['trial'], $this->names(archived: true));
    }

    public function test_a_run_recorded_after_the_archive_brings_the_experiment_back_when_it_names_it_later(): void
    {
        $this->recordRun('trial');
        $this->archive('trial');
        $late = $this->recordRun(null);

        $late->experiment = 'trial';
        $this->em->flush();

        self::assertSame(['trial'], $this->names(archived: false));
    }

    public function test_a_run_in_a_later_second_brings_the_experiment_back(): void
    {
        $this->recordRun('trial');
        $this->archive('trial');

        $this->recordRun('trial', '2026-09-13 12:00:01');

        self::assertSame(['trial'], $this->names(archived: false));
    }

    public function test_an_experiment_that_only_a_pin_names_can_be_archived(): void
    {
        $this->em->persist(new ExperimentPin($this->project, Uuid::v7(), 'pinned', 'a'));
        $this->em->flush();

        $this->archive('pinned');

        self::assertSame(['pinned'], $this->names(archived: true));
    }

    public function test_it_refuses_a_name_that_no_run_and_no_pin_names(): void
    {
        $this->recordRun('trial');

        try {
            $this->archive('typo');
            self::fail('The handler should refuse an unknown experiment.');
        } catch (DomainErrors $e) {
            self::assertSame(['experiment' => 'bridge.experiments.archive.error.unknown'], $e->errors);
        }

        self::assertSame([], $this->archives()->findAll());
    }

    public function test_it_does_not_archive_the_experiment_of_another_project(): void
    {
        $other = $this->project($this->em, $this->user($this->em, 'archive-other-'.uniqid().'@example.com'), 'Other');
        $this->recordRun('trial', project: $other);

        $this->expectException(DomainErrors::class);

        $this->archive('trial');
    }

    public function test_archiving_twice_keeps_one_row(): void
    {
        $this->recordRun('trial');

        $this->archive('trial');
        $this->archive('trial');

        self::assertCount(1, $this->archives()->findAll());
    }

    public function test_unarchive_puts_the_experiment_back_and_ignores_an_active_one(): void
    {
        $this->recordRun('trial');
        $this->archive('trial');

        $handler = new UnarchiveExperimentHandler($this->archives(), $this->em, $this->auditor());
        $handler(new UnarchiveExperimentCommand($this->project, 'trial'));
        $handler(new UnarchiveExperimentCommand($this->project, 'trial'));

        self::assertSame(['trial'], $this->names(archived: false));
        self::assertSame([], $this->archives()->findAll());
    }

    private function archive(string $experiment): void
    {
        $handler = new ArchiveExperimentHandler(
            $this->archives(),
            self::getContainer()->get(WorkerRunRepository::class),
            self::getContainer()->get(ExperimentPinRepository::class),
            $this->em,
            new MockClock(new \DateTimeImmutable(self::SECOND.'.400')),
            $this->auditor(),
        );
        $handler(new ArchiveExperimentCommand($this->project, $experiment));
    }

    private function recordRun(?string $experiment, string $receivedAt = self::SECOND, ?Project $project = null): WorkerRun
    {
        $run = $this->seedRun($this->em, $project ?? $this->project, receivedAt: new \DateTimeImmutable($receivedAt), cardId: Uuid::v7());
        $run->experiment = $experiment;
        $run->variant = null === $experiment ? null : 'a';
        $this->em->flush();

        return $run;
    }

    /** @return list<string> */
    private function names(bool $archived): array
    {
        $handler = new ListExperimentsHandler(
            self::getContainer()->get(WorkerRunRepository::class),
            self::getContainer()->get(ExperimentPinRepository::class),
            $this->archives(),
        );

        return array_map(
            static fn ($summary): string => $summary->name,
            $handler(new ListExperimentsCommand($this->project, $archived))->experiments,
        );
    }

    private function archives(): ExperimentArchiveRepository
    {
        $archives = self::getContainer()->get(ExperimentArchiveRepository::class);
        self::assertInstanceOf(ExperimentArchiveRepository::class, $archives);

        return $archives;
    }

    private function auditor(): \Ubermuda\AuditBundle\Auditor
    {
        $actors = self::getContainer()->get(AuditActorProviderInterface::class);
        self::assertInstanceOf(AuditActorProviderInterface::class, $actors);

        return (new RecordingAuditor($actors))->auditor;
    }
}
