<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Api;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\ApiKeyCredential;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The metric routes of the public API under /api/v1/projects/{project}/metrics. */
final class MetricApiTest extends WebTestCase
{
    use BridgeScenario;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private Project $project;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = $this->em();
        $this->owner = $this->user($this->em, 'metric-api-'.uniqid().'@example.com');
        $this->project = $this->project($this->em, $this->owner, 'Metric API');
        $this->token = ApiKeyCredential::mint(static::getContainer(), $this->owner);
    }

    public function test_the_list_walks_the_metrics_by_key_and_each_entry_names_what_it_takes(): void
    {
        $this->get($this->base().'?limit=3');
        self::assertResponseStatusCodeSame(200);
        $first = $this->json();
        $keys = array_column($first['items'], 'key');
        self::assertCount(3, $keys);
        $sorted = $keys;
        sort($sorted, \SORT_STRING);
        self::assertSame($sorted, $keys);
        self::assertSame(['key', 'units', 'valueType', 'statistics', 'groups', 'description', 'label'], array_keys($first['items'][0]));
        self::assertIsString($first['next']);

        $this->get($first['next']);
        $second = $this->json();
        self::assertGreaterThan(end($keys), $second['items'][0]['key']);
    }

    public function test_one_metric_reads_as_a_series_with_its_window(): void
    {
        $this->get($this->base().'/query?unit=run&metric=cost&statistic=sum&range=thirty-days&bucket=week');

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame('run', $body['unit']);
        self::assertSame('cost', $body['metric']);
        self::assertSame('sum', $body['statistic']);
        self::assertNull($body['formula']);
        self::assertNull($body['window']['previous']);
        self::assertIsList($body['series']);
    }

    public function test_a_formula_reads_its_rows_and_has_no_metric(): void
    {
        $this->get($this->base().'/query?unit=run&rows='.rawurlencode('A:cache-read-tokens:sum,B:cache-write-tokens:sum').'&formula='.rawurlencode('A / B'));

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertNull($body['metric']);
        self::assertNull($body['statistic']);
        self::assertSame('A / B', $body['formula']['formula']);
        self::assertSame(['A', 'B'], array_column($body['formula']['rows'], 'letter'));
    }

    public function test_a_refused_combination_names_the_field_and_the_values_to_use(): void
    {
        $this->get($this->base().'/query?unit=run&metric=cost&statistic=sum&range=seven-days&bucket=week');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->json()['code']);
        self::assertSame('bucket', $this->json()['errors'][0]['field']);
        self::assertStringContainsString('day', $this->json()['errors'][0]['message']);

        $this->get($this->base().'/query?unit=run&metric=nothing&statistic=sum');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('metric', $this->json()['errors'][0]['field']);

        $this->get($this->base().'/query?unit=planet&metric=cost&statistic=sum');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('unit', $this->json()['errors'][0]['field']);

        $this->get($this->base().'/query?unit=run');
        self::assertResponseStatusCodeSame(422);

        $this->get($this->base().'/query?unit=run&metric=cost&statistic=sum&rows=A:cost:sum&formula=A');
        self::assertResponseStatusCodeSame(422);

        $this->get($this->base().'/query?unit=run&rows=A:cost:sum&formula='.rawurlencode('A +'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('formula', $this->json()['errors'][0]['field']);

        foreach (['A:cost' => 'malformed row', 'A:nope:sum' => 'unknown metric', 'Z:cost:sum' => 'bad letter'] as $rows => $case) {
            $this->get($this->base().'/query?unit=run&formula=A&rows='.rawurlencode($rows));
            self::assertResponseStatusCodeSame(422, $case);
            self::assertSame('rows', $this->json()['errors'][0]['field'], $case);
        }

        $this->get($this->base().'/query?unit=run&rows=A:cost:sum&formula=A&format=nope');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('format', $this->json()['errors'][0]['field']);
    }

    public function test_compare_previous_adds_the_previous_window(): void
    {
        $this->get($this->base().'/query?unit=run&metric=cost&statistic=sum&compare=previous');

        self::assertResponseStatusCodeSame(200);
        self::assertNotNull($this->json()['window']['previous']);
    }

    public function test_a_stranger_and_no_token_are_refused(): void
    {
        $stranger = ApiKeyCredential::mint(static::getContainer(), $this->user($this->em, 'metric-api-stranger-'.uniqid().'@example.com'));

        $this->get($this->base().'/query?unit=run&metric=cost&statistic=sum', $stranger);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);

        $this->get($this->base(), null);
        self::assertResponseStatusCodeSame(401);
    }

    private function base(): string
    {
        return '/api/v1/projects/'.$this->project->id.'/metrics';
    }

    private function get(string $url, ?string $token = ''): void
    {
        $bearer = '' === $token ? $this->token : $token;
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $url, server: null === $bearer ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer]);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
