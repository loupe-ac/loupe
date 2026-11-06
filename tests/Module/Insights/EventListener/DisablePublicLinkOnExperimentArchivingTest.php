<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\EventListener;

use App\Module\Bridge\Command\ArchiveExperimentCommand;
use App\Module\Bridge\Command\ArchiveExperimentHandler;
use App\Module\Bridge\Command\UnarchiveExperimentCommand;
use App\Module\Bridge\Command\UnarchiveExperimentHandler;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Repository\PublicLinkRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DisablePublicLinkOnExperimentArchivingTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_archiving_switches_the_link_of_that_experiment_off_and_keeps_the_code_and_the_note(): void
    {
        $project = $this->scenarioProject('archive-link');
        $this->seedExperiment($project, 'trial');
        $this->seedExperiment($project, 'other');
        $trial = $this->link($project, 'trial');
        $other = $this->link($project, 'other');
        $code = $trial->code;

        $this->service(ArchiveExperimentHandler::class)(new ArchiveExperimentCommand($project, 'trial'));

        $this->em()->clear();
        $links = $this->service(PublicLinkRepository::class)->findExperimentLinks($this->em()->find(Project::class, $project->id) ?? throw new \LogicException());
        self::assertFalse($links['trial']->enabled);
        self::assertSame($code, $links['trial']->code);
        self::assertSame('A note.', $links['trial']->note);
        self::assertTrue($links['other']->enabled);
        self::assertSame($other->code, $links['other']->code);
    }

    public function test_a_hand_unarchive_leaves_the_link_off(): void
    {
        $project = $this->scenarioProject('archive-unarchive');
        $this->seedExperiment($project, 'trial');
        $this->link($project, 'trial');
        $this->service(ArchiveExperimentHandler::class)(new ArchiveExperimentCommand($project, 'trial'));

        $this->service(UnarchiveExperimentHandler::class)(new UnarchiveExperimentCommand($project, 'trial'));

        $this->em()->clear();
        $stored = $this->em()->find(Project::class, $project->id) ?? throw new \LogicException();
        self::assertFalse($this->service(PublicLinkRepository::class)->findForExperiment($stored, 'trial')?->enabled);
    }

    public function test_a_new_run_after_the_archive_leaves_the_link_off(): void
    {
        $project = $this->scenarioProject('archive-run');
        $this->seedExperiment($project, 'trial');
        $this->link($project, 'trial');
        $this->service(ArchiveExperimentHandler::class)(new ArchiveExperimentCommand($project, 'trial'));
        $run = $this->seedRun($this->em(), $project, receivedAt: new \DateTimeImmutable('+1 hour'), cardNumber: 2);
        $run->experiment = 'trial';
        $this->em()->flush();

        $this->em()->clear();
        $stored = $this->em()->find(Project::class, $project->id) ?? throw new \LogicException();
        self::assertFalse($this->service(PublicLinkRepository::class)->findForExperiment($stored, 'trial')?->enabled);
    }

    public function test_archiving_an_experiment_with_no_link_creates_none(): void
    {
        $project = $this->scenarioProject('archive-nolink');
        $this->seedExperiment($project, 'trial');

        $this->service(ArchiveExperimentHandler::class)(new ArchiveExperimentCommand($project, 'trial'));

        self::assertSame([], $this->service(PublicLinkRepository::class)->findExperimentLinks($project));
    }

    private function link(Project $project, string $experiment): PublicLink
    {
        $link = PublicLink::forExperiment($project, $experiment, 'A note.');
        $this->em()->persist($link);
        $this->em()->flush();

        return $link;
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
