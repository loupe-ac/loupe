<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Exception\DomainErrors;
use App\Module\Board\Command\SaveBoardAutomationSettingsCommand;
use App\Module\Board\Command\SaveBoardAutomationSettingsHandler;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Workflow\Contract\ApprovalRules;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * @phpstan-type AutomationSettingsSummary array{enabled: bool, stuckDelayMinutes: int, approvalCoversConflictResolution: bool}
 */
#[McpTool(name: self::NAME, description: 'Turn the workflow of the project board on or off, the same switch as the Automation page of the project settings. While it is off, the workflow moves no card and asks for no work. stuckDelayMinutes is the minutes a pull request may wait for a merge or for any card change before its card shows Stuck. approvalCoversConflictResolution says whether an approval of a pull request still covers a merge from the base that holds a conflict resolution. It is true by default. Set it to false and the pull request waits for a new approval after such a merge. The value belongs to the copy of the workflow template that this project holds. A setting you leave out keeps the value it has. The result gives the value of every setting after the change.')]
final readonly class AutomationSettingsUpdateTool
{
    public const string NAME = 'automation_settings_update';

    public function __construct(
        private BoardSubjectResolver $subjects,
        private BoardAutomation $automation,
        private SaveBoardAutomationSettingsHandler $saveSettings,
        private BoardToolErrorMessages $errorMessages,
        private ApprovalRules $approvalRules,
    ) {
    }

    /**
     * @param bool|null $enabled                          whether the workflow of the board moves the cards and asks for their work
     * @param int|null  $stuckDelayMinutes                the minutes a pull request may wait for a merge or for any card change before its card shows Stuck, from 1 to 1440
     * @param bool|null $approvalCoversConflictResolution whether an approval still covers a merge from the base that holds a conflict resolution
     *
     * @return AutomationSettingsSummary
     */
    public function __invoke(?bool $enabled = null, ?int $stuckDelayMinutes = null, ?bool $approvalCoversConflictResolution = null): array
    {
        try {
            $project = $this->subjects->requireProject();
            $current = $this->automation->settingsOf($project);

            ($this->saveSettings)(new SaveBoardAutomationSettingsCommand(
                project: $project,
                enabled: $enabled ?? $current->enabled,
                stuckDelayMinutes: $stuckDelayMinutes ?? $current->stuckDelayMinutes,
                approvalCoversConflictResolution: $approvalCoversConflictResolution,
            ));
            $saved = $this->automation->settingsOf($project);

            return [
                'enabled' => $saved->enabled,
                'stuckDelayMinutes' => $saved->stuckDelayMinutes,
                'approvalCoversConflictResolution' => $this->approvalRules->coversConflictResolution($project->id ?? throw new \LogicException('The project is not persisted.')),
            ];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The automation settings could not be changed. The error has been logged.', previous: $e);
        }
    }
}
