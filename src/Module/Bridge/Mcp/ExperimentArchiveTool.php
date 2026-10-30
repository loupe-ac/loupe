<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\ArchiveExperimentCommand;
use App\Module\Bridge\Command\ArchiveExperimentHandler;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

#[McpTool(name: 'experiment_archive', description: 'Archive an experiment of this project. It leaves the main Experiments list and shows under the archived filter, and its page and data stay readable. Pins and variant assignment work as before. A worker run that Loupe first records after the archive brings the experiment back by itself. Archiving is curation, so use it only when asked. An experiment that no run and no pin names is refused. Archiving an archived experiment moves its archive time forward.')]
final readonly class ExperimentArchiveTool
{
    public function __construct(
        private BridgeSubjectResolver $subjects,
        private ArchiveExperimentHandler $handler,
    ) {
    }

    /**
     * @param string $experiment the name of the experiment to archive, as worker_run_list and experiment_get name it
     *
     * @return array{experiment: string, archived: bool}
     */
    public function __invoke(string $experiment): array
    {
        try {
            ($this->handler)(new ArchiveExperimentCommand($this->subjects->requireWritableProject(), $experiment));

            return ['experiment' => $experiment, 'archived' => true];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (DomainErrors) {
            throw new ToolCallException(\sprintf('No experiment named "%s" exists in this project. worker_run_list names the experiment of each run.', $experiment));
        } catch (\Throwable $e) {
            throw new ToolCallException('The experiment could not be archived. The error has been logged.', previous: $e);
        }
    }
}
