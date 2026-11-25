<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

/** Thrown when Loupe cannot open the wait a worker asked for. The message is fit for the worker. */
final class WorkerWaitRefused extends \DomainException
{
}
