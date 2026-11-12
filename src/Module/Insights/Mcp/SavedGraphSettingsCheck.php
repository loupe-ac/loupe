<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Module\Insights\Service\SavedGraphSettingsProblems;
use App\Module\Project\Entity\Project;
use Mcp\Exception\ToolCallException;

/** Refuses a setting that the Metrics page would swap for another value, and names the values that are valid. */
final readonly class SavedGraphSettingsCheck
{
    public function __construct(
        private SavedGraphSettingsProblems $problems,
    ) {
    }

    /**
     * @param array<string, string> $settings the settings to check, by name
     *
     * @throws ToolCallException listing each setting that the metric or the range cannot take
     */
    public function check(Project $project, array $settings): void
    {
        $problems = $this->problems->find($project, $settings);
        if ([] !== $problems) {
            throw new ToolCallException(implode("\n", array_map(static fn (array $problem): string => $problem['field'].': '.$problem['message'], $problems)));
        }
    }
}
