<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Service;

use App\Exception\DomainErrors;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Readiness\Service\SupersededReportGuard;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Verdict;
use App\Tests\Module\Readiness\DiscoveryScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SupersededReportGuardTest extends KernelTestCase
{
    use DiscoveryScenario;

    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('superseded-guard');
    }

    public function test_it_refuses_when_the_loaded_run_says_reported_and_the_row_says_superseded(): void
    {
        [$run, $report] = $this->reportedRun();
        $this->em()->getConnection()->executeStatement("UPDATE discovery_runs SET state = 'superseded' WHERE id = ?", [(string) $run->id]);
        self::assertSame(DiscoveryRunState::Reported, $run->state);

        self::assertSame(SupersededReportGuard::SUPERSEDED, $this->guard()->refusal($report, Verdict::Approved));
    }

    /** @return iterable<string, array{string}> */
    public static function verdicts(): iterable
    {
        yield 'approved' => ['approved'];
        yield 'changes requested' => ['changes-requested'];
    }

    /** The tag brings the guard into the verdict of every surface. */
    #[DataProvider('verdicts')]
    public function test_a_verdict_on_a_superseded_report_is_refused(string $verdict): void
    {
        [$run, $report] = $this->reportedRun();
        $run->state = DiscoveryRunState::Superseded;
        $this->em()->flush();
        $submit = self::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $submit);

        try {
            $submit(new SubmitReviewCommand($this->project->owner, $report, $verdict, 1, 'A note.'));
            self::fail('The verdict was not refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['verdict' => SupersededReportGuard::SUPERSEDED], $e->errors);
            self::assertTrue($e->fromGuard);
        }

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM reviews'));
    }

    public function test_the_refusal_has_an_english_sentence(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        self::assertSame('This report was replaced by a newer discovery run.', $translator->trans(SupersededReportGuard::SUPERSEDED, locale: 'en'));
    }

    /** @return iterable<string, array{DiscoveryRunState}> */
    public static function otherStates(): iterable
    {
        foreach (DiscoveryRunState::cases() as $state) {
            if (DiscoveryRunState::Superseded !== $state) {
                yield $state->value => [$state];
            }
        }
    }

    #[DataProvider('otherStates')]
    public function test_it_lets_the_report_of_any_other_run_through(DiscoveryRunState $state): void
    {
        [$run, $report] = $this->reportedRun();
        $run->state = $state;
        $this->em()->flush();

        self::assertNull($this->guard()->refusal($report, Verdict::Approved));
    }

    public function test_it_lets_a_document_that_is_no_report_through(): void
    {
        self::assertNull($this->guard()->refusal($this->document(), Verdict::Approved));
    }

    /** @return array{DiscoveryRun, Document} */
    private function reportedRun(): array
    {
        $run = $this->discoveryRun($this->project, DiscoveryRunState::Reported);
        $run->reportDocument = $this->document();
        $this->em()->flush();

        return [$run, $run->reportDocument];
    }

    private function document(): Document
    {
        $document = new Document($this->project->owner, $this->project, 'Report');
        $document->addVersion('x', '<p>x</p>');
        $this->em()->persist($document);
        $this->em()->flush();

        return $document;
    }

    private function guard(): SupersededReportGuard
    {
        $runs = self::getContainer()->get(DiscoveryRunRepository::class);
        $projects = self::getContainer()->get(ProjectRepository::class);
        self::assertInstanceOf(DiscoveryRunRepository::class, $runs);
        self::assertInstanceOf(ProjectRepository::class, $projects);

        return new SupersededReportGuard($runs, $projects);
    }
}
