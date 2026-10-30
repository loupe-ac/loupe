<?php

declare(strict_types=1);

namespace App\Tests\Observability\Command;

use App\Observability\Command\ReportQueueDepthCommand;
use App\Observability\Command\ReportQueueDepthHandler;
use App\Observability\QueueDepthReader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Sentry\ClientBuilder;
use Sentry\Metrics\Types\Metric;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReportQueueDepthHandlerTest extends KernelTestCase
{
    private const string DSN = 'https://key@o0.ingest.sentry.io/1';

    private HubInterface $hub;

    protected function setUp(): void
    {
        $this->hub = SentrySdk::getCurrentHub();
        self::bootKernel();
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM messenger_messages');
    }

    protected function tearDown(): void
    {
        SentrySdk::setCurrentHub($this->hub);
        parent::tearDown();
    }

    public function test_it_sends_two_gauges_for_each_queue_with_a_usable_dsn(): void
    {
        $seen = $this->captureMetrics();

        $depths = ($this->handler(self::DSN))(new ReportQueueDepthCommand());

        self::assertCount(5, $depths);
        $names = array_map(static fn (Metric $m): string => $m->getName(), $seen->metrics);
        self::assertCount(10, $names);
        self::assertSame(5, \count(array_keys($names, 'messenger.queue.pending', true)));
        self::assertSame(5, \count(array_keys($names, 'messenger.queue.oldest_age', true)));
        $queues = array_values(array_unique(array_map(static fn (Metric $m): mixed => $m->getAttributes()->toSimpleArray()['queue_name'], $seen->metrics)));
        self::assertEqualsCanonicalizing(['interactive', 'jobs', 'card_eval', 'card_sweep', 'failed'], $queues);
    }

    public function test_the_gauges_carry_the_value_the_attributes_and_the_unit(): void
    {
        $this->insertWaiting('card_sweep', '-90 seconds');
        $seen = $this->captureMetrics();

        ($this->handler(self::DSN))(new ReportQueueDepthCommand());

        $pending = $this->gauge($seen->metrics, 'messenger.queue.pending', 'card_sweep');
        self::assertEquals(1, $pending->getValue());
        self::assertSame('card_sweep', $pending->getAttributes()->toSimpleArray()['transport']);
        self::assertNull($pending->getUnit());

        $age = $this->gauge($seen->metrics, 'messenger.queue.oldest_age', 'card_sweep');
        self::assertGreaterThanOrEqual(90, $age->getValue());
        self::assertLessThan(120, $age->getValue());
        self::assertSame('card_sweep', $age->getAttributes()->toSimpleArray()['transport']);
        self::assertSame('second', (string) $age->getUnit());

        $jobsPending = $this->gauge($seen->metrics, 'messenger.queue.pending', 'jobs');
        self::assertEquals(0, $jobsPending->getValue());
        self::assertSame('jobs', $jobsPending->getAttributes()->toSimpleArray()['transport']);
    }

    public function test_it_does_nothing_without_a_usable_dsn(): void
    {
        $seen = $this->captureMetrics();

        self::assertSame([], ($this->handler(null))(new ReportQueueDepthCommand()));
        self::assertSame([], ($this->handler('not a dsn'))(new ReportQueueDepthCommand()));

        self::assertSame([], $seen->metrics);
    }

    private function handler(?string $dsn): ReportQueueDepthHandler
    {
        return new ReportQueueDepthHandler(self::getContainer()->get(QueueDepthReader::class), $dsn);
    }

    /**
     * @param list<Metric> $metrics
     */
    private function gauge(array $metrics, string $name, string $queue): Metric
    {
        foreach ($metrics as $metric) {
            if ($name === $metric->getName() && $queue === $metric->getAttributes()->toSimpleArray()['queue_name']) {
                return $metric;
            }
        }

        self::fail(\sprintf('No %s gauge for the %s queue.', $name, $queue));
    }

    private function insertWaiting(string $queue, string $availableOffset): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        self::getContainer()->get(Connection::class)->insert(
            'messenger_messages',
            ['body' => '{}', 'headers' => '{}', 'queue_name' => $queue, 'created_at' => $now, 'available_at' => $now->modify($availableOffset), 'delivered_at' => null],
            ['created_at' => Types::DATETIME_IMMUTABLE, 'available_at' => Types::DATETIME_IMMUTABLE, 'delivered_at' => Types::DATETIME_IMMUTABLE],
        );
    }

    private function captureMetrics(): \stdClass
    {
        $seen = new \stdClass();
        $seen->metrics = [];
        $client = ClientBuilder::create([
            'dsn' => self::DSN,
            'default_integrations' => false,
            'before_send_metric' => static function (Metric $metric) use ($seen): null {
                $seen->metrics[] = $metric;

                return null;
            },
        ])->getClient();
        SentrySdk::setCurrentHub(new Hub($client));

        return $seen;
    }
}
