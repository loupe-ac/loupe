<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\OAuth\Command\MintPreviewTokenCommand;
use App\Module\OAuth\Command\MintPreviewTokenHandler;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\OAuthScenario;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class WidgetCardStartApiTest extends WebTestCase
{
    use WorkflowProjects;

    private KernelBrowser $client;
    private Project $project;
    private User $owner;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $scenario = new OAuthScenario(static::getContainer());
        $scenario->createClient();
        $this->owner = $scenario->createUser('widget-start-'.uniqid().'@example.com');
        $this->project = $this->projectWithColumns($scenario, 'widget-start');
        $this->bindLifecycle($this->project);
        $this->token = $scenario->accessTokenFor($this->client, $this->owner, 'site-review', $this->project);
        $this->owner = AgentCredential::managed($this->em(), $this->owner, $this->owner->id);
        $this->project = AgentCredential::managed($this->em(), $this->project, $this->project->id);
    }

    public function test_a_card_in_the_backlog_lists_the_columns_the_reviewer_may_start_it_in(): void
    {
        $card = $this->card('backlog');

        $data = $this->show($card);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($card->number, $data['number']);
        self::assertStringContainsString((string) $card->id, $data['url']);
        self::assertFalse($data['startsWork']);
        self::assertSame(['next', 'product-design', 'tech-design'], $this->slugsOf($data['moves']));
        $byId = array_column($data['moves'], 'hint', 'id');
        self::assertSame('Waits until you start it.', $byId[(string) $this->column($this->project, 'next')->id]);
        self::assertSame('An agent writes the tech design.', $byId[(string) $this->column($this->project, 'tech-design')->id]);
    }

    public function test_a_card_in_next_offers_the_design_columns_and_starts_no_work(): void
    {
        $data = $this->show($this->card('next'));

        self::assertFalse($data['startsWork']);
        self::assertSame(['product-design', 'tech-design'], $this->slugsOf($data['moves']));
    }

    public function test_a_card_in_product_design_has_no_move_and_starts_work(): void
    {
        $data = $this->show($this->card('product-design'));

        self::assertTrue($data['startsWork']);
        self::assertSame([], $data['moves']);
    }

    public function test_a_card_in_implementation_may_go_back_to_the_tech_design_only(): void
    {
        $data = $this->show($this->card('in-progress'));

        self::assertTrue($data['startsWork']);
        self::assertSame(['tech-design'], $this->slugsOf($data['moves']));
    }

    public function test_a_card_the_widget_did_not_raise_is_not_found(): void
    {
        $card = $this->card('backlog', Actor::Agent);

        $this->show($card);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('not_found', $this->body()['code']);

        $this->start($card, $this->column($this->project, 'next'));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('backlog', $this->storedSlug($card));
    }

    public function test_a_card_of_another_project_is_not_found(): void
    {
        $scenario = new OAuthScenario(static::getContainer());
        $other = $this->projectWithColumns($scenario, 'widget-start-other');
        $foreign = new Card($other, $this->column($other, 'backlog'), 'Foreign', '', 1, origin: Actor::Reviewer);
        $this->em()->persist($foreign);
        $this->em()->flush();

        $this->show($foreign);
        self::assertResponseStatusCodeSame(404);

        $this->start($foreign, $this->column($other, 'next'));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('backlog', $this->storedSlug($foreign));
    }

    public function test_a_card_id_that_is_no_uuid_is_not_found(): void
    {
        $this->request(Request::METHOD_GET, '/api/v1/live-review-widget/cards/nope/start', $this->token);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('not_found', $this->body()['code']);
    }

    public function test_a_preview_token_may_neither_read_nor_start(): void
    {
        $card = $this->card('backlog');
        $mint = static::getContainer()->get(MintPreviewTokenHandler::class);
        self::assertInstanceOf(MintPreviewTokenHandler::class, $mint);
        $preview = $mint(new MintPreviewTokenCommand($this->owner, (string) $this->project->id, 'pr-start', null))->token;

        $this->request(Request::METHOD_GET, $this->path($card), $preview);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('start_not_allowed', $this->body()['code']);

        $this->request(Request::METHOD_POST, $this->path($card), $preview, ['columnId' => (string) $this->column($this->project, 'next')->id]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('start_not_allowed', $this->body()['code']);
        self::assertSame('backlog', $this->storedSlug($card));
    }

    public function test_a_request_without_a_credential_is_refused(): void
    {
        $card = $this->card('backlog');

        $this->client->request(Request::METHOD_POST, $this->path($card), server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        self::assertResponseStatusCodeSame(401);
        self::assertSame('backlog', $this->storedSlug($card));
    }

    public function test_an_agent_token_is_refused(): void
    {
        $card = $this->card('backlog');
        $agent = AgentCredential::agentToken(static::getContainer(), $this->owner);

        $this->request(Request::METHOD_POST, $this->path($card), $agent, ['columnId' => (string) $this->column($this->project, 'next')->id]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('credential_not_allowed', $this->body()['code']);
        self::assertSame('backlog', $this->storedSlug($card));
    }

    public function test_a_start_moves_the_card_and_records_the_reviewer(): void
    {
        $card = $this->card('backlog');
        $column = $this->column($this->project, 'product-design');

        $data = $this->start($card, $column);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($this->translated($column), $data['column']);
        self::assertSame('product-design', $this->storedSlug($card));
        $moved = array_values(array_filter(
            static::getContainer()->get(CardEventRepository::class)->findForCard($card),
            static fn ($event): bool => CardEventKind::Moved === $event->kind,
        ));
        self::assertCount(1, $moved);
        self::assertSame(Actor::Reviewer, $moved[0]->actorKind);
    }

    public function test_a_start_in_the_backlog_is_refused_with_the_moves_the_card_has(): void
    {
        $card = $this->card('next');

        $data = $this->start($card, $this->column($this->project, 'backlog'));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('start_refused', $data['code']);
        self::assertSame($this->translated($this->column($this->project, 'next')), $data['column']);
        self::assertSame(['product-design', 'tech-design'], $this->slugsOf($data['moves']));
        self::assertSame('next', $this->storedSlug($card));
    }

    public function test_a_start_in_a_terminal_column_is_refused(): void
    {
        $card = $this->card('next');

        $data = $this->start($card, $this->column($this->project, 'done'));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('start_refused', $data['code']);
        self::assertSame('next', $this->storedSlug($card));
    }

    public function test_a_start_in_a_column_the_guard_does_not_offer_is_refused(): void
    {
        $card = $this->card('backlog');

        $data = $this->start($card, $this->column($this->project, 'in-progress'));

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['next', 'product-design', 'tech-design'], $this->slugsOf($data['moves']));
        self::assertSame('backlog', $this->storedSlug($card));
    }

    public function test_a_start_in_the_column_the_card_is_in_is_refused(): void
    {
        $card = $this->card('next');

        $data = $this->start($card, $this->column($this->project, 'next'));

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['product-design', 'tech-design'], $this->slugsOf($data['moves']));
    }

    public function test_a_start_in_a_column_of_another_project_is_refused(): void
    {
        $card = $this->card('backlog');
        $scenario = new OAuthScenario(static::getContainer());
        $other = $this->projectWithColumns($scenario, 'widget-start-elsewhere');

        $this->start($card, $this->column($other, 'next'));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('backlog', $this->storedSlug($card));
    }

    public function test_a_start_after_the_card_moved_is_refused_with_the_fresh_moves(): void
    {
        $card = $this->card('backlog');
        $techDesign = $this->column($this->project, 'tech-design');
        $this->show($card);
        self::assertContains('tech-design', $this->slugsOf($this->body()['moves']));
        $this->em()->getConnection()->executeStatement('UPDATE board_cards SET column_id = :column WHERE id = :id', [
            'column' => (string) $this->column($this->project, 'product-design')->id,
            'id' => (string) $card->id,
        ]);

        $data = $this->start($card, $techDesign);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('start_refused', $data['code']);
        self::assertSame($this->translated($this->column($this->project, 'product-design')), $data['column']);
        self::assertSame([], $data['moves']);
        self::assertSame('product-design', $this->storedSlug($card));
    }

    public function test_a_start_with_no_column_fails_validation(): void
    {
        $card = $this->card('backlog');

        $this->request(Request::METHOD_POST, $this->path($card), $this->token, []);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('backlog', $this->storedSlug($card));
    }

    private function projectWithColumns(OAuthScenario $scenario, string $name): Project
    {
        $project = $scenario->createProject($this->owner, $name.'-'.uniqid());
        $this->seedColumns($project);
        foreach (['product-design', 'tech-design', 'in-review'] as $position => $slug) {
            $column = new BoardColumn(project: $project, label: $slug, slug: $slug, position: 10 + $position);
            $this->em()->persist($column);
            $this->seededColumns[$project->id.'/'.$slug] = $column;
        }
        $this->em()->flush();

        return $project;
    }

    private function card(string $slug, Actor $origin = Actor::Reviewer): Card
    {
        $number = 1 + (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM board_cards WHERE project_id = :id', ['id' => (string) $this->project->id]);
        $card = new Card($this->project, $this->column($this->project, $slug), 'Note card', '', $number, origin: $origin);
        $this->em()->persist($card);
        $this->em()->flush();

        return $card;
    }

    private function translated(BoardColumn $column): string
    {
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return $translator->trans($column->label);
    }

    private function storedSlug(Card $card): string
    {
        return (string) $this->em()->getConnection()->fetchOne(
            'SELECT c.slug FROM board_cards k JOIN board_columns c ON c.id = k.column_id WHERE k.id = :id',
            ['id' => (string) $card->id],
        );
    }

    /**
     * @param list<array{id: string}> $moves
     *
     * @return list<string>
     */
    private function slugsOf(array $moves): array
    {
        $slugs = [];
        foreach ($moves as $move) {
            $slugs[] = (string) $this->em()->getConnection()->fetchOne('SELECT slug FROM board_columns WHERE id = :id', ['id' => $move['id']]);
        }

        return $slugs;
    }

    private function path(Card $card): string
    {
        return '/api/v1/live-review-widget/cards/'.$card->id.'/start';
    }

    /** @return array<string, mixed> */
    private function show(Card $card): array
    {
        $this->request(Request::METHOD_GET, $this->path($card), $this->token);

        return $this->body();
    }

    /** @return array<string, mixed> */
    private function start(Card $card, BoardColumn $column): array
    {
        $this->request(Request::METHOD_POST, $this->path($card), $this->token, ['columnId' => (string) $column->id]);

        return $this->body();
    }

    /** @param array<string, mixed>|null $json */
    private function request(string $method, string $path, string $token, ?array $json = null): void
    {
        $this->client->request($method, $path,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://app.localhost'],
            content: null === $json ? null : json_encode($json, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($data) ? $data : [];
    }
}
