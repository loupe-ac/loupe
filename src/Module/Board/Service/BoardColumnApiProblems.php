<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Api\Problem\ApiProblem;
use App\Api\Problem\ApiProblemException;
use App\Exception\DomainErrors;
use App\Module\Board\Command\ConfigureBoardColumnHandler;
use App\Module\Board\Command\DeleteBoardColumnHandler;
use App\Module\Board\Command\ReorderBoardColumnsBySlugHandler;
use App\Module\Board\Command\ReorderBoardColumnsHandler;
use App\Module\Board\Entity\BoardColumn;

/** Turns what a column handler refuses with into the coded problems of the public API. */
final readonly class BoardColumnApiProblems
{
    public function columnNotFound(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(404, 'column_not_found', 'Column not found', 'This board has no column with this slug.'));
    }

    public function unknownTargetColumn(string $slug): ApiProblemException
    {
        return ApiProblemException::validationFailed([[
            'field' => 'targetColumn',
            'code' => 'target_column_unknown',
            'message' => sprintf('This board has no column "%s".', $slug),
        ]]);
    }

    /**
     * The first refusal that is a conflict or a missing column decides the answer. Any other
     * refusal is a field the client can correct, and they leave together as one list.
     * A refusal that no code covers stays a DomainErrors, so it is logged as a failure.
     */
    public function from(DomainErrors $errors): ApiProblemException|DomainErrors
    {
        $fields = [];
        foreach ($errors->errors as $field => $key) {
            $problem = $this->conflict($key);
            if (null !== $problem) {
                return new ApiProblemException($problem, $errors);
            }

            $invalid = $this->invalidField($key, $errors->parameters[$field] ?? []);
            if (null === $invalid) {
                return $errors;
            }
            $fields[] = $invalid;
        }

        $problem = ApiProblemException::validationFailed($fields);

        return new ApiProblemException($problem->problem, $errors);
    }

    private function conflict(string $key): ?ApiProblem
    {
        return match ($key) {
            BoardColumns::SLUG_TAKEN => new ApiProblem(409, 'column_slug_taken', 'Column slug taken', 'Another column on this board already has the slug that comes from this label.'),
            BoardColumns::NO_TERMINAL => new ApiProblem(409, 'no_terminal_column', 'No terminal column', 'A board needs at least one terminal column.'),
            BoardColumns::BACKLOG_LOCKED => new ApiProblem(409, 'backlog_locked', 'Backlog locked', 'Nobody can rename, change, reorder or delete Backlog.'),
            ConfigureBoardColumnHandler::LABEL_STALE, ConfigureBoardColumnHandler::TERMINAL_STALE, ReorderBoardColumnsHandler::ORDER_STALE => new ApiProblem(409, 'columns_changed', 'Columns changed', 'The columns changed while the call ran. Read them and try again.'),
            DeleteBoardColumnHandler::TARGET_REQUIRED => new ApiProblem(409, 'column_target_required', 'Target column required', 'This column holds cards. Pass targetColumn.'),
            ConfigureBoardColumnHandler::GONE => new ApiProblem(404, 'column_not_found', 'Column not found', 'This board has no column with this slug.'),
            default => null,
        };
    }

    /**
     * @param array<string, int|string> $parameters the placeholders of the key
     *
     * @return array{field: string, code: string, message: string}|null
     */
    private function invalidField(string $key, array $parameters): ?array
    {
        return match ($key) {
            'board.column.error.label_too_long' => ['field' => 'label', 'code' => 'label_length', 'message' => sprintf('A column label must be at most %d characters.', BoardColumn::MAX_LABEL_LENGTH)],
            BoardColumns::LABEL_RESERVED => ['field' => 'label', 'code' => 'label_reserved', 'message' => 'The app uses this text internally. Choose another label.'],
            BoardColumns::SLUG_EMPTY, BoardColumns::SLUG_INVALID => ['field' => 'label', 'code' => 'label_invalid', 'message' => 'A column label needs at least one letter or digit, because the slug comes from the label.'],
            ReorderBoardColumnsBySlugHandler::BACKLOG_FIRST => ['field' => 'order', 'code' => 'backlog_in_order', 'message' => 'Backlog always comes first. Leave it out of the order.'],
            ReorderBoardColumnsBySlugHandler::UNKNOWN_SLUG => ['field' => 'order', 'code' => 'unknown_column', 'message' => sprintf('Unknown column "%s". Use one of: %s.', $parameters['%slug%'] ?? '', $parameters['%slugs%'] ?? '')],
            ReorderBoardColumnsBySlugHandler::EACH_ONCE => ['field' => 'order', 'code' => 'order_incomplete', 'message' => sprintf('Name each of these columns exactly once: %s.', $parameters['%slugs%'] ?? '')],
            DeleteBoardColumnHandler::TARGET_INVALID => ['field' => 'targetColumn', 'code' => 'target_column_invalid', 'message' => 'targetColumn must name another column of this board.'],
            default => null,
        };
    }
}
