<?php

declare(strict_types=1);

namespace App\Mercure;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Sends a private live update to the pages open on a topic. The update names
 * what changed and the origin of the change, so a page can tell its own changes.
 *
 * It publishes after the response or the message, so a hub that hangs never
 * slows a change, and a failure is only logged: the page catches up on reload.
 * At terminate it runs below the outbox drain (16) and above the end of the
 * Sentry transaction (5), so its hub calls stay in the trace. A listener that
 * queues a live update at terminate must run above 8.
 */
final class LiveUpdatePublisher implements ResetInterface
{
    public const string ORIGIN_HEADER = 'X-Loupe-Origin';

    private const int ORIGIN_MAX_LENGTH = 64;

    /** @var array<string, array{topic: string, data: string}> */
    private array $pending = [];

    /**
     * @param \Closure(): HubInterface $hub a closure, because building the hub
     *                                      reads MERCURE_JWT_SECRET, which an
     *                                      instance with no hub never sets
     */
    public function __construct(
        private readonly RequestStack $requests,
        private readonly LiveUpdateTransactionBuffer $transactions,
        private readonly FeatureFlagService $featureFlags,
        private readonly LoggerInterface $logger,

        #[AutowireServiceClosure(HubInterface::class)]
        private readonly \Closure $hub,
    ) {
    }

    /**
     * Inside a transaction the signal waits for the commit, so a rollback
     * signals nothing.
     *
     * @param array<string, scalar|list<scalar>|null> $payload
     */
    public function queue(string $topic, array $payload): void
    {
        if (!\array_key_exists('origin', $payload)) {
            $payload['origin'] = $this->origin();
        }

        $data = json_encode($payload, \JSON_THROW_ON_ERROR);
        if ($this->transactions->isOpen()) {
            $this->transactions->hold($topic, $data);

            return;
        }

        $this->add($topic, $data);
    }

    /**
     * The messenger worker resets this service after each message and
     * terminates only at its time limit, so a worker publishes here. A failed
     * message publishes after its own failure listeners, which may change state.
     */
    #[AsEventListener(WorkerMessageHandledEvent::class)]
    #[AsEventListener(WorkerMessageFailedEvent::class, priority: -16)]
    #[AsEventListener(KernelEvents::TERMINATE, priority: 8)]
    #[AsEventListener(ConsoleEvents::TERMINATE)]
    public function publish(?object $event = null): void
    {
        foreach ($this->transactions->takeCommitted() as $update) {
            $this->add($update['topic'], $update['data']);
        }

        $pending = $this->pending;
        $this->pending = [];
        if ([] === $pending || !$this->featureFlags->isEnabled(LiveUpdates::FLAG)) {
            return;
        }

        foreach ($pending as $update) {
            try {
                ($this->hub)()->publish(new Update($update['topic'], $update['data'], private: true));
                \Sentry\traceMetrics()->count('live.published', 1, ['type' => $this->typeOf($update['data']), 'cause' => $this->causeOf($event)]);
            } catch (\Throwable $e) {
                $this->logger->warning('live_updates.publish_failed', [
                    'topic' => $update['topic'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    #[\Override]
    public function reset(): void
    {
        $this->pending = [];
        $this->transactions->reset();
    }

    private function add(string $topic, string $data): void
    {
        $this->pending[$topic."\n".$data] = ['topic' => $topic, 'data' => $data];
    }

    private function typeOf(string $data): string
    {
        $payload = json_decode($data, true);
        $type = \is_array($payload) ? ($payload['type'] ?? null) : null;

        return \is_string($type) ? $type : 'unknown';
    }

    /** The route in a web request, the message class in a worker and the command name in a console run. */
    private function causeOf(?object $event): string
    {
        $cause = match (true) {
            $event instanceof TerminateEvent => $event->getRequest()->attributes->get('_route'),
            $event instanceof ConsoleTerminateEvent => $event->getCommand()?->getName(),
            $event instanceof WorkerMessageHandledEvent, $event instanceof WorkerMessageFailedEvent => new \ReflectionClass($event->getEnvelope()->getMessage())->getShortName(),
            default => null,
        };

        return \is_string($cause) && '' !== $cause ? $cause : 'unknown';
    }

    private function origin(): ?string
    {
        $header = $this->requests->getMainRequest()?->headers->get(self::ORIGIN_HEADER);
        if (null === $header || !mb_check_encoding($header, 'UTF-8')) {
            return null;
        }

        $origin = mb_substr(trim($header), 0, self::ORIGIN_MAX_LENGTH);

        return '' === $origin ? null : $origin;
    }
}
