<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\Problem\ApiProblem;
use App\Api\Problem\ApiProblemException;
use App\Exception\DomainErrors;
use App\Module\Bridge\Command\CancelBridgeCommandHandler;
use App\Module\Bridge\Command\RequestBridgeCommandHandler;

/** Turns what the bridge command handlers refuse with into the coded problems of the public API. */
final readonly class WorkerRunApiProblems
{
    public function runNotFound(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(404, 'run_not_found', 'Run not found', 'This project has no worker run with this id.'));
    }

    /** A refusal that no code covers stays as it is, so it is logged as a failure. */
    public function from(DomainErrors $errors): ApiProblemException|DomainErrors
    {
        $problem = match (array_first($errors->errors)) {
            RequestBridgeCommandHandler::NOT_STOPPABLE => new ApiProblem(409, 'run_not_stoppable', 'Run not stoppable', 'Only a queued, resumed, preparing or running run can stop.'),
            RequestBridgeCommandHandler::NOT_RESUMABLE => new ApiProblem(409, 'run_not_resumable', 'Run not resumable', 'The run did not end, or it succeeded, so it cannot resume.'),
            RequestBridgeCommandHandler::NOT_CONTROLLABLE => new ApiProblem(409, 'run_not_controllable', 'Run not controllable', 'An interactive session takes no command from a bridge.'),
            RequestBridgeCommandHandler::NO_SESSION => new ApiProblem(409, 'no_session', 'No session', 'The run holds no session to resume.'),
            RequestBridgeCommandHandler::NO_BRIDGE => new ApiProblem(409, 'no_bridge', 'No bridge', 'The run names no bridge.'),
            RequestBridgeCommandHandler::UNKNOWN_BRIDGE => new ApiProblem(409, 'unknown_bridge', 'Unknown bridge', 'The bridge of the run is gone.'),
            RequestBridgeCommandHandler::BRIDGE_OUTDATED => new ApiProblem(409, 'bridge_outdated', 'Bridge outdated', 'The bridge is too old to take commands. Update it.'),
            RequestBridgeCommandHandler::PENDING => new ApiProblem(409, 'command_pending', 'Command pending', 'A command already waits on the run.'),
            RequestBridgeCommandHandler::CARD_HELD => new ApiProblem(409, 'card_held', 'Card held', 'A person paused the agents on the card of the run.'),
            CancelBridgeCommandHandler::NOTHING_PENDING => new ApiProblem(409, 'nothing_pending', 'Nothing pending', 'No command waits on the run, or its bridge already read it.'),
            default => null,
        };

        if (null !== $problem) {
            return new ApiProblemException($problem, $errors);
        }
        if (RequestBridgeCommandHandler::REASON_TOO_LONG === array_first($errors->errors)) {
            return ApiProblemException::validationFailed([['field' => 'reason', 'code' => 'too_long', 'message' => 'The reason is too long.']]);
        }

        return $errors;
    }
}
