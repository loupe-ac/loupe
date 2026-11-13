<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use App\Module\Insights\Command\AnalyticsSettingsView;
use OpenApi\Attributes as OA;

/** The analytics settings of a project. */
#[OA\Schema(required: ['defaultModel', 'defaultEffort', 'collectFullText', 'subcommandPrograms', 'model', 'effort'])]
final readonly class AnalyticsSettingsResponse
{
    /** @param list<string> $subcommandPrograms */
    public function __construct(
        #[OA\Property(description: 'The model the project sets for a new analysis, or null when it sets none.', nullable: true)]
        public ?string $defaultModel,

        #[OA\Property(description: 'The effort the project sets for a new analysis, or null when it sets none.', nullable: true)]
        public ?string $defaultEffort,

        #[OA\Property(description: 'Whether the bridge sends the full text of each tool call.')]
        public bool $collectFullText,

        #[OA\Property(description: 'The programs whose second word joins the signature of a shell command, such as `git`. Empty when the project sets none, so the instance list applies.', type: 'array', items: new OA\Items(type: 'string'))]
        public array $subcommandPrograms,

        #[OA\Property(description: 'The model a new analysis takes when it names none: the project value, else the instance default.')]
        public string $model,

        #[OA\Property(description: 'The effort a new analysis takes when it names none: `low`, `medium`, `high`, `xhigh` or `max`.')]
        public string $effort,
    ) {
    }

    public static function fromView(AnalyticsSettingsView $view): self
    {
        return new self($view->defaultModel, $view->defaultEffort, $view->collectFullText, $view->subcommandPrograms ?? [], $view->model, $view->effort);
    }
}
