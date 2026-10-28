<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Workflow\Contract\RepositorySuggestionKey;
use App\Module\Workflow\Contract\RepositorySuggestions;
use App\Module\Workflow\Contract\SuggestionMark;
use App\Tests\Module\Workflow\WorkflowProjects;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TemplateRepositorySuggestionsTest extends KernelTestCase
{
    use WorkflowProjects;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_project_bound_to_no_template_has_no_suggestions(): void
    {
        $project = $this->workflowProject('suggestions-unbound');

        self::assertSame([], $this->suggestions()->forProject($project->id ?? throw new \LogicException()));
    }

    public function test_a_bound_project_reads_the_suggestions_of_its_copy_in_template_order(): void
    {
        $project = $this->workflowProject('suggestions-bound');
        $this->bindLifecycle($project);

        $suggestions = $this->suggestions()->forProject($project->id ?? throw new \LogicException());

        self::assertSame(
            [RepositorySuggestionKey::ApprovalGate, RepositorySuggestionKey::CiChecks, RepositorySuggestionKey::BranchProtection, RepositorySuggestionKey::MergeMethod],
            array_map(static fn ($suggestion): RepositorySuggestionKey => $suggestion->key, $suggestions),
        );
        self::assertSame(SuggestionMark::Blocking, $suggestions[0]->mark);
        self::assertSame(SuggestionMark::Warning, $suggestions[1]->mark);
    }

    public function test_a_fresh_read_sees_a_template_update_that_the_kept_copy_misses(): void
    {
        $project = $this->workflowProject('suggestions-fresh');
        $this->bindLifecycle($project);
        $projectId = $project->id ?? throw new \LogicException();
        self::assertNotSame([], $this->suggestions()->forProject($projectId), 'Guard: the copy suggests settings.');
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement(
            "UPDATE workflow_bindings SET definition = definition - 'repositorySettings' WHERE project_id = :projectId",
            ['projectId' => $projectId->toRfc4122()],
        );

        self::assertNotSame([], $this->suggestions()->forProject($projectId), 'The kept copy stays until the services reset.');
        self::assertSame([], $this->suggestions()->forProjectFresh($projectId));
    }

    public function test_a_fresh_read_of_a_project_bound_to_no_template_has_no_suggestions(): void
    {
        $project = $this->workflowProject('suggestions-fresh-unbound');

        self::assertSame([], $this->suggestions()->forProjectFresh($project->id ?? throw new \LogicException()));
    }

    private function suggestions(): RepositorySuggestions
    {
        $suggestions = self::getContainer()->get(RepositorySuggestions::class);
        self::assertInstanceOf(RepositorySuggestions::class, $suggestions);

        return $suggestions;
    }
}
