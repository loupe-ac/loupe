<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Action;

use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\ActionDescription;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\ActionTraits;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;

/** An action for a list in a rule. It writes its label to the log and answers done, or refuses when the label is "refuse". */
final class ListedTestAction implements Action
{
    /** @var list<string> */
    public static array $log = [];

    /** @var list<Facts> the facts that each run read */
    public static array $facts = [];

    #[\Override]
    public static function key(): string
    {
        return 'listed-test';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('label', ParameterType::String)];
    }

    #[\Override]
    public static function traits(): ActionTraits
    {
        return new ActionTraits(refreshesFacts: true, listable: true);
    }

    #[\Override]
    public function describe(array $params): ActionDescription
    {
        return new ActionDescription('workflow.settings.action.listed_test', 'workflow.panel.action.listed_test');
    }

    #[\Override]
    public function workKind(array $params): ?string
    {
        return null;
    }

    #[\Override]
    public function run(ActionContext $context): ActionOutcome
    {
        self::$log[] = $context->string('label');
        self::$facts[] = $context->facts;

        return 'refuse' === $context->string('label') ? ActionOutcome::refused('listed-refusal') : ActionOutcome::done();
    }
}
