<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Api;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Repository\CardRepository;
use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Repository\ApiKeyRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\PauseKind;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\ApiKeyCredential;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** The card routes of the public API under /api/v1/projects/{project}/cards. */
final class CardApiTest extends WebTestCase
{
    use BoardColumnFixtures;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private Project $project;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $scenario = new OAuthScenario(static::getContainer());
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->owner = $scenario->createUser('cards-api-'.uniqid().'@example.com');
        $this->project = new Project($this->owner, 'cards-shop');
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
        $this->token = ApiKeyCredential::mint(static::getContainer(), $this->owner);
    }

    public function test_a_card_is_created_with_a_location_and_the_key_as_reporter(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), '{"title":"Ship it","body":"Now","type":"feature"}');

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertSame(1, $body['number']);
        self::assertSame('Ship it', $body['title']);
        self::assertSame('backlog', $body['status']);
        self::assertSame('api-key', $body['reporter']);
        self::assertStringEndsWith($this->base().'/1', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
        self::assertSame('Ship it', $this->findCard(1)?->title);
    }

    public function test_a_card_lands_in_the_named_column_and_a_person_can_be_written_down_as_reporter(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), '{"title":"Done","body":"","type":"feature","status":"done","reporter":"human"}');

        self::assertResponseStatusCodeSame(201);
        self::assertSame('done', $this->json()['status']);
        self::assertSame('human', $this->json()['reporter']);
        self::assertNotNull($this->json()['completedAt']);
    }

    public function test_the_history_names_the_key_that_created_and_changed_the_card(): void
    {
        $this->createCard('Tracked');
        $this->send(Request::METHOD_PATCH, $this->base().'/1', '{"title":"Tracked again"}');
        self::assertResponseStatusCodeSame(200);

        $this->send(Request::METHOD_GET, $this->base().'/1/events');

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertNull($body['next']);
        self::assertSame('created', $body['items'][count($body['items']) - 1]['kind']);
        foreach ($body['items'] as $event) {
            self::assertSame('api-key', $event['actor']['kind']);
            self::assertSame('Test key', $event['actor']['apiKey']['name']);
            self::assertNotEmpty($event['actor']['apiKey']['id']);
            self::assertSame($this->owner->fullName, $event['actor']['name']);
        }
    }

    public function test_a_retry_with_the_same_key_creates_one_card(): void
    {
        $payload = '{"title":"Once","body":"","type":"feature"}';
        $this->send(Request::METHOD_POST, $this->base(), $payload, ['HTTP_IDEMPOTENCY_KEY' => 'create-once']);
        self::assertResponseStatusCodeSame(201);
        $first = $this->json();

        $this->send(Request::METHOD_POST, $this->base(), $payload, ['HTTP_IDEMPOTENCY_KEY' => 'create-once']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($first, $this->json());
        $this->send(Request::METHOD_GET, $this->base());
        self::assertCount(1, $this->json()['items']);
    }

    public function test_a_refused_create_lists_each_field_in_errors(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), '{"title":"  ","body":"x","type":"nope","status":"nowhere"}');

        self::assertResponseStatusCodeSame(422);
        $body = $this->json();
        self::assertSame('validation_failed', $body['code']);
        self::assertSame([['field' => 'title', 'code' => 'title_blank']], array_map(static fn (array $e): array => ['field' => $e['field'], 'code' => $e['code']], $body['errors']));

        $this->send(Request::METHOD_POST, $this->base(), '{"title":"A","body":"x","type":"nope","status":"nowhere"}');
        self::assertResponseStatusCodeSame(422);
        self::assertEqualsCanonicalizing(['unknown_status', 'unknown_type'], array_column($this->json()['errors'], 'code'));
        self::assertSame(['field', 'code', 'message'], array_keys($this->json()['errors'][0]));
    }

    public function test_a_key_cannot_claim_the_agent_reporter_and_a_null_field_is_refused(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), '{"title":"A","body":"x","type":"feature","reporter":"agent"}');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('reporter_invalid', $this->json()['errors'][0]['code']);

        $this->send(Request::METHOD_POST, $this->base(), '{"title":"A","body":"x","type":"feature","status":null}');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->json()['code']);
    }

    public function test_a_link_to_an_unknown_document_is_refused_with_its_field(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), '{"title":"A","body":"x","type":"feature","documentIds":["0198f6e0-0000-7000-8000-000000000000"]}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['documentIds', 'document_unknown'], [$this->json()['errors'][0]['field'], $this->json()['errors'][0]['code']]);
        self::assertNull($this->findCard(1));
    }

    public function test_a_card_is_read_by_its_number_and_by_the_project_slug(): void
    {
        $this->createCard('Readable');

        $this->send(Request::METHOD_GET, '/api/v1/projects/'.$this->project->slug.'/cards/1');

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame('Readable', $body['title']);
        foreach (['cardId', 'number', 'body', 'position', 'pullRequests', 'documents', 'artifacts', 'siteReviewComments', 'relatedCards', 'parent', 'laneEnabled', 'requiresHumanReview', 'values', 'children', 'progress', 'pause', 'state'] as $key) {
            self::assertArrayHasKey($key, $body);
        }
    }

    public function test_a_missing_card_and_a_missing_project_are_not_found(): void
    {
        $this->send(Request::METHOD_GET, $this->base().'/99');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('card_not_found', $this->json()['code']);

        $this->send(Request::METHOD_GET, '/api/v1/projects/nowhere/cards/1');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);

        $this->send(Request::METHOD_GET, '/api/v1/projects/nowhere/cards');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);
    }

    public function test_another_owners_card_is_not_found(): void
    {
        $this->createCard('Mine');
        $other = new OAuthScenario(static::getContainer())->createUser('cards-other-'.uniqid().'@example.com');
        $otherToken = ApiKeyCredential::mint(static::getContainer(), $other);

        $this->send(Request::METHOD_GET, $this->base().'/1', token: $otherToken);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);
    }

    public function test_the_list_reads_oldest_first_with_the_summary_shape(): void
    {
        $this->createCard('First');
        $this->createCard('Second');

        $this->send(Request::METHOD_GET, $this->base());

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame(['First', 'Second'], array_column($body['items'], 'title'));
        self::assertSame(['cardId', 'number', 'title', 'type', 'status', 'reporter', 'parentCardId', 'updatedAt', 'state'], array_keys($body['items'][0]));
        self::assertNull($body['next']);
    }

    public function test_full_makes_each_item_a_whole_card(): void
    {
        $this->createCard('Whole');

        $this->send(Request::METHOD_GET, $this->base().'?full=true');

        self::assertResponseStatusCodeSame(200);
        self::assertArrayHasKey('body', $this->json()['items'][0]);
        self::assertArrayHasKey('pullRequests', $this->json()['items'][0]);
    }

    public function test_a_cursor_walk_visits_every_card_once_in_each_order(): void
    {
        foreach (range(1, 5) as $i) {
            $this->createCard('Card '.$i);
        }

        self::assertSame([1, 2, 3, 4, 5], $this->walk($this->base().'?limit=2'));
        self::assertSame([5, 4, 3, 2, 1], $this->walk($this->base().'?limit=2&sort=-number'));
        self::assertEqualsCanonicalizing([1, 2, 3, 4, 5], $this->walk($this->base().'?limit=2&sort=updatedAt'));
        self::assertEqualsCanonicalizing([1, 2, 3, 4, 5], $this->walk($this->base().'?limit=2&sort=-updatedAt'));
        self::assertSame([1, 2, 3, 4, 5], $this->walk($this->base().'?limit=2&sort=createdAt'));
    }

    public function test_a_numeric_search_stays_inside_the_project(): void
    {
        $this->addCardsToAnotherProject();
        $this->createCard('Own card');
        $this->createCard('Release 7 notes');

        self::assertSame(['Release 7 notes'], $this->titles($this->base().'?q=7'));
    }

    /** Only a row with the same sort value as the cursor row reaches the tie-break, so every card shares one time. */
    public function test_an_update_time_walk_stays_inside_the_project(): void
    {
        $this->addCardsToAnotherProject();
        $this->createCard('First');
        $this->createCard('Second');
        $this->createCard('Third');
        $this->em->getConnection()->executeStatement('UPDATE board_cards SET updated_at = ?', ['2026-11-02 08:00:00']);

        self::assertSame([3, 2, 1], $this->walk($this->base().'?limit=1&sort=-updatedAt'));
        self::assertSame([1, 2, 3], $this->walk($this->base().'?limit=1&sort=updatedAt'));
    }

    /** Only a row with the same time as the cursor row reaches the tie-break, so every event shares one time. */
    public function test_a_history_walk_stays_inside_the_card(): void
    {
        $this->createCard('Walked');
        $this->createCard('Next door');
        foreach ([['1', 'done'], ['2', 'done'], ['1', 'backlog'], ['2', 'backlog']] as [$number, $status]) {
            $this->send(Request::METHOD_PATCH, $this->base().'/'.$number, json_encode(['status' => $status], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(200);
        }
        $this->em->getConnection()->executeStatement('UPDATE board_card_events SET occurred_at = ?', ['2026-11-02 08:00:00.000000']);

        $this->send(Request::METHOD_GET, $this->base().'/1/events?limit=200');
        self::assertResponseStatusCodeSame(200);
        $whole = $this->json()['items'];
        self::assertGreaterThanOrEqual(3, count($whole));

        $walked = [];
        $url = $this->base().'/1/events?limit=1';
        for ($guard = 0; null !== $url && $guard < 20; ++$guard) {
            $this->send(Request::METHOD_GET, $url);
            self::assertResponseStatusCodeSame(200);
            $page = $this->json();
            array_push($walked, ...$page['items']);
            $url = $page['next'];
        }

        self::assertSame($whole, $walked);
    }

    public function test_a_page_may_hold_two_hundred_rows_and_no_more(): void
    {
        $this->send(Request::METHOD_GET, $this->base().'?limit=200');
        self::assertResponseStatusCodeSame(200);

        $this->send(Request::METHOD_GET, $this->base().'?limit=201');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('limit_out_of_range', $this->json()['errors'][0]['code']);
    }

    public function test_a_cursor_from_other_filters_or_another_sort_is_refused(): void
    {
        foreach (range(1, 3) as $i) {
            $this->createCard('Card '.$i);
        }
        $this->send(Request::METHOD_GET, $this->base().'?limit=1&type=feature');
        $next = $this->json()['next'];
        self::assertIsString($next);

        $this->send(Request::METHOD_GET, str_replace('type=feature', 'type=epic', $next));
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_cursor', $this->json()['code']);

        $this->send(Request::METHOD_GET, $next.'&sort=-number');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_cursor', $this->json()['code']);
    }

    public function test_the_filters_combine_with_the_search(): void
    {
        $this->createCard('Alpha pagination');
        $this->createCard('Beta pagination', ['status' => 'done']);
        $this->createCard('Gamma other', ['reporter' => 'human', 'body' => 'about pagination too']);

        $titles = fn (string $query): array => $this->titles($this->base().'?'.$query);

        self::assertSame(['Alpha pagination', 'Beta pagination', 'Gamma other'], $titles('q=pagination'));
        self::assertSame(['Beta pagination'], $titles('q=pagination&status=done'));
        self::assertSame(['Alpha pagination', 'Beta pagination'], $titles('q=pagination&reporter=api-key'));
        self::assertSame(['Gamma other'], $titles('reporter=human'));
        self::assertSame(['Gamma other'], $titles('q=%233'));
        self::assertSame(['Alpha pagination', 'Beta pagination', 'Gamma other'], $titles('type=feature'));
        self::assertSame([], $titles('type=epic'));
        self::assertSame(['Alpha pagination', 'Beta pagination', 'Gamma other'], $titles('paused=false'));
        self::assertSame([], $titles('paused=true'));
    }

    public function test_the_children_of_a_card_are_filtered_by_its_id_or_its_number(): void
    {
        $epic = $this->createCard('The epic', ['type' => 'epic']);
        $this->createCard('Child', ['parentCardId' => $epic['cardId']]);
        $this->createCard('Loose');

        self::assertSame(['Child'], $this->titles($this->base().'?parentCardId='.$epic['cardId']));
        self::assertSame(['Child'], $this->titles($this->base().'?parent='.$epic['number']));

        $this->send(Request::METHOD_GET, $this->base().'?parent=999');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('parent_unknown', $this->json()['errors'][0]['code']);
    }

    public function test_a_bad_filter_value_answers_the_error_list(): void
    {
        $this->send(Request::METHOD_GET, $this->base().'?status=nowhere&type=nope&reporter=robot&sort=title');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->base().'?status=nowhere&type=nope&reporter=robot');
        self::assertResponseStatusCodeSame(422);
        self::assertEqualsCanonicalizing(['unknown_status', 'unknown_type', 'reporter_invalid'], array_column($this->json()['errors'], 'code'));

        $this->send(Request::METHOD_GET, $this->base().'?paused=maybe');
        self::assertResponseStatusCodeSame(422);
    }

    public function test_a_change_is_partial_and_keeps_what_it_leaves_out(): void
    {
        $this->createCard('Old title', ['body' => 'Keep me']);

        $this->send(Request::METHOD_PATCH, $this->base().'/1', '{"title":"New title","status":"next"}');

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame('New title', $body['title']);
        self::assertSame('Keep me', $body['body']);
        self::assertSame('next', $body['status']);
        self::assertSame('New title', $this->findCard(1)?->title);
    }

    public function test_an_empty_change_leaves_the_card_as_it_was(): void
    {
        $this->createCard('Same');

        $this->send(Request::METHOD_PATCH, $this->base().'/1', '{}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Same', $this->json()['title']);
    }

    public function test_a_change_that_is_refused_names_the_field_and_changes_nothing(): void
    {
        $this->createCard('Stable');

        foreach ([
            ['{"title":""}', 422, 'title_blank'],
            ['{"title":"  "}', 422, 'title_blank'],
            ['{"status":"nowhere"}', 422, 'unknown_status'],
            ['{"type":"nope"}', 422, 'unknown_type'],
            ['{"title":null}', 422, 'validation_failed'],
            ['{"values":{"no-such-key":true}}', 422, 'value_undeclared_key'],
            ['{"parentCardId":"0198f6e0-0000-7000-8000-000000000000"}', 422, 'parent_unknown'],
        ] as [$payload, $status, $code]) {
            $this->send(Request::METHOD_PATCH, $this->base().'/1', $payload);
            self::assertResponseStatusCodeSame($status, $payload);
            self::assertTrue(\in_array($code, [$this->json()['code'], ...array_column($this->json()['errors'] ?? [], 'code')], true), $payload.' answered '.json_encode($this->json()));
        }
        self::assertSame('Stable', $this->findCard(1)?->title);

        $this->send(Request::METHOD_PATCH, $this->base().'/9', '{"title":"x"}');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('card_not_found', $this->json()['code']);
    }

    public function test_a_parent_is_set_and_cleared(): void
    {
        $epic = $this->createCard('The epic', ['type' => 'epic']);
        $this->createCard('Child');

        $this->send(Request::METHOD_PATCH, $this->base().'/2', json_encode(['parentCardId' => $epic['cardId']], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        self::assertSame($epic['cardId'], $this->json()['parent']['cardId']);

        $this->send(Request::METHOD_PATCH, $this->base().'/2', '{"clearParent":true}');
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->json()['parent']);

        $this->send(Request::METHOD_PATCH, $this->base().'/2', json_encode(['parentCardId' => $epic['cardId']], \JSON_THROW_ON_ERROR));
        self::assertSame($epic['cardId'], $this->json()['parent']['cardId']);

        $this->send(Request::METHOD_PATCH, $this->base().'/2', '{"parentCardId":""}');
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->json()['parent']);
    }

    public function test_a_hold_makes_the_card_unmanaged_and_a_second_hold_conflicts(): void
    {
        $card = $this->createCard('Hold me');

        $this->send(Request::METHOD_PUT, $this->base().'/1/hold');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['cardId' => $card['cardId'], 'outcome' => 'held'], $this->json());

        $this->send(Request::METHOD_PUT, $this->base().'/1/hold');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('already_paused', $this->json()['code']);

        $this->send(Request::METHOD_DELETE, $this->base().'/1/hold');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['cardId' => $card['cardId'], 'outcome' => 'released'], $this->json());

        $this->send(Request::METHOD_DELETE, $this->base().'/1/hold');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('not_paused', $this->json()['code']);
    }

    public function test_a_hold_and_its_release_with_an_api_key_name_the_key_in_their_events(): void
    {
        $card = $this->createCard('Hold me');
        $key = static::getContainer()->get(ApiKeyRepository::class)->findOneBy(['owner' => $this->owner]);
        self::assertInstanceOf(ApiKey::class, $key);

        $this->send(Request::METHOD_PUT, $this->base().'/1/hold');
        self::assertResponseStatusCodeSame(200);
        $this->send(Request::METHOD_DELETE, $this->base().'/1/hold');
        self::assertResponseStatusCodeSame(200);

        foreach (['board.card_held', 'board.card_released'] as $type) {
            self::assertSame([[
                'type' => $type,
                'subject' => ['type' => 'card', 'id' => $card['cardId']],
                'projectId' => (string) $this->project->id,
                'actor' => 'api-key',
                'apiKey' => ['id' => (string) $key->id, 'name' => $key->name],
            ]], $this->outboxPayloads($type), $type);
        }
    }

    public function test_a_hold_and_its_release_with_a_cli_sign_in_name_the_agent_and_no_key(): void
    {
        $card = $this->createCard('Hold me');
        $token = AgentCredential::tokenFor(static::getContainer(), $this->owner, 'public');

        $this->send(Request::METHOD_PUT, $this->base().'/1/hold', token: $token);
        self::assertResponseStatusCodeSame(200);
        $this->send(Request::METHOD_DELETE, $this->base().'/1/hold', token: $token);
        self::assertResponseStatusCodeSame(200);

        foreach (['board.card_held', 'board.card_released'] as $type) {
            self::assertSame([[
                'type' => $type,
                'subject' => ['type' => 'card', 'id' => $card['cardId']],
                'projectId' => (string) $this->project->id,
                'actor' => 'agent',
            ]], $this->outboxPayloads($type), $type);
        }
    }

    public function test_a_hold_on_a_missing_card_is_not_found(): void
    {
        foreach ([Request::METHOD_PUT, Request::METHOD_DELETE] as $method) {
            $this->send($method, $this->base().'/7/hold');
            self::assertResponseStatusCodeSame(404);
            self::assertSame('card_not_found', $this->json()['code']);
        }
        $this->send(Request::METHOD_DELETE, $this->base().'/7/pause');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('card_not_found', $this->json()['code']);
    }

    public function test_a_pause_is_lifted_by_the_key_and_the_history_names_it(): void
    {
        $created = $this->createCard('Paused');
        $card = $this->findCard(1) ?? throw new \LogicException('The card exists.');
        $pause = new CardPause($card, $card->project, 'work-failed', 'rule-one', PauseKind::Retries, new \DateTimeImmutable());
        $this->em->persist($pause);
        $this->em->flush();

        $this->send(Request::METHOD_DELETE, $this->base().'/1/pause?pauseId=nonsense');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('pause_id_invalid', $this->json()['errors'][0]['code']);

        $this->send(Request::METHOD_DELETE, $this->base().'/1/pause?pauseId=0198f6e0-0000-7000-8000-000000000000');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('pause_changed', $this->json()['code']);

        $this->send(Request::METHOD_DELETE, $this->base().'/1/pause?pauseId='.$pause->id);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['cardId' => $created['cardId'], 'outcome' => 'released', 'kind' => 'retries', 'reason' => 'work-failed', 'ruleId' => 'rule-one'], $this->json());

        $this->send(Request::METHOD_DELETE, $this->base().'/1/pause');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('not_paused', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->base().'/1/events');
        $released = array_values(array_filter($this->json()['items'], static fn (array $event): bool => 'pause-released' === $event['kind']));
        self::assertCount(1, $released);
        self::assertSame('api-key', $released[0]['actor']['kind']);
        self::assertSame('Test key', $released[0]['actor']['apiKey']['name']);
    }

    private function base(): string
    {
        return '/api/v1/projects/'.$this->project->id.'/cards';
    }

    /** Cards of the same owner that a list of the project under test must never show. */
    private function addCardsToAnotherProject(): void
    {
        $other = new Project($this->owner, 'cards-other-shop');
        $this->em->persist($other);
        $this->seedColumns($other);
        $column = $this->column($other, 'backlog');
        foreach ([[7, 'Number match'], [901, 'Title 7'], [902, 'Other title']] as [$number, $title]) {
            $this->em->persist(new Card($other, $column, $title, 'Body 7', $number, 'feature'));
        }
        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function createCard(string $title, array $extra = []): array
    {
        $this->send(Request::METHOD_POST, $this->base(), json_encode($extra + ['title' => $title, 'body' => '', 'type' => 'feature'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        return $this->json();
    }

    private function findCard(int $number): ?Card
    {
        $this->em->clear();
        $cards = static::getContainer()->get(CardRepository::class);
        self::assertInstanceOf(CardRepository::class, $cards);

        $project = $this->em->find(Project::class, $this->project->id) ?? throw new \LogicException('The project exists.');

        return $cards->findOneByProjectAndNumber($project, $number);
    }

    /** @return list<int> */
    private function walk(string $url): array
    {
        $numbers = [];
        $guard = 0;
        do {
            $this->send(Request::METHOD_GET, $url);
            self::assertResponseStatusCodeSame(200);
            $page = $this->json();
            array_push($numbers, ...array_column($page['items'], 'number'));
            $url = $page['next'];
        } while (null !== $url && ++$guard < 10);

        return $numbers;
    }

    /** @return list<string> */
    private function titles(string $url): array
    {
        $this->send(Request::METHOD_GET, $url);
        self::assertResponseStatusCodeSame(200, $url);

        return array_column($this->json()['items'], 'title');
    }

    /** @return list<array<string, mixed>> */
    private function outboxPayloads(string $type): array
    {
        /** @var list<string> $payloads */
        $payloads = $this->em->getConnection()->fetchFirstColumn(
            'SELECT payload FROM outbox_events WHERE type = ? ORDER BY sequence',
            [$type],
        );

        return array_map(static fn (string $payload): array => json_decode($payload, true, flags: \JSON_THROW_ON_ERROR), $payloads);
    }

    /** @param array<string, string> $server */
    private function send(string $method, string $url, string $body = '', array $server = [], ?string $token = ''): void
    {
        $bearer = '' === $token ? $this->token : $token;
        $this->client->request($method, $url, server: $server + ['CONTENT_TYPE' => 'application/json'] + (null === $bearer ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer]), content: $body);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
