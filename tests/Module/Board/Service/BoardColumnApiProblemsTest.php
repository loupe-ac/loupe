<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Api\Problem\ApiProblemException;
use App\Exception\DomainErrors;
use App\Module\Board\Command\ConfigureBoardColumnHandler;
use App\Module\Board\Command\DeleteBoardColumnHandler;
use App\Module\Board\Command\ReorderBoardColumnsHandler;
use App\Module\Board\Service\BoardColumnApiProblems;
use App\Module\Board\Service\BoardColumns;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BoardColumnApiProblemsTest extends TestCase
{
    /** @return iterable<string, array{string, string, int, string}> */
    public static function refusals(): iterable
    {
        yield 'slug taken' => ['label', BoardColumns::SLUG_TAKEN, 409, 'column_slug_taken'];
        yield 'no terminal' => ['terminal', BoardColumns::NO_TERMINAL, 409, 'no_terminal_column'];
        yield 'backlog locked' => ['column', BoardColumns::BACKLOG_LOCKED, 409, 'backlog_locked'];
        yield 'label changed under the call' => ['label', ConfigureBoardColumnHandler::LABEL_STALE, 409, 'columns_changed'];
        yield 'terminal changed under the call' => ['terminal', ConfigureBoardColumnHandler::TERMINAL_STALE, 409, 'columns_changed'];
        yield 'order changed under the call' => ['order', ReorderBoardColumnsHandler::ORDER_STALE, 409, 'columns_changed'];
        yield 'target needed' => ['target', DeleteBoardColumnHandler::TARGET_REQUIRED, 409, 'column_target_required'];
        yield 'column deleted under the call' => ['column', ConfigureBoardColumnHandler::GONE, 404, 'column_not_found'];
    }

    #[DataProvider('refusals')]
    public function test_a_conflict_keeps_its_status_and_code(string $field, string $key, int $status, string $code): void
    {
        $problem = new BoardColumnApiProblems()->from(new DomainErrors([$field => $key]));

        self::assertInstanceOf(ApiProblemException::class, $problem);
        self::assertSame($status, $problem->problem->status);
        self::assertSame($code, $problem->problem->code);
    }

    public function test_refused_fields_leave_together_as_one_validation_problem(): void
    {
        $problem = new BoardColumnApiProblems()->from(new DomainErrors([
            'label' => BoardColumns::LABEL_RESERVED,
            'target' => DeleteBoardColumnHandler::TARGET_INVALID,
        ]));

        self::assertInstanceOf(ApiProblemException::class, $problem);
        self::assertSame(422, $problem->problem->status);
        self::assertSame('validation_failed', $problem->problem->code);
        self::assertSame(['label_reserved', 'target_column_invalid'], array_column($problem->problem->errors, 'code'));
        self::assertSame(['label', 'targetColumn'], array_column($problem->problem->errors, 'field'));
    }

    public function test_a_refusal_no_code_covers_stays_a_failure(): void
    {
        $errors = new DomainErrors(['label' => BoardColumns::NO_SINGLE_BACKLOG]);

        self::assertSame($errors, new BoardColumnApiProblems()->from($errors));
    }
}
