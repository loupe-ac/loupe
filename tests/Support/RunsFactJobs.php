<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Bridge\Messenger\WriteWorkerRunFacts;
use App\Module\Bridge\Messenger\WriteWorkerRunFactsMessageHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** Runs the queued fact row writes of the bridge reports, as the insights worker does. */
final class RunsFactJobs
{
    /** @return list<WriteWorkerRunFacts> the messages it ran */
    public static function run(ContainerInterface $container): array
    {
        $transport = $container->get('messenger.transport.insights');
        \assert($transport instanceof InMemoryTransport);
        $handler = $container->get(WriteWorkerRunFactsMessageHandler::class);
        \assert($handler instanceof WriteWorkerRunFactsMessageHandler);

        $ran = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof WriteWorkerRunFacts) {
                $handler($message);
                $ran[] = $message;
            }
        }
        $transport->reset();

        return $ran;
    }
}
