<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\IncludeExperimentCardCommand;
use App\Module\Bridge\Command\IncludeExperimentCardHandler;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

#[McpTool(name: 'experiment_card_include', description: 'Put a card back into one experiment after experiment_card_exclude left it out. The card counts again in the figures, unless an automatic left-out reason still applies to it. Pass experiment (its name) and number (the card number). Including a card that has no exclusion changes nothing. A card that the Cards tab of the experiment does not list is refused.')]
final readonly class ExperimentCardIncludeTool
{
    public function __construct(
        private BridgeSubjectResolver $subjects,
        private IncludeExperimentCardHandler $handler,
    ) {
    }

    /**
     * @param string $experiment the name of the experiment, as worker_run_list and experiment_get name it
     * @param int    $number     the number of the card to put back
     *
     * @return array{experiment: string, number: int, cardId: string, excluded: bool}
     */
    public function __invoke(string $experiment, #[Schema(minimum: 1)] int $number): array
    {
        $project = $this->subjects->requireWritableProject();

        try {
            $cardId = ($this->handler)(new IncludeExperimentCardCommand($project, $experiment, $number));

            return ['experiment' => $experiment, 'number' => $number, 'cardId' => $cardId->toRfc4122(), 'excluded' => false];
        } catch (DomainErrors $e) {
            throw new ToolCallException(isset($e->errors['experiment']) ? \sprintf('No experiment named "%s" exists in this project. worker_run_list names the experiment of each run.', $experiment) : \sprintf('Card %d is not in the experiment "%s". experiment_get lists its cards.', $number, $experiment));
        } catch (\Throwable $e) {
            throw new ToolCallException('The card could not be put back. The error has been logged.', previous: $e);
        }
    }
}
