<?php

declare(strict_types=1);

namespace App\Module\Workflow\Controller\Api;

use App\Module\Workflow\Command\WorkflowColumnView;
use App\Module\Workflow\Command\WorkflowKindView;
use App\Module\Workflow\Command\WorkflowView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The workflow of a project: its template, settings, columns and the kinds of work it asks for. */
#[OA\Schema(required: ['template', 'approvalCoversConflictResolution', 'epicBranch', 'requirements', 'settings', 'githubAppId', 'columns', 'kinds'])]
final readonly class WorkflowResponse
{
    /**
     * @param list<string>                  $requirements
     * @param list<WorkflowSettingResponse> $settings
     * @param list<WorkflowColumnResponse>  $columns
     * @param list<WorkflowKindResponse>    $kinds
     */
    public function __construct(
        #[OA\Property(ref: new Model(type: WorkflowTemplateResponse::class))]
        public WorkflowTemplateResponse $template,

        #[OA\Property(description: 'Whether an approval of a pull request still covers a merge from the base that holds a conflict resolution.')]
        public bool $approvalCoversConflictResolution,

        #[OA\Property(description: 'The epic branch pattern, with `{number}` for the card number. Null when the project has no epic branches.', nullable: true)]
        public ?string $epicBranch,

        #[OA\Property(description: 'The written requirements of the workflow, in template order.', type: 'array', items: new OA\Items(type: 'string'))]
        public array $requirements,

        #[OA\Property(description: 'The settings that the template declares, in template order. A repository setting has one entry for each repository of the project.', type: 'array', items: new OA\Items(ref: new Model(type: WorkflowSettingResponse::class)))]
        public array $settings,

        #[OA\Property(description: 'The id of the Loupe GitHub App, or null when the instance has none. The merge stage uses it to tell the `Loupe / Approval` check of the Loupe App from a check of the same name from another App.', nullable: true)]
        public ?string $githubAppId,

        #[OA\Property(description: 'The board columns in board order.', type: 'array', items: new OA\Items(ref: new Model(type: WorkflowColumnResponse::class)))]
        public array $columns,

        #[OA\Property(description: 'Each kind of work the workflow can ask a bridge for.', type: 'array', items: new OA\Items(ref: new Model(type: WorkflowKindResponse::class)))]
        public array $kinds,
    ) {
    }

    public static function fromView(WorkflowView $view, TranslatorInterface $translator): self
    {
        return new self(
            new WorkflowTemplateResponse($view->templateKey, $view->templateVersion),
            $view->approvalCoversConflictResolution,
            $view->epicBranch,
            $view->requirements,
            array_map(WorkflowSettingResponse::fromView(...), $view->settings),
            $view->githubAppId,
            array_map(static fn (WorkflowColumnView $column): WorkflowColumnResponse => new WorkflowColumnResponse(
                $column->column->slug,
                // A seeded label is a translation key, and a renamed one is text that no key matches.
                $translator->trans($column->column->label),
                $column->slot,
                $column->column->backlog,
                $column->column->terminal,
            ), $view->columns),
            array_map(static fn (WorkflowKindView $kind): WorkflowKindResponse => new WorkflowKindResponse($kind->kind, $kind->origin->value, $kind->rules, $kind->checks), $view->kinds),
        );
    }
}
