<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Command;

use App\Module\Insights\Command\ReportPublicPreviewCommand;
use App\Module\Insights\Command\ReportPublicPreviewHandler;
use App\Module\Insights\Preview\PublicPreviewEntries;
use App\Module\Insights\Service\PublicPreviewUsageReader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Sentry\ClientBuilder;
use Sentry\Metrics\Types\Metric;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReportPublicPreviewHandlerTest extends KernelTestCase
{
    private const string DSN = 'https://key@o0.ingest.sentry.io/1';

    private HubInterface $hub;

    protected function setUp(): void
    {
        $this->hub = SentrySdk::getCurrentHub();
        self::bootKernel();
        $this->connection()->executeStatement('DELETE FROM cache_items WHERE item_id LIKE \'%insights\_public\_preview%\'');
    }

    protected function tearDown(): void
    {
        SentrySdk::setCurrentHub($this->hub);
        parent::tearDown();
    }

    public function test_the_reader_counts_the_live_png_entries_and_their_bytes_only(): void
    {
        $this->insert(PublicPreviewEntries::PNG_PREFIX.'one', 'abcde', 3600);
        $this->insert(PublicPreviewEntries::PNG_PREFIX.'two', 'abc', null);
        $this->insert(PublicPreviewEntries::PNG_PREFIX.'old', 'abcdefghij', 1, time() - 100);
        $this->insert(PublicPreviewEntries::ENTRY_PREFIX.'abcdefghijkl', 'small', 3600);
        $this->insert('other_entry', 'zzzzzzzz', 3600);

        $usage = self::getContainer()->get(PublicPreviewUsageReader::class)->read();

        self::assertSame(2, $usage->images);
        self::assertSame(8, $usage->imageBytes);
        self::assertGreaterThan(0, $usage->databaseBytes);
    }

    public function test_it_sends_the_three_gauges_with_a_usable_dsn(): void
    {
        $this->insert(PublicPreviewEntries::PNG_PREFIX.'one', 'abcde', 3600);
        $seen = $this->captureMetrics();

        $usage = ($this->handler(self::DSN))(new ReportPublicPreviewCommand());

        self::assertSame(1, $usage?->images);
        $values = [];
        foreach ($seen->metrics as $metric) {
            $values[$metric->getName()] = $metric->getValue();
        }
        self::assertEqualsCanonicalizing(['public_preview.count', 'public_preview.bytes', 'database.bytes'], array_keys($values));
        self::assertEquals(1, $values['public_preview.count']);
        self::assertEquals(5, $values['public_preview.bytes']);
        self::assertGreaterThan(0, $values['database.bytes']);
    }

    public function test_it_does_nothing_without_a_usable_dsn(): void
    {
        $seen = $this->captureMetrics();

        self::assertNull(($this->handler(null))(new ReportPublicPreviewCommand()));
        self::assertNull(($this->handler('not a dsn'))(new ReportPublicPreviewCommand()));

        self::assertSame([], $seen->metrics);
    }

    private function handler(?string $dsn): ReportPublicPreviewHandler
    {
        return new ReportPublicPreviewHandler(self::getContainer()->get(PublicPreviewUsageReader::class), $dsn);
    }

    private function insert(string $id, string $data, ?int $lifetime, ?int $time = null): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO cache_items (item_id, item_data, item_lifetime, item_time) VALUES (:id, :data, :lifetime, :time)',
            ['id' => $id, 'data' => $data, 'lifetime' => $lifetime, 'time' => $time ?? time()],
            ['data' => ParameterType::LARGE_OBJECT],
        );
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
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
