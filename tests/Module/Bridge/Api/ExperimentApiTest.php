<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Api;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\ExperimentPin;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\ApiKeyCredential;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** The experiment list, comparison and archive routes of the public API under /api/v1/projects/{project}/experiments. */
final class ExperimentApiTest extends WebTestCase
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
        $this->owner = $this->user($this->em, 'experiment-list-api-'.uniqid().'@example.com');
        $this->project = $this->project($this->em, $this->owner, 'Experiment list API');
        $this->token = ApiKeyCredential::mint(static::getContainer(), $this->owner);
    }

    public function test_the_list_walks_the_latest_run_first_and_the_pin_only_experiment_last(): void
    {
        $this->runOf('php-lsp', '+1 hour');
        $this->runOf('go-lsp', '+3 hours');
        $this->runOf('ts-lsp', '+2 hours');
        $this->em->persist(new ExperimentPin($this->project, Uuid::v7(), 'only-pinned', 'a'));
        $this->em->flush();

        $this->send(Request::METHOD_GET, $this->base().'?limit=3');
        self::assertResponseStatusCodeSame(200);
        $first = $this->json();
        self::assertSame(['go-lsp', 'ts-lsp', 'php-lsp'], array_column($first['items'], 'name'));
        self::assertSame(['name', 'cards', 'lastRunAt', 'archived'], array_keys($first['items'][0]));
        self::assertSame(1, $first['items'][0]['cards']);
        self::assertFalse($first['items'][0]['archived']);
        self::assertIsString($first['next']);

        $this->send(Request::METHOD_GET, $first['next']);
        self::assertResponseStatusCodeSame(200);
        $second = $this->json();
        self::assertSame(['only-pinned'], array_column($second['items'], 'name'));
        self::assertNull($second['items'][0]['lastRunAt']);
        self::assertNull($second['next']);
    }

    public function test_experiments_with_the_same_last_run_time_walk_by_name(): void
    {
        foreach (['b-exp', 'a-exp', 'c-exp'] as $name) {
            $this->runOf($name, '+1 hour');
        }

        $this->send(Request::METHOD_GET, $this->base().'?limit=2');
        $first = $this->json();
        self::assertSame(['a-exp', 'b-exp'], array_column($first['items'], 'name'));

        $this->send(Request::METHOD_GET, $first['next']);
        self::assertSame(['c-exp'], array_column($this->json()['items'], 'name'));
    }

    public function test_a_bad_cursor_a_bad_limit_and_a_bad_flag_are_refused(): void
    {
        $this->runOf('php-lsp', '+1 hour');

        $this->send(Request::METHOD_GET, $this->base().'?cursor=nonsense');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_cursor', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->base().'?limit=0');
        self::assertResponseStatusCodeSame(422);

        $this->send(Request::METHOD_GET, $this->base().'?archived=maybe');
        self::assertResponseStatusCodeSame(422);
    }

    public function test_an_archive_moves_the_experiment_between_the_two_lists_and_a_restore_moves_it_back(): void
    {
        $this->runOf('php-lsp', '+1 hour');
        $this->runOf('go-lsp', '+2 hours');

        $this->send(Request::METHOD_PATCH, $this->base().'/php-lsp', '{"archived":true}');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['name' => 'php-lsp', 'cards' => 1, 'lastRunAt' => $this->json()['lastRunAt'], 'archived' => true], $this->json());
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));

        $this->send(Request::METHOD_GET, $this->base());
        self::assertSame(['go-lsp'], array_column($this->json()['items'], 'name'));
        $this->send(Request::METHOD_GET, $this->base().'?archived=true');
        self::assertSame(['php-lsp'], array_column($this->json()['items'], 'name'));

        $this->send(Request::METHOD_GET, $this->base().'/php-lsp');
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->json()['archived']);

        $this->send(Request::METHOD_PATCH, $this->base().'/php-lsp', '{"archived":false}');
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->json()['archived']);

        $this->send(Request::METHOD_PATCH, $this->base().'/php-lsp', '{"archived":false}');
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->json()['archived']);

        $this->send(Request::METHOD_GET, $this->base().'?archived=true');
        self::assertSame([], $this->json()['items']);
    }

    public function test_the_comparison_names_the_headline_the_variants_and_the_metrics(): void
    {
        $this->runOf('php-lsp', '+1 hour');

        $this->send(Request::METHOD_GET, $this->base().'/php-lsp');

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame(['experiment', 'archived', 'baseline', 'confidenceLevel', 'declaredMetrics', 'unknownMetrics', 'unknownGuards', 'minFinishedCards', 'headline', 'variants', 'metrics', 'includedCards', 'leftOutCards'], array_keys($body));
        self::assertSame('php-lsp', $body['experiment']);
        self::assertFalse($body['archived']);
        self::assertSame(['goal', 'baselineArm', 'comparisons', 'controls', 'guards'], array_keys($body['headline']));
        self::assertSame('a', $body['variants'][0]['name']);
        self::assertSame(1, $body['leftOutCards']);
        self::assertNotSame([], $body['metrics']);
    }

    public function test_an_unknown_experiment_is_not_found_for_a_read_and_for_both_archive_directions(): void
    {
        $this->send(Request::METHOD_GET, $this->base().'/nothing-here');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('experiment_not_found', $this->json()['code']);

        foreach (['true', 'false'] as $archived) {
            $this->send(Request::METHOD_PATCH, $this->base().'/nothing-here', '{"archived":'.$archived.'}');
            self::assertResponseStatusCodeSame(404);
            self::assertSame('experiment_not_found', $this->json()['code']);
        }
    }

    public function test_an_archive_body_without_a_boolean_is_refused(): void
    {
        $this->runOf('php-lsp', '+1 hour');

        foreach (['{}', '{"archived":null}', '{"archived":"yes"}'] as $body) {
            $this->send(Request::METHOD_PATCH, $this->base().'/php-lsp', $body);
            self::assertResponseStatusCodeSame(422, $body);
        }
    }

    public function test_a_project_of_another_owner_is_not_found_and_no_token_is_refused(): void
    {
        $this->runOf('php-lsp', '+1 hour');
        $stranger = $this->user($this->em, 'experiment-list-api-stranger-'.uniqid().'@example.com');
        $strangerToken = ApiKeyCredential::mint(static::getContainer(), $stranger);

        $this->send(Request::METHOD_PATCH, $this->base().'/php-lsp', '{"archived":true}', $strangerToken);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->base(), '', null);
        self::assertResponseStatusCodeSame(401);
    }

    private function runOf(string $experiment, string $at): void
    {
        $run = $this->seedRun($this->em, $this->project, receivedAt: new \DateTimeImmutable('2026-09-01 10:00:00')->modify($at), cardNumber: random_int(1, 100000), cardId: Uuid::v7(), runKey: Uuid::v7());
        $run->experiment = $experiment;
        $run->variant = 'a';
        $this->em->flush();
    }

    private function base(): string
    {
        return '/api/v1/projects/'.$this->project->id.'/experiments';
    }

    private function send(string $method, string $url, string $body = '', ?string $token = ''): void
    {
        $bearer = '' === $token ? $this->token : $token;
        $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'] + (null === $bearer ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer]), content: $body);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
