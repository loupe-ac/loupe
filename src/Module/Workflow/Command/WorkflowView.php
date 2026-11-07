<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

/** The workflow of one project, as an agent reads it to learn what each kind of work needs. */
final readonly class WorkflowView
{
    /**
     * @param list<WorkflowColumnView>  $columns      in board order
     * @param list<WorkflowKindView>    $kinds        in the order of their first rule, template rules first
     * @param ?string                   $epicBranch   the epic branch pattern of the template with {number} for the card number, or null when the template has none
     * @param list<string>              $requirements the written requirements of the workflow, in template order
     * @param ?string                   $githubAppId  the id of the Loupe GitHub App, or null when the instance has none
     * @param list<WorkflowSettingView> $settings     the resolved settings, in template order, a repository setting once for each repository of the project
     */
    public function __construct(
        public string $templateKey,
        public string $templateVersion,
        public array $columns,
        public array $kinds,
        public bool $approvalCoversConflictResolution,
        public ?string $epicBranch,
        public array $requirements = [],
        public ?string $githubAppId = null,
        public array $settings = [],
    ) {
    }
}
