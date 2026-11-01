<?php

declare(strict_types=1);

namespace App\DevSeed;

use App\Module\Artifact\Service\Dev\DevArtifactSeeder;
use App\Module\Inbox\Service\Dev\DevCodexRunSeeder;
use App\Module\Inbox\Service\Dev\DevExperimentSeeder;
use App\Module\Inbox\Service\Dev\DevMetricsCardSeeder;
use App\Module\Insights\Service\Dev\DevAnalysesSeeder;
use App\Module\Insights\Service\Dev\DevMetricsSeeder;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use Symfony\Component\DependencyInjection\Attribute\When;

/** Runs the module seeders that do not implement the interface yet, in their old order. */
#[When('dev')]
#[When('test')]
final readonly class LegacyModuleSeeders implements DevSeederInterface
{
    public function __construct(
        private ProjectRepository $projects,
        private DevExperimentSeeder $experiment,
        private DevCodexRunSeeder $codexRuns,
        private DevAnalysesSeeder $analyses,
        private DevMetricsSeeder $metrics,
        private DevMetricsCardSeeder $metricsCards,
        private DevArtifactSeeder $artifacts,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return 'legacy-module-seeders';
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevProjectSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $project = $this->projects->find(DevProjectSeeder::devProjectId());
        if (!$project instanceof Project) {
            throw new \LogicException('The dev project does not exist.');
        }

        $this->experiment->seed($project);
        // The goal panel has three states and two problems. The merge rate of the two models overlaps, and the template has no pull request history to give hours to merge.
        $this->experiment->seed($project, 'implement-merge-rate', ['goal' => ['metric' => 'merge-rate', 'better' => 'higher']]);
        $this->experiment->seed($project, 'implement-merge-time', ['goal' => ['metric' => 'hours-to-merge', 'better' => 'lower']]);
        $this->experiment->seed($project, 'implement-goal-typo', ['goal' => ['metric' => 'hours-to-mrege', 'better' => 'lower']]);
        $this->experiment->seed($project, 'implement-no-direction', ['goal' => ['metric' => 'hours-to-merge']]);
        // The Simple template declares no fix-rounds: a declared fix-rounds guard is unknown and shows a note, and a default one drops with none.
        $known = ['cost', 'merge-rate', 'duration', 'input-tokens'];
        $this->experiment->seed($project, 'implement-unknown-guard', ['guards' => ['merge-rate', 'fix-rounds']], $known);
        $this->experiment->seed($project, 'implement-simple-default', null, $known);
        // The template metrics in the Comparison table, with the pull request history that hours to merge reads.
        $this->experiment->seed($project, 'implement-template-metrics', ['goal' => ['metric' => 'hours-to-merge', 'better' => 'lower']], ['cost', 'merge-rate', 'hours-to-merge']);
        $this->codexRuns->seed($project);
        $this->analyses->seed($project, DevExperimentSeeder::EXPERIMENT);
        // After the analyses, whose bucket rules sort the tool time of the demo runs.
        $this->metrics->seed($project, fn (\DateTimeImmutable $from, \DateTimeImmutable $to): array => $this->metricsCards->cardFor($project, $from, $to));
        $this->artifacts->seed($project);
    }
}
