<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\ExcludeExperimentCardCommand;
use App\Module\Bridge\Command\ExcludeExperimentCardHandler;
use App\Module\Bridge\Entity\ExperimentExclusion;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

#[McpTool(name: 'experiment_card_exclude', description: 'Leave one card out of one experiment by hand. The report then drops the card from every figure, metric, control and headline of every variant, and the Cards tab shows it with the left-out reason excluded, the reason you pass, the author and the time. Pass experiment (its name), number (the card number, as experiment_get shows it) and reason (1 to 500 characters, why the card does not fit the question). Excluding an excluded card replaces its reason. A card that the Cards tab of the experiment does not list is refused, and so is a listed card that has no number. experiment_card_include puts the card back. Excluding is curation, so use it only when asked.')]
final readonly class ExperimentCardExcludeTool
{
    public function __construct(
        private BridgeSubjectResolver $subjects,
        private ExcludeExperimentCardHandler $handler,
    ) {
    }

    /**
     * @param string $experiment the name of the experiment, as worker_run_list and experiment_get name it
     * @param int    $number     the number of the card to leave out
     * @param string $reason     why the card does not fit the question of the experiment
     *
     * @return array{experiment: string, number: int, cardId: string, excluded: bool, reason: string}
     */
    public function __invoke(
        string $experiment,
        #[Schema(minimum: 1)] int $number,
        #[Schema(minLength: 1, maxLength: ExperimentExclusion::MAX_REASON_LENGTH)] string $reason,
    ): array {
        $project = $this->subjects->requireWritableProject();
        $user = $this->subjects->requireUser();
        $reason = trim($reason);
        if ('' === $reason || mb_strlen($reason) > ExperimentExclusion::MAX_REASON_LENGTH) {
            throw new ToolCallException(\sprintf('The reason must have 1 to %d characters.', ExperimentExclusion::MAX_REASON_LENGTH));
        }

        try {
            $cardId = ($this->handler)(new ExcludeExperimentCardCommand($project, $experiment, $number, $reason, $user));

            return ['experiment' => $experiment, 'number' => $number, 'cardId' => $cardId->toRfc4122(), 'excluded' => true, 'reason' => $reason];
        } catch (DomainErrors $e) {
            throw new ToolCallException(isset($e->errors['experiment']) ? \sprintf('No experiment named "%s" exists in this project. worker_run_list names the experiment of each run.', $experiment) : \sprintf('Card %d is not in the experiment "%s". experiment_get lists its cards.', $number, $experiment));
        } catch (\Throwable $e) {
            throw new ToolCallException('The card could not be excluded. The error has been logged.', previous: $e);
        }
    }
}
