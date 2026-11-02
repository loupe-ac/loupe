<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Api;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\ApiKeyCredential;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** The column routes of the public API under /api/v1/projects/{project}/board/columns. */
final class BoardColumnApiTest extends WebTestCase
{
    use BoardColumnFixtures;

    private KernelBrowser $client;
    private OAuthScenario $scenario;
    private EntityManagerInterface $em;
    private User $owner;
    private Project $project;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->scenario = new OAuthScenario(static::getContainer());
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->owner = $this->scenario->createUser('columns-api-'.uniqid().'@example.com');
        $this->project = $this->makeProject($this->owner, 'columns-shop');
        $this->token = ApiKeyCredential::mint(static::getContainer(), $this->owner);
    }

    public function test_the_list_holds_the_columns_in_board_order_with_the_card_types(): void
    {
        $this->send(Request::METHOD_GET, $this->base());

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame(['backlog', 'next', 'in-progress', 'done'], array_column($body['items'], 'slug'));
        self::assertNull($body['next']);
        self::assertSame(['slug', 'label', 'terminal', 'default', 'backlog'], array_keys($body['items'][0]));
        self::assertTrue($body['items'][0]['backlog']);
        self::assertTrue($body['items'][0]['default']);
        self::assertFalse($body['items'][1]['terminal']);
        self::assertTrue($body['items'][3]['terminal']);
        self::assertStringNotContainsString('board.card.status', $body['items'][1]['label']);
        self::assertNotEmpty($body['types']);
        self::assertSame(['key', 'label', 'children', 'lane'], array_keys($body['types'][0]));
        self::assertContains($body['defaultType'], array_column($body['types'], 'key'));
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
    }

    public function test_a_page_follows_the_board_order_and_the_walk_ends(): void
    {
        $this->send(Request::METHOD_GET, $this->base().'?limit=3');
        self::assertResponseStatusCodeSame(200);
        $first = $this->json();
        self::assertSame(['backlog', 'next', 'in-progress'], array_column($first['items'], 'slug'));
        self::assertIsString($first['next']);
        self::assertStringContainsString('rel="next"', (string) $this->client->getResponse()->headers->get('Link'));
        self::assertNotEmpty($first['types']);

        $this->send(Request::METHOD_GET, $first['next']);
        self::assertResponseStatusCodeSame(200);
        $second = $this->json();
        self::assertSame(['done'], array_column($second['items'], 'slug'));
        self::assertNull($second['next']);
    }

    public function test_a_broken_cursor_and_a_limit_out_of_range_are_refused(): void
    {
        $this->send(Request::METHOD_GET, $this->base().'?cursor=nonsense');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_cursor', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->base().'?limit=0');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->json()['code']);
    }

    public function test_one_column_is_read_with_the_whole_board(): void
    {
        $this->send(Request::METHOD_GET, $this->base().'/next');

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame('next', $body['column']);
        self::assertSame(['backlog', 'next', 'in-progress', 'done'], array_column($body['columns'], 'slug'));
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
    }

    public function test_a_missing_column_is_column_not_found(): void
    {
        $this->send(Request::METHOD_GET, $this->base().'/nowhere');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('column_not_found', $this->json()['code']);
    }

    public function test_a_project_is_found_by_its_slug_as_well(): void
    {
        $this->send(Request::METHOD_GET, '/api/v1/projects/'.$this->project->slug.'/board/columns/done');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('done', $this->json()['column']);
    }

    public function test_a_column_is_added_last_and_not_terminal(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), '{"label":"Code review"}');

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertSame('code-review', $body['column']);
        self::assertSame(['backlog', 'next', 'in-progress', 'done', 'code-review'], array_column($body['columns'], 'slug'));
        self::assertFalse($body['columns'][4]['terminal']);
        self::assertSame('Code review', $body['columns'][4]['label']);
        self::assertStringEndsWith($this->base().'/code-review', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
        self::assertNotNull($this->findColumn('code-review'));
    }

    public function test_a_label_that_gives_a_taken_slug_is_column_slug_taken(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), '{"label":"Done"}');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('column_slug_taken', $this->json()['code']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function badLabels(): iterable
    {
        yield 'no label' => ['{}', 'label', 'label_blank'];
        yield 'a null label' => ['{"label":null}', 'label', 'label_blank'];
        yield 'blank' => ['{"label":"   "}', 'label', 'label_blank'];
        yield 'too long' => ['{"label":"'.str_repeat('a', 101).'"}', 'label', 'label_length'];
        yield 'no letter or digit' => ['{"label":"!!!"}', 'label', 'label_invalid'];
    }

    #[DataProvider('badLabels')]
    public function test_a_label_the_board_refuses_is_a_validation_problem(string $body, string $field, string $code): void
    {
        $this->send(Request::METHOD_POST, $this->base(), $body);

        self::assertResponseStatusCodeSame(422);
        $problem = $this->json();
        self::assertSame('validation_failed', $problem['code']);
        self::assertSame($field, $problem['errors'][0]['field']);
        self::assertSame($code, $problem['errors'][0]['code']);
        self::assertCount(4, $this->columns());
    }

    public function test_a_repeated_idempotency_key_adds_one_column(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), '{"label":"Review"}', ['HTTP_IDEMPOTENCY_KEY' => 'add-review-1']);
        $first = $this->json();
        $this->send(Request::METHOD_POST, $this->base(), '{"label":"Review"}', ['HTTP_IDEMPOTENCY_KEY' => 'add-review-1']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($first, $this->json());
        self::assertCount(5, $this->columns());
    }

    public function test_an_explicit_null_is_refused_on_a_column_patch_and_a_reorder(): void
    {
        foreach (['label', 'terminal'] as $field) {
            $this->send(Request::METHOD_PATCH, $this->base().'/next', '{"'.$field.'":null}');
            self::assertResponseStatusCodeSame(422, $field);
            self::assertSame('validation_failed', $this->json()['code'], $field);
            self::assertSame($field, $this->json()['errors'][0]['field'], $field);
        }

        $this->send(Request::METHOD_PUT, $this->base().'/order', '{"order":null}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->json()['code']);
        self::assertSame('order', $this->json()['errors'][0]['field']);
    }

    public function test_a_rename_changes_the_slug_and_keeps_the_terminal_flag(): void
    {
        $this->send(Request::METHOD_PATCH, $this->base().'/next', '{"label":"Up next"}');

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame('up-next', $body['column']);
        self::assertSame(['backlog', 'up-next', 'in-progress', 'done'], array_column($body['columns'], 'slug'));
        self::assertSame('Up next', $body['columns'][1]['label']);
        self::assertFalse($body['columns'][1]['terminal']);
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
        self::assertNull($this->findColumn('next'));
    }

    public function test_the_terminal_flag_changes_alone(): void
    {
        $this->send(Request::METHOD_PATCH, $this->base().'/next', '{"terminal":true}');

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame('next', $body['column']);
        self::assertTrue($body['columns'][1]['terminal']);
        self::assertTrue($this->findColumn('next')?->terminal);
    }

    public function test_an_empty_change_keeps_the_column_as_it_is(): void
    {
        $this->send(Request::METHOD_PATCH, $this->base().'/next', '{}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('next', $this->json()['column']);
    }

    public function test_a_rename_to_a_taken_slug_is_column_slug_taken(): void
    {
        $this->send(Request::METHOD_PATCH, $this->base().'/next', '{"label":"Done"}');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('column_slug_taken', $this->json()['code']);
        self::assertNotNull($this->findColumn('next'));
    }

    public function test_the_only_terminal_column_cannot_stop_being_terminal(): void
    {
        $this->send(Request::METHOD_PATCH, $this->base().'/done', '{"terminal":false}');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('no_terminal_column', $this->json()['code']);
        self::assertTrue($this->findColumn('done')?->terminal);
    }

    public function test_backlog_cannot_be_changed(): void
    {
        $this->send(Request::METHOD_PATCH, $this->base().'/backlog', '{"label":"Ideas"}');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('backlog_locked', $this->json()['code']);
    }

    public function test_a_change_to_a_missing_column_or_with_a_blank_label_is_refused(): void
    {
        $this->send(Request::METHOD_PATCH, $this->base().'/nowhere', '{"label":"x"}');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('column_not_found', $this->json()['code']);

        $this->send(Request::METHOD_PATCH, $this->base().'/next', '{"label":""}');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('label_length', $this->json()['errors'][0]['code']);
    }

    public function test_the_order_is_saved(): void
    {
        $this->send(Request::METHOD_PUT, $this->base().'/order', '{"order":["done","in-progress","next"]}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['backlog', 'done', 'in-progress', 'next'], array_column($this->json()['columns'], 'slug'));
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
        self::assertSame(['backlog', 'done', 'in-progress', 'next'], array_map(static fn (BoardColumn $column): string => $column->slug, $this->columns()));
    }

    /** @return iterable<string, array{string, string}> */
    public static function badOrders(): iterable
    {
        yield 'with backlog' => ['{"order":["backlog","next","in-progress","done"]}', 'backlog_in_order'];
        yield 'unknown slug' => ['{"order":["next","in-progress","done","nowhere"]}', 'unknown_column'];
        yield 'a column left out' => ['{"order":["next","done"]}', 'order_incomplete'];
        yield 'a column twice' => ['{"order":["next","next","done"]}', 'order_incomplete'];
        yield 'an entry that is no slug' => ['{"order":["next",3,"done"]}', 'unknown_column'];
    }

    #[DataProvider('badOrders')]
    public function test_a_bad_order_is_a_validation_problem_with_a_code_per_error(string $body, string $code): void
    {
        $this->send(Request::METHOD_PUT, $this->base().'/order', $body);

        self::assertResponseStatusCodeSame(422);
        $problem = $this->json();
        self::assertSame('validation_failed', $problem['code']);
        self::assertSame('order', $problem['errors'][0]['field']);
        self::assertSame($code, $problem['errors'][0]['code']);
        self::assertSame(['backlog', 'next', 'in-progress', 'done'], array_map(static fn (BoardColumn $column): string => $column->slug, $this->columns()));
    }

    public function test_an_order_with_no_list_is_refused(): void
    {
        $this->send(Request::METHOD_PUT, $this->base().'/order', '{}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('order_not_null', $this->json()['errors'][0]['code']);
    }

    public function test_an_empty_column_is_deleted_with_no_target(): void
    {
        $this->send(Request::METHOD_DELETE, $this->base().'/next');

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame('next', $body['deleted']);
        self::assertSame(0, $body['movedCards']);
        self::assertNull($body['targetColumn']);
        self::assertSame(['backlog', 'in-progress', 'done'], array_column($body['columns'], 'slug'));
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
        self::assertNull($this->findColumn('next'));
    }

    public function test_the_cards_of_a_deleted_column_move_to_the_target(): void
    {
        $card = $this->card('next');

        $this->send(Request::METHOD_DELETE, $this->base().'/next?targetColumn=done');

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame(1, $body['movedCards']);
        self::assertSame('done', $body['targetColumn']);
        $this->em->clear();
        $moved = static::getContainer()->get(CardRepository::class)->find($card->id);
        self::assertInstanceOf(Card::class, $moved);
        self::assertSame('done', $moved->column->slug);
        self::assertNotNull($moved->completedAt);
    }

    public function test_a_column_that_holds_cards_needs_a_target(): void
    {
        $this->card('next');

        $this->send(Request::METHOD_DELETE, $this->base().'/next');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('column_target_required', $this->json()['code']);
        self::assertNotNull($this->findColumn('next'));
    }

    public function test_a_target_that_is_unknown_or_the_column_itself_is_a_validation_problem(): void
    {
        $this->card('next');

        $this->send(Request::METHOD_DELETE, $this->base().'/next?targetColumn=nowhere');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('targetColumn', $this->json()['errors'][0]['field']);
        self::assertSame('target_column_unknown', $this->json()['errors'][0]['code']);

        $this->send(Request::METHOD_DELETE, $this->base().'/next?targetColumn=next');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('target_column_invalid', $this->json()['errors'][0]['code']);
        self::assertNotNull($this->findColumn('next'));
    }

    public function test_backlog_and_the_only_terminal_column_cannot_be_deleted(): void
    {
        $this->send(Request::METHOD_DELETE, $this->base().'/backlog');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('backlog_locked', $this->json()['code']);

        $this->send(Request::METHOD_DELETE, $this->base().'/done');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('no_terminal_column', $this->json()['code']);
        self::assertNotNull($this->findColumn('done'));
    }

    public function test_a_missing_column_cannot_be_deleted(): void
    {
        $this->send(Request::METHOD_DELETE, $this->base().'/nowhere');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('column_not_found', $this->json()['code']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function routes(): iterable
    {
        yield 'list' => [Request::METHOD_GET, '', ''];
        yield 'create' => [Request::METHOD_POST, '', '{"label":"Review"}'];
        yield 'reorder' => [Request::METHOD_PUT, '/order', '{"order":["next","in-progress","done"]}'];
        yield 'show' => [Request::METHOD_GET, '/next', ''];
        yield 'update' => [Request::METHOD_PATCH, '/next', '{"label":"Soon"}'];
        yield 'delete' => [Request::METHOD_DELETE, '/next', ''];
    }

    #[DataProvider('routes')]
    public function test_a_project_of_another_owner_reads_as_a_missing_project_and_stays_untouched(string $method, string $suffix, string $body): void
    {
        $other = $this->scenario->createUser('columns-other-'.uniqid().'@example.com');
        $otherProject = $this->makeProject($other, 'their-board');

        $this->send($method, '/api/v1/projects/'.$otherProject->id.'/board/columns'.$suffix, $body);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);
        self::assertSame(['backlog', 'next', 'in-progress', 'done'], array_map(static fn (BoardColumn $column): string => $column->slug, $this->columns($otherProject)));
    }

    #[DataProvider('routes')]
    public function test_a_request_with_no_credential_is_refused(string $method, string $suffix, string $body): void
    {
        $this->send($method, $this->base().$suffix, $body, token: null);

        self::assertResponseStatusCodeSame(401);
        self::assertSame('invalid_credentials', $this->json()['code']);
    }

    #[DataProvider('routes')]
    public function test_a_credential_without_the_public_scope_is_refused(string $method, string $suffix, string $body): void
    {
        $this->send($method, $this->base().$suffix, $body, token: AgentCredential::tokenFor(static::getContainer(), $this->owner, 'agent mcp'));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('credential_not_allowed', $this->json()['code']);
    }

    #[DataProvider('routes')]
    public function test_a_credential_bound_to_another_project_reaches_nothing_here(string $method, string $suffix, string $body): void
    {
        $bound = $this->makeProject($this->owner, 'bound-board');

        $this->send($method, $this->base().$suffix, $body, token: AgentCredential::tokenFor(static::getContainer(), $this->owner, 'mcp public', $bound));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);
    }

    public function test_a_credential_bound_to_this_project_reaches_it(): void
    {
        $this->send(Request::METHOD_GET, $this->base(), token: AgentCredential::tokenFor(static::getContainer(), $this->owner, 'mcp public', $this->project));

        self::assertResponseStatusCodeSame(200);
    }

    public function test_a_wrong_method_on_the_order_route_answers_405(): void
    {
        $this->send(Request::METHOD_POST, $this->base().'/order', '{"label":"x"}');

        self::assertResponseStatusCodeSame(405);
        self::assertSame('method_not_allowed', $this->json()['code']);
    }

    private function base(): string
    {
        return '/api/v1/projects/'.$this->project->id.'/board/columns';
    }

    private function makeProject(User $owner, string $name): Project
    {
        $project = new Project($owner, $name);
        $this->em->persist($project);
        $this->seedColumns($project);
        $this->em->flush();

        return $project;
    }

    private function card(string $slug): Card
    {
        $card = new Card($this->project, $this->column($this->project, $slug), 'Ship it', '', 1);
        $this->em->persist($card);
        $this->em->flush();
        $this->em->clear();

        return $card;
    }

    private function findColumn(string $slug): ?BoardColumn
    {
        $this->em->clear();

        return static::getContainer()->get(BoardColumnRepository::class)->findOneBy(['project' => $this->project->id, 'slug' => $slug]);
    }

    /** @return list<BoardColumn> */
    private function columns(?Project $project = null): array
    {
        $this->em->clear();

        return static::getContainer()->get(BoardColumnRepository::class)->findBy(['project' => ($project ?? $this->project)->id], ['position' => 'ASC']);
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
