<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Api;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Messenger\NotifyInboxOwner;
use App\Module\Inbox\Messenger\NotifyInboxOwnerKind;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Repository\ApiKeyRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Module\Inbox\InboxScenario;
use App\Tests\Module\Inbox\QueuedPushes;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\ApiKeyCredential;
use App\Tests\Support\PublicSpec;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** The inbox routes of the public API under /api/v1/projects/{project}/inbox/items. */
final class InboxItemApiTest extends WebTestCase
{
    use BoardColumnFixtures;
    use InboxScenario;
    use QueuedPushes;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private Project $project;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->owner = $this->signedUpUser($this->em, 'inbox-api');
        $this->project = new Project($this->owner, 'inbox-api-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
        $this->token = ApiKeyCredential::mint(static::getContainer(), $this->owner);
        $this->setInboxFlag(true);
    }

    public function test_a_notice_is_created_with_a_location_and_the_key_as_author(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), ['kind' => 'notice', 'title' => 'The weekly mutation run failed', 'body' => 'See the run.']);

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertSame(1, $body['number']);
        self::assertSame('notice', $body['kind']);
        self::assertSame('api-key', $body['origin']);
        self::assertSame('Test key', $body['apiKey']['name']);
        self::assertSame((string) $this->keyId(), $body['apiKey']['id']);
        self::assertFalse($body['blocking']);
        self::assertTrue($body['dismissible'], 'nothing else closes a notice from a key');
        self::assertSame('open', $body['state']);
        self::assertStringEndsWith($this->base().'/'.$body['itemId'], (string) $this->client->getResponse()->headers->get('Location'));
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
        PublicSpec::assertMatches('InboxItem', $body);

        $item = $this->item($body['itemId']);
        self::assertSame(InboxAskOrigin::ApiKey, $item->origin);
        self::assertSame('Test key', $item->apiKeyName);
    }

    public function test_an_item_sits_in_a_closed_ask_with_no_session_and_pushes_to_the_owner(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), ['kind' => 'todo', 'title' => 'Rerun the weekly report']);

        self::assertResponseStatusCodeSame(201);
        $itemId = $this->json()['itemId'];
        self::assertEquals([new NotifyInboxOwner($itemId, NotifyInboxOwnerKind::Notice)], $this->queuedPushes());

        $asks = $this->asksOf($itemId);
        self::assertCount(1, $asks);
        self::assertNull($asks[0]->sessionId);
        self::assertNull($asks[0]->bridgeId);
        self::assertNotNull($asks[0]->closedAt, 'nothing blocks, so the ask closes at once');
    }

    public function test_a_question_never_blocks_and_its_card_shows_no_blocking_question(): void
    {
        $card = $this->card(1);
        $this->send(Request::METHOD_POST, $this->base(), ['kind' => 'question', 'title' => 'Ship the release?', 'options' => ['Yes', 'No'], 'cardIds' => [(string) $card->id]]);

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertFalse($body['blocking']);
        self::assertSame(['Yes', 'No'], $body['options']);
        self::assertSame([1], $body['cardNumbers']);

        $items = static::getContainer()->get(InboxItemRepository::class);
        self::assertInstanceOf(InboxItemRepository::class, $items);
        $rows = $items->findOpenAsksOfCards($this->project, [(string) $card->id]);
        self::assertCount(1, $rows);
        self::assertFalse($rows[0]['blocking'], 'a card waits only on a blocking question');
    }

    public function test_blocking_true_and_another_kind_are_refused(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), ['kind' => 'question', 'title' => 'Wait?', 'options' => ['Yes'], 'blocking' => true]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['blocking'], array_column($this->json()['errors'], 'field'));

        foreach (['review', 'wait', 'workflow'] as $kind) {
            $this->send(Request::METHOD_POST, $this->base(), ['kind' => $kind, 'title' => 'Look']);
            self::assertResponseStatusCodeSame(422, $kind);
            self::assertSame(['kind'], array_column($this->json()['errors'], 'field'), $kind);
        }

        $this->send(Request::METHOD_POST, $this->base(), ['kind' => 'question', 'title' => 'Fine?', 'options' => ['Yes'], 'blocking' => false]);
        self::assertResponseStatusCodeSame(201);
    }

    public function test_the_rules_of_an_agent_ask_refuse_each_field(): void
    {
        foreach ([
            [['kind' => 'todo', 'title' => '   '], 'title', 'title_blank'],
            [['kind' => 'todo', 'title' => "Two\nlines"], 'title', 'title_multiline'],
            [['kind' => 'todo', 'title' => 'Done?', 'options' => ['Yes']], 'options', 'todo_with_answer'],
            [['kind' => 'notice', 'title' => 'Read', 'freeText' => true], 'options', 'notice_with_answer'],
            [['kind' => 'question', 'title' => 'Which?'], 'options', 'question_without_answer'],
            [['kind' => 'question', 'title' => 'Which?', 'options' => ['A', 'A']], 'options', 'option_duplicate'],
            [['kind' => 'todo', 'title' => 'Look', 'cardIds' => [(string) Uuid::v7()]], 'cardIds', 'card_unknown'],
        ] as [$payload, $field, $code]) {
            $this->send(Request::METHOD_POST, $this->base(), $payload);

            self::assertResponseStatusCodeSame(422, $code);
            $errors = $this->json()['errors'];
            self::assertSame([['field' => $field, 'code' => $code]], array_map(static fn (array $e): array => ['field' => $e['field'], 'code' => $e['code']], $errors), $code);
            self::assertNotSame('', $errors[0]['message']);
        }
        self::assertSame(0, $this->countItems());
    }

    public function test_a_repeat_with_the_same_dedupe_key_returns_the_open_item_and_a_new_one_after_it_closes(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), ['kind' => 'notice', 'title' => 'Weekly run failed', 'dedupeKey' => ' weekly-ci ']);
        self::assertResponseStatusCodeSame(201);
        $first = $this->json()['itemId'];

        $this->send(Request::METHOD_POST, $this->base(), ['kind' => 'notice', 'title' => 'Weekly run failed again', 'dedupeKey' => 'weekly-ci']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($first, $this->json()['itemId']);
        self::assertSame('Weekly run failed', $this->json()['title']);
        self::assertSame([], $this->queuedPushes(), 'a repeat is no news');
        self::assertSame(1, $this->countItems());

        $this->send(Request::METHOD_POST, $this->base(), ['kind' => 'notice', 'title' => 'Other', 'dedupeKey' => 'nightly-ci']);
        self::assertResponseStatusCodeSame(201, 'another key makes another item');

        $this->em->getConnection()->executeStatement("UPDATE inbox_items SET state = 'declined', closed_at = NOW() WHERE id = ?", [$first]);

        $this->send(Request::METHOD_POST, $this->base(), ['kind' => 'notice', 'title' => 'Weekly run failed', 'dedupeKey' => 'weekly-ci']);
        self::assertResponseStatusCodeSame(201);
        self::assertNotSame($first, $this->json()['itemId']);
        self::assertSame(3, $this->json()['number']);
    }

    public function test_a_long_dedupe_key_is_refused(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), ['kind' => 'notice', 'title' => 'Long', 'dedupeKey' => str_repeat('k', 201)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['dedupeKey'], array_column($this->json()['errors'], 'field'));
    }

    public function test_a_retry_with_the_same_idempotency_key_creates_one_item(): void
    {
        $payload = ['kind' => 'todo', 'title' => 'Once'];
        $this->send(Request::METHOD_POST, $this->base(), $payload, ['HTTP_IDEMPOTENCY_KEY' => 'inbox-once']);
        self::assertResponseStatusCodeSame(201);
        $first = $this->json();

        $this->send(Request::METHOD_POST, $this->base(), $payload, ['HTTP_IDEMPOTENCY_KEY' => 'inbox-once']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($first, $this->json());
        self::assertSame(1, $this->countItems());
    }

    public function test_the_read_returns_the_answer_of_the_owner_and_the_key(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), ['kind' => 'question', 'title' => 'Ship it?', 'options' => ['Yes', 'No'], 'freeText' => true]);
        $itemId = $this->json()['itemId'];
        $this->em->getConnection()->executeStatement(
            "UPDATE inbox_items SET state = 'resolved', closed_by = 'owner', selected_options = '[1]', note = 'Not this week', closed_at = NOW() WHERE id = ?",
            [$itemId],
        );

        $this->send(Request::METHOD_GET, $this->base().'/'.$itemId);

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame('resolved', $body['state']);
        self::assertSame('owner', $body['closedBy']);
        self::assertSame([1], $body['selectedOptions']);
        self::assertSame('Not this week', $body['note']);
        self::assertSame('api-key', $body['origin']);
        self::assertSame('Test key', $body['apiKey']['name']);
        PublicSpec::assertMatches('InboxItem', $body);
    }

    public function test_the_key_name_survives_the_removal_of_the_key(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), ['kind' => 'todo', 'title' => 'Outlive the key']);
        $itemId = $this->json()['itemId'];
        $keyId = $this->keyId();
        $this->em->getConnection()->executeStatement('DELETE FROM api_keys WHERE id = ?', [(string) $keyId]);

        $this->send(Request::METHOD_GET, $this->base().'/'.$itemId, token: AgentCredential::tokenFor(static::getContainer(), $this->owner, 'public'));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['id' => (string) $keyId, 'name' => 'Test key'], $this->json()['apiKey']);
    }

    public function test_an_item_of_another_project_and_an_agent_item_without_a_key_read_as_expected(): void
    {
        $other = new Project($this->owner, 'inbox-api-other-'.uniqid());
        $this->em->persist($other);
        $foreign = new InboxItem(project: $other, number: 1, kind: InboxItemKind::Todo, title: 'Elsewhere', blocking: false, origin: InboxAskOrigin::Agent);
        $this->em->persist($foreign);
        $own = new InboxItem(project: $this->project, number: 1, kind: InboxItemKind::Todo, title: 'Mine', blocking: false, origin: InboxAskOrigin::Agent);
        $this->em->persist($own);
        $this->em->flush();

        $this->send(Request::METHOD_GET, $this->base().'/'.$foreign->id);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('inbox_item_not_found', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->base().'/'.Uuid::v7());
        self::assertResponseStatusCodeSame(404);
        self::assertSame('inbox_item_not_found', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->base().'/'.$own->id);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('agent', $this->json()['origin']);
        self::assertNull($this->json()['apiKey']);
    }

    public function test_a_cli_sign_in_creates_an_agent_item_with_no_key(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), ['kind' => 'todo', 'title' => 'From the terminal'], token: AgentCredential::tokenFor(static::getContainer(), $this->owner, 'public'));

        self::assertResponseStatusCodeSame(201);
        self::assertSame('agent', $this->json()['origin']);
        self::assertNull($this->json()['apiKey']);
    }

    public function test_both_routes_are_absent_while_the_inbox_is_switched_off(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), ['kind' => 'todo', 'title' => 'Before']);
        $itemId = $this->json()['itemId'];
        $this->setInboxFlag(false);

        $this->send(Request::METHOD_POST, $this->base(), ['kind' => 'todo', 'title' => 'Off']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('route_not_found', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->base().'/'.$itemId);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('route_not_found', $this->json()['code']);
        self::assertSame(1, $this->countItems());
    }

    public function test_a_request_without_a_credential_is_refused(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), ['kind' => 'todo', 'title' => 'Anonymous'], token: null);
        self::assertResponseStatusCodeSame(401);

        $this->send(Request::METHOD_GET, $this->base().'/'.Uuid::v7(), token: null);
        self::assertResponseStatusCodeSame(401);
    }

    public function test_a_project_of_another_owner_is_not_found(): void
    {
        $stranger = $this->signedUpUser($this->em, 'inbox-api-stranger');

        $this->send(Request::METHOD_POST, $this->base(), ['kind' => 'todo', 'title' => 'Intrude'], token: ApiKeyCredential::mint(static::getContainer(), $stranger));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);
        self::assertSame(0, $this->countItems());
    }

    private function base(): string
    {
        return '/api/v1/projects/'.$this->project->id.'/inbox/items';
    }

    private function card(int $number): Card
    {
        $card = new Card($this->project, $this->column($this->project, 'backlog'), 'Release', 'Body', $number, 'feature');
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }

    private function keyId(): Uuid
    {
        $keys = static::getContainer()->get(ApiKeyRepository::class);
        self::assertInstanceOf(ApiKeyRepository::class, $keys);
        $key = $keys->findOneBy(['owner' => $this->owner]);
        self::assertInstanceOf(ApiKey::class, $key);

        return $key->id ?? throw new \LogicException('A stored key has an id.');
    }

    private function item(string $itemId): InboxItem
    {
        $this->em->clear();
        $item = $this->em->find(InboxItem::class, $itemId);
        self::assertInstanceOf(InboxItem::class, $item);
        self::assertSame(InboxItemState::Open, $item->state);

        return $item;
    }

    /** @return list<InboxAsk> */
    private function asksOf(string $itemId): array
    {
        $this->em->clear();

        return array_values(array_map(
            static fn (InboxAskItem $link): InboxAsk => $link->ask,
            $this->em->getRepository(InboxAskItem::class)->findBy(['item' => $itemId]),
        ));
    }

    private function countItems(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_items WHERE project_id = ?', [(string) $this->project->id]);
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $server
     */
    private function send(string $method, string $url, array $payload = [], array $server = [], ?string $token = ''): void
    {
        $bearer = '' === $token ? $this->token : $token;
        $this->client->request(
            $method,
            $url,
            server: $server + ['CONTENT_TYPE' => 'application/json'] + (null === $bearer ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer]),
            content: Request::METHOD_GET === $method ? '' : json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
