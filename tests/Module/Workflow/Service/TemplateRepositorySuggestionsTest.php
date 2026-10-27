<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Workflow\Contract\RepositorySuggestionKey;
use App\Module\Workflow\Contract\RepositorySuggestions;
use App\Module\Workflow\Contract\SuggestionMark;
use App\Tests\Module\Workflow\WorkflowProjects;
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

    private function suggestions(): RepositorySuggestions
    {
        $suggestions = self::getContainer()->get(RepositorySuggestions::class);
        self::assertInstanceOf(RepositorySuggestions::class, $suggestions);

        return $suggestions;
    }
}
