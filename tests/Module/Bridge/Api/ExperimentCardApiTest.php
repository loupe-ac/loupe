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

/** The experiment card routes of the public API under /api/v1/projects/{project}/experiments. */
final class ExperimentCardApiTest extends WebTestCase
{
    use BridgeScenario;

    private const string EXPERIMENT = 'php-lsp';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private Project $project;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = $this->em();
        $this->owner = $this->user($this->em, 'experiment-api-'.uniqid().'@example.com');
        $this->project = $this->project($this->em, $this->owner, 'Experiment API');
        $this->token = ApiKeyCredential::mint(static::getContainer(), $this->owner);
    }

    public function test_the_list_walks_the_cards_by_cursor_in_the_order_of_the_cards_tab(): void
    {
        foreach ([1, 2, 3] as $number) {
            $this->runOfCard($number, \sprintf('+%d hours', $number));
        }

        $this->send(Request::METHOD_GET, $this->base().'?limit=2');
        self::assertResponseStatusCodeSame(200);
        $first = $this->json();
        self::assertSame([3, 2], array_column($first['items'], 'number'));
        self::assertIsString($first['next']);
        self::assertStringContainsString('cursor=', $first['next']);

        $this->send(Request::METHOD_GET, $first['next']);
        self::assertResponseStatusCodeSame(200);
        $second = $this->json();
        self::assertSame([1], array_column($second['items'], 'number'));
        self::assertNull($second['next']);
    }

    public function test_a_card_row_names_its_variant_and_its_left_out_reasons_and_no_exclusion(): void
    {
        $this->runOfCard(4, '+1 hour');

        $this->send(Request::METHOD_GET, $this->base());

        $row = $this->json()['items'][0];
        self::assertSame(['cardId', 'number', 'title', 'variant', 'pullRequestUrls', 'leftOut', 'exclusion'], array_keys($row));
        self::assertSame(4, $row['number']);
        self::assertSame('a', $row['variant']);
        self::assertSame([], $row['pullRequestUrls']);
        self::assertNull($row['exclusion']);
    }

    public function test_an_unknown_experiment_is_not_found(): void
    {
        $this->send(Request::METHOD_GET, $this->base('nothing-here'));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('experiment_not_found', $this->json()['code']);
    }

    public function test_a_bad_cursor_and_a_bad_flag_are_refused(): void
    {
        $this->runOfCard(1, '+1 hour');

        $this->send(Request::METHOD_GET, $this->base().'?cursor=nonsense');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_cursor', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->base().'?leftOut=maybe');
        self::assertResponseStatusCodeSame(422);
    }

    public function test_the_excluded_card_shows_in_the_left_out_filter_and_never_in_the_kept_one(): void
    {
        $this->runOfCard(1, '+1 hour');
        $this->runOfCard(2, '+2 hours');
        $this->send(Request::METHOD_PUT, $this->base().'/2/exclusion', '{"reason":"Changes no PHP"}');
        self::assertResponseStatusCodeSame(200);

        $this->send(Request::METHOD_GET, $this->base().'?leftOut=true');
        $reasons = array_column($this->json()['items'], 'leftOut', 'number');
        self::assertContains('excluded', $reasons[2]);
        self::assertNotContains('excluded', $reasons[1]);

        $this->send(Request::METHOD_GET, $this->base().'?leftOut=false');
        self::assertNotContains(2, array_column($this->json()['items'], 'number'));
    }

    public function test_an_exclusion_is_set_replaced_and_cleared(): void
    {
        $this->runOfCard(7, '+1 hour');

        $this->send(Request::METHOD_PUT, $this->base().'/7/exclusion', '{"reason":"  Changes no PHP  "}');
        self::assertResponseStatusCodeSame(200);
        $row = $this->json();
        self::assertSame(['no-history', 'excluded'], $row['leftOut']);
        self::assertSame('Changes no PHP', $row['exclusion']['reason']);
        self::assertSame($this->owner->fullName, $row['exclusion']['excludedBy']);
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));

        $this->send(Request::METHOD_PUT, $this->base().'/7/exclusion', '{"reason":"Second reason"}');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Second reason', $this->json()['exclusion']['reason']);

        $this->send(Request::METHOD_DELETE, $this->base().'/7/exclusion');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['no-history'], $this->json()['leftOut']);
        self::assertNull($this->json()['exclusion']);

        $this->send(Request::METHOD_DELETE, $this->base().'/7/exclusion');
        self::assertResponseStatusCodeSame(200);
    }

    public function test_a_card_the_experiment_does_not_list_is_refused(): void
    {
        $this->runOfCard(7, '+1 hour');

        $this->send(Request::METHOD_PUT, $this->base().'/99/exclusion', '{"reason":"Nope"}');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('card_not_in_experiment', $this->json()['code']);

        $this->send(Request::METHOD_DELETE, $this->base().'/99/exclusion');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('card_not_in_experiment', $this->json()['code']);

        $this->send(Request::METHOD_PUT, $this->base('nothing-here').'/7/exclusion', '{"reason":"Nope"}');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('experiment_not_found', $this->json()['code']);
    }

    public function test_a_blank_or_long_reason_is_refused(): void
    {
        $this->runOfCard(7, '+1 hour');

        foreach (['{}', '{"reason":""}', '{"reason":"   "}', '{"reason":"'.str_repeat('x', 501).'"}'] as $body) {
            $this->send(Request::METHOD_PUT, $this->base().'/7/exclusion', $body);
            self::assertResponseStatusCodeSame(422, $body);
        }
    }

    public function test_a_project_of_another_owner_is_not_found_and_no_token_is_refused(): void
    {
        $this->runOfCard(7, '+1 hour');
        $stranger = $this->user($this->em, 'experiment-api-stranger-'.uniqid().'@example.com');
        $strangerToken = ApiKeyCredential::mint(static::getContainer(), $stranger);

        $this->send(Request::METHOD_PUT, $this->base().'/7/exclusion', '{"reason":"Nope"}', $strangerToken);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->base(), '', null);
        self::assertResponseStatusCodeSame(401);
    }

    public function test_a_pinned_card_with_no_run_is_listed_with_no_number_to_reach(): void
    {
        $this->runOfCard(1, '+1 hour');
        $this->em->persist(new ExperimentPin($this->project, Uuid::v7(), self::EXPERIMENT, 'a'));
        $this->em->flush();

        $this->send(Request::METHOD_GET, $this->base());

        self::assertSame([1, null], array_column($this->json()['items'], 'number'));
        self::assertSame(['no-run'], $this->json()['items'][1]['leftOut']);
    }

    private function runOfCard(int $number, string $at): void
    {
        $run = $this->seedRun($this->em, $this->project, receivedAt: new \DateTimeImmutable('2026-09-01 10:00:00')->modify($at), cardNumber: $number, cardId: Uuid::v7(), runKey: Uuid::v7());
        $run->experiment = self::EXPERIMENT;
        $run->variant = 'a';
        $this->em->flush();
    }

    private function base(string $experiment = self::EXPERIMENT): string
    {
        return '/api/v1/projects/'.$this->project->id.'/experiments/'.$experiment.'/cards';
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
