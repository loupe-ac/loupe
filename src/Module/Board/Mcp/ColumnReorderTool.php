<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Exception\DomainErrors;
use App\Module\Board\Command\ListBoardColumnsCommand;
use App\Module\Board\Command\ListBoardColumnsHandler;
use App\Module\Board\Command\ReorderBoardColumnsBySlugCommand;
use App\Module\Board\Command\ReorderBoardColumnsBySlugHandler;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * @phpstan-import-type BoardColumnSummary from BoardColumnPayload
 */
#[McpTool(name: self::NAME, description: 'Put the columns of the project board in a new order. Pass the slug of every column except backlog, each one once, in the order the board shows them from left to right. Backlog always comes first and never moves, so leave it out. board_columns lists the slugs. The result lists every column of the board in the new order.')]
final readonly class ColumnReorderTool
{
    public const string NAME = 'column_reorder';

    public function __construct(
        private BoardSubjectResolver $subjects,
        private ReorderBoardColumnsBySlugHandler $reorderColumns,
        private ListBoardColumnsHandler $listColumns,
        private BoardColumnPayload $columns,
        private BoardToolErrorMessages $errorMessages,
    ) {
    }

    /**
     * `string[]` not `list<string>`, for the reason on CardUpdateTool.
     *
     * @param string[] $order the slug of every column except backlog, each once, in the new order
     *
     * @return array{columns: list<BoardColumnSummary>}
     */
    public function __invoke(array $order): array
    {
        try {
            $project = $this->subjects->requireProject();

            ($this->reorderColumns)(new ReorderBoardColumnsBySlugCommand($project, $order));
            $view = ($this->listColumns)(new ListBoardColumnsCommand($project));

            return ['columns' => $this->columns->forColumns($view->columns)];
        } catch (DomainErrors $e) {
            throw \in_array($e->errors['order'] ?? null, [ReorderBoardColumnsBySlugHandler::BACKLOG_FIRST, ReorderBoardColumnsBySlugHandler::UNKNOWN_SLUG, ReorderBoardColumnsBySlugHandler::EACH_ONCE], true) ? $this->errorMessages->sentencesForAgent($e) : $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The columns could not be reordered. The error has been logged.', previous: $e);
        }
    }
}
