<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Condition\Conditions;
use App\Module\Workflow\Contract\WaitConditionCheck;
use App\Module\Workflow\Contract\WaitConditionRefusal;
use App\Module\Workflow\Expression\Expression;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateMarker;
use App\Module\Workflow\Template\TemplateParser;

/** Builds the expression of one condition that a worker waits for, from a condition key or a marker key of the template. */
final readonly class WaitExpressions
{
    public function __construct(
        private Conditions $conditions,
        private TemplateParser $parser,
    ) {
    }

    /**
     * The expression, or the refusal that says why the key cannot be waited for.
     *
     * @param array<string, mixed> $params
     */
    public function of(Template $template, string $key, array $params): Expression|WaitConditionCheck
    {
        $marker = array_find($template->markers, static fn (TemplateMarker $marker): bool => $marker->key === $key);
        if (null !== $marker) {
            if ([] !== $params) {
                return new WaitConditionCheck(WaitConditionRefusal::InvalidParameters, \sprintf('The marker "%s" takes no parameters.', $key), params: $params);
            }

            return $marker->when;
        }

        if (!$this->conditions->has($key)) {
            return new WaitConditionCheck(WaitConditionRefusal::UnknownCondition, \sprintf('"%s" is not a condition or a marker of the workflow.', $key), params: $params);
        }

        $errors = [];
        $leaf = $this->parser->conditionOf($template, $key, $params, $errors);

        return $leaf ?? new WaitConditionCheck(WaitConditionRefusal::InvalidParameters, implode('; ', $errors), params: $params);
    }
}
