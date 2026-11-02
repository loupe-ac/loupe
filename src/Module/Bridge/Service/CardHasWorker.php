<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

/** Thrown when an interactive session opens on a card where a worker runs or holds a claim. */
final class CardHasWorker extends \DomainException
{
    /** The answer an MCP tool gives. */
    public const string AGENT_MESSAGE = 'worker: A worker already runs on this card, so no session may open on it. Make no edit. The owner can wait for the worker, or stop it with worker_run_stop.';

    public function __construct()
    {
        parent::__construct('A worker already runs on the card.');
    }
}
