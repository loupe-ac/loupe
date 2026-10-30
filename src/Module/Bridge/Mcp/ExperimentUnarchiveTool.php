<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Module\Bridge\Command\UnarchiveExperimentCommand;
use App\Module\Bridge\Command\UnarchiveExperimentHandler;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

#[McpTool(name: 'experiment_unarchive', description: 'Put an archived experiment of this project back in the main Experiments list. Unarchiving an experiment that is not archived changes nothing.')]
final readonly class ExperimentUnarchiveTool
{
    public function __construct(
        private BridgeSubjectResolver $subjects,
        private UnarchiveExperimentHandler $handler,
    ) {
    }

    /**
     * @param string $experiment the name of the experiment to unarchive
     *
     * @return array{experiment: string, archived: bool}
     */
    public function __invoke(string $experiment): array
    {
        try {
            ($this->handler)(new UnarchiveExperimentCommand($this->subjects->requireWritableProject(), $experiment));

            return ['experiment' => $experiment, 'archived' => false];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The experiment could not be unarchived. The error has been logged.', previous: $e);
        }
    }
}
