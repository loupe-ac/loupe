<?php

declare(strict_types=1);

namespace App\Tests\Mercure;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\LiveUpdates;
use App\Mercure\LiveUpdateTransactionBuffer;
use App\Tests\Support\FeatureFlags;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

/** Runs through the real connection, so the dama savepoint that stands in for the transaction is covered. */
final class LiveUpdateTransactionIntegrationTest extends KernelTestCase
{
    /** @var list<Update> */
    private array $published = [];

    private LiveUpdatePublisher $publisher;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $buffer = self::getContainer()->get(LiveUpdateTransactionBuffer::class);
        self::assertInstanceOf(LiveUpdateTransactionBuffer::class, $buffer);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->publisher = new LiveUpdatePublisher(
            new RequestStack(),
            $buffer,
            FeatureFlags::service([LiveUpdates::FLAG => true]),
            new NullLogger(),
            fn (): HubInterface => new MockHub(
                'http://mercure/.well-known/mercure',
                new StaticTokenProvider('token'),
                function (Update $update): string {
                    $this->published[] = $update;

                    return 'id';
                },
            ),
        );
    }

    public function test_a_signal_queued_in_a_committed_transaction_is_published(): void
    {
        $this->em->wrapInTransaction(function (): void {
            $this->publisher->queue('https://loupe.test/committed', ['type' => 'a']);
        });
        $this->publisher->publish();

        self::assertSame([['https://loupe.test/committed']], $this->topics());
    }

    public function test_a_signal_queued_in_a_rolled_back_transaction_is_not_published(): void
    {
        try {
            $this->em->wrapInTransaction(function (): void {
                $this->publisher->queue('https://loupe.test/rolled-back', ['type' => 'a']);

                throw new \RuntimeException('the change failed');
            });
        } catch (\RuntimeException) {
            $this->publisher->queue('https://loupe.test/guard', ['type' => 'a']);
        }
        $this->publisher->publish();

        // The guard signal proves the publisher ran, so the empty result is about the rollback.
        self::assertSame([['https://loupe.test/guard']], $this->topics());
    }

    public function test_a_signal_queued_in_a_nested_transaction_waits_for_the_outer_commit(): void
    {
        $this->em->wrapInTransaction(function (): void {
            $this->em->wrapInTransaction(function (): void {
                $this->publisher->queue('https://loupe.test/nested', ['type' => 'a']);
            });
            $this->publisher->publish();
            self::assertSame([], $this->published);
        });
        $this->publisher->publish();

        self::assertSame([['https://loupe.test/nested']], $this->topics());
    }

    /** @return list<array<mixed>> */
    private function topics(): array
    {
        return array_map(static fn (Update $update): array => $update->getTopics(), $this->published);
    }
}
