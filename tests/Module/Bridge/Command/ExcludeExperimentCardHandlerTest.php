<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\ExcludeExperimentCardCommand;
use App\Module\Bridge\Command\ExcludeExperimentCardHandler;
use App\Module\Bridge\Command\IncludeExperimentCardCommand;
use App\Module\Bridge\Command\IncludeExperimentCardHandler;
use App\Module\Bridge\Entity\ExperimentPin;
use App\Module\Bridge\Repository\ExperimentExclusionRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ExcludeExperimentCardHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string EXPERIMENT = 'php-lsp';

    private EntityManagerInterface $em;
    private Project $project;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = $this->em();
        $this->project = $this->project($this->em, $this->user($this->em, 'exclude-'.uniqid().'@example.com'), 'Exclude');
        $this->audit = RecordingAuditor::installedIn(self::getContainer());
    }

    public function test_it_excludes_a_card_that_a_run_of_the_experiment_names(): void
    {
        $cardId = $this->runOfCard(7);
        $author = $this->user($this->em, 'author-'.uniqid().'@example.com');

        $excluded = $this->exclude(7, 'Changes no PHP', $author);

        self::assertTrue($excluded->equals($cardId));
        $rows = $this->exclusions()->findOfExperiment($this->project, self::EXPERIMENT);
        self::assertCount(1, $rows);
        self::assertSame('Changes no PHP', $rows[0]->reason);
        self::assertSame((string) $author->id, (string) $rows[0]->excludedBy?->id);
        self::assertSame(['experiment.card_excluded'], $this->audit->operations());
    }

    public function test_a_second_exclusion_replaces_the_reason_and_the_row_count_stays_one(): void
    {
        $this->runOfCard(7);

        $this->exclude(7, 'First reason', null);
        $this->exclude(7, 'Second reason', null);

        $this->em->clear();
        $rows = $this->exclusions()->findOfExperiment($this->project, self::EXPERIMENT);
        self::assertCount(1, $rows);
        self::assertSame('Second reason', $rows[0]->reason);
        self::assertNull($rows[0]->excludedBy);
    }

    public function test_including_a_card_deletes_its_exclusion_and_a_second_include_succeeds(): void
    {
        $this->runOfCard(7);
        $this->exclude(7, 'Changes no PHP', null);

        $this->include(7);
        $this->include(7);

        self::assertSame([], $this->exclusions()->findOfExperiment($this->project, self::EXPERIMENT));
        self::assertSame(['experiment.card_excluded', 'experiment.card_included', 'experiment.card_included'], $this->audit->operations());
    }

    public function test_it_refuses_an_experiment_the_project_does_not_know(): void
    {
        $this->runOfCard(7);

        $errors = $this->refusal(fn () => $this->exclude(7, 'Reason', null, 'unknown-experiment'));

        self::assertSame(['experiment' => 'bridge.experiments.exclusion.error.unknown_experiment'], $errors);
        self::assertSame([], $this->exclusions()->findOfExperiment($this->project, 'unknown-experiment'));
    }

    public function test_it_refuses_a_card_the_experiment_does_not_list(): void
    {
        $this->runOfCard(7);

        $errors = $this->refusal(fn () => $this->exclude(99, 'Reason', null));

        self::assertSame(['card' => 'bridge.experiments.exclusion.error.card_not_in_experiment'], $errors);
        self::assertSame([], $this->audit->operations());
    }

    public function test_a_pin_alone_does_not_give_a_card_a_number_to_reach(): void
    {
        $this->em->persist(new ExperimentPin($this->project, Uuid::v7(), self::EXPERIMENT, 'a'));
        $this->em->flush();

        $errors = $this->refusal(fn () => $this->exclude(5, 'Reason', null));

        self::assertSame(['card' => 'bridge.experiments.exclusion.error.card_not_in_experiment'], $errors);
    }

    public function test_an_include_refuses_a_card_the_experiment_does_not_list(): void
    {
        $this->runOfCard(7);

        $errors = $this->refusal(fn () => $this->include(99));

        self::assertSame(['card' => 'bridge.experiments.exclusion.error.card_not_in_experiment'], $errors);
    }

    private function runOfCard(int $number): Uuid
    {
        $cardId = Uuid::v7();
        $run = $this->seedRun($this->em, $this->project, cardNumber: $number, cardId: $cardId, runKey: Uuid::v7());
        $run->experiment = self::EXPERIMENT;
        $run->variant = 'a';
        $this->em->flush();

        return $cardId;
    }

    /** @param non-empty-string $reason */
    private function exclude(int $number, string $reason, ?\App\Module\Account\Entity\User $author, string $experiment = self::EXPERIMENT): Uuid
    {
        $handler = self::getContainer()->get(ExcludeExperimentCardHandler::class);
        self::assertInstanceOf(ExcludeExperimentCardHandler::class, $handler);

        return $handler(new ExcludeExperimentCardCommand($this->project, $experiment, $number, $reason, $author));
    }

    private function include(int $number): Uuid
    {
        $handler = self::getContainer()->get(IncludeExperimentCardHandler::class);
        self::assertInstanceOf(IncludeExperimentCardHandler::class, $handler);

        return $handler(new IncludeExperimentCardCommand($this->project, self::EXPERIMENT, $number));
    }

    /** @return array<string, string> */
    private function refusal(\Closure $call): array
    {
        try {
            $call();
        } catch (DomainErrors $e) {
            return $e->errors;
        }

        self::fail('The handler refuses the call.');
    }

    private function exclusions(): ExperimentExclusionRepository
    {
        $exclusions = self::getContainer()->get(ExperimentExclusionRepository::class);
        self::assertInstanceOf(ExperimentExclusionRepository::class, $exclusions);

        return $exclusions;
    }
}
