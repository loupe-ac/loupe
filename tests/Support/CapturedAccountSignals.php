<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Mercure\UserTopicBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

/**
 * Captures what the real services of a booted kernel send to the account topic of a user. Install it
 * before anything builds the hub, because the container hands a replacement only to services it builds later.
 */
final class CapturedAccountSignals
{
    /** @var list<Update> */
    private array $published = [];

    private function __construct(
        private readonly ContainerInterface $container,
    ) {
    }

    public static function install(ContainerInterface $container): self
    {
        $captured = new self($container);
        $container->set('mercure.hub.default', new MockHub(
            'http://mercure/.well-known/mercure',
            new StaticTokenProvider('token'),
            function (Update $update) use ($captured): string {
                $captured->published[] = $update;

                return 'id';
            },
        ));

        return $captured;
    }

    /** Ends the request, which is when the live publisher sends what it holds. */
    public function endRequest(): void
    {
        $dispatcher = $this->container->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $kernel = $this->container->get('kernel');
        \assert($kernel instanceof \Symfony\Component\HttpKernel\HttpKernelInterface);
        $dispatcher->dispatch(new TerminateEvent($kernel, Request::create('/'), new Response()), KernelEvents::TERMINATE);
    }

    /**
     * The areas that account.changed signals named on the topic of the user, in order.
     *
     * @return list<string>
     */
    public function areasOf(Uuid $userId): array
    {
        $topics = $this->container->get(UserTopicBuilder::class);
        \assert($topics instanceof UserTopicBuilder);
        $topic = $topics->forAccount($userId);

        $areas = [];
        foreach ($this->published as $update) {
            if (!\in_array($topic, $update->getTopics(), true)) {
                continue;
            }
            $data = json_decode($update->getData(), true, 512, \JSON_THROW_ON_ERROR);
            \assert(\is_array($data) && 'account.changed' === $data['type'] && \is_string($data['area']));
            $areas[] = $data['area'];
        }

        return $areas;
    }
}
