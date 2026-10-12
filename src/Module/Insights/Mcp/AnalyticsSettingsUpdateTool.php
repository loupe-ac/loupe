<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Exception\DomainErrors;
use App\Module\Insights\Command\ShowAnalyticsSettingsCommand;
use App\Module\Insights\Command\ShowAnalyticsSettingsHandler;
use App\Module\Insights\Command\UpdateAnalyticsSettingsCommand;
use App\Module\Insights\Command\UpdateAnalyticsSettingsHandler;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Changes the analysis settings of the project.
 *
 * @phpstan-import-type AnalyticsSettingsPayload from AnalyticsSettingsGetTool
 */
#[McpTool(name: self::NAME, description: 'Change the analysis settings of this project. Pass only the values you change: an argument you leave out keeps its value. defaultModel is the model a new analysis takes when it names none, one word such as sonnet or opus. defaultEffort is low, medium, high, xhigh or max. Pass clearDefaultModel or clearDefaultEffort true to clear the project value, so the instance default applies. An empty string also clears it. collectFullText says whether the bridge sends the full text of each tool call. subcommandPrograms is the list of programs whose second word joins the signature of a shell command, such as git or npm. Pass at most 50 names of letters, digits and . _ + - and pass an empty list to clear the project list, so the instance list applies. The response is the settings, as analytics_settings_get answers them.')]
final readonly class AnalyticsSettingsUpdateTool
{
    public const string NAME = 'analytics_settings_update';

    public function __construct(
        private InsightsSubjectResolver $subjects,
        private ShowAnalyticsSettingsHandler $showSettings,
        private UpdateAnalyticsSettingsHandler $updateSettings,
        private InsightsToolErrorMessages $errorMessages,
    ) {
    }

    /**
     * @param string|null   $defaultModel       the model, or an empty string to clear it
     * @param bool|null     $clearDefaultModel  true clears the project model; do not pass it with defaultModel
     * @param string|null   $defaultEffort      low, medium, high, xhigh or max, or an empty string to clear it
     * @param bool|null     $clearDefaultEffort true clears the project effort; do not pass it with defaultEffort
     * @param bool|null     $collectFullText    whether the bridge sends the full text of each tool call
     * @param string[]|null $subcommandPrograms the programs whose second word joins a shell signature, or an empty list to clear them
     *
     * @return AnalyticsSettingsPayload
     */
    public function __invoke(?string $defaultModel = null, ?bool $clearDefaultModel = null, ?string $defaultEffort = null, ?bool $clearDefaultEffort = null, ?bool $collectFullText = null, ?array $subcommandPrograms = null): array
    {
        try {
            if (true === $clearDefaultModel) {
                if (null !== $defaultModel && '' !== $defaultModel) {
                    throw new ToolCallException('Pass clearDefaultModel or defaultModel, not both.');
                }
                $defaultModel = '';
            }
            if (true === $clearDefaultEffort) {
                if (null !== $defaultEffort && '' !== $defaultEffort) {
                    throw new ToolCallException('Pass clearDefaultEffort or defaultEffort, not both.');
                }
                $defaultEffort = '';
            }

            $project = $this->subjects->requireWritableProject();

            try {
                ($this->updateSettings)(new UpdateAnalyticsSettingsCommand(
                    project: $project,
                    model: '' === $defaultModel ? null : $defaultModel,
                    effort: '' === $defaultEffort ? null : $defaultEffort,
                    collectFullText: $collectFullText ?? false,
                    changeModel: null !== $defaultModel,
                    changeEffort: null !== $defaultEffort,
                    changeCollectFullText: null !== $collectFullText,
                    subcommandPrograms: [] === $subcommandPrograms || null === $subcommandPrograms ? null : array_values($subcommandPrograms),
                    changeSubcommandPrograms: null !== $subcommandPrograms,
                ));
            } catch (DomainErrors $e) {
                throw $this->errorMessages->forAgent($e, ['model' => 'defaultModel', 'effort' => 'defaultEffort']);
            }

            return AnalyticsSettingsGetTool::payload(($this->showSettings)(new ShowAnalyticsSettingsCommand($project)));
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The analysis settings could not be saved. The error has been logged.', previous: $e);
        }
    }
}
