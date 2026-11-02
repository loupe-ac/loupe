<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Api\Fields\FieldSelection;
use App\Module\Board\Controller\Api\CardResponse;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\CardPayload;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/** What a page of rows reads from the database, for each way a request can shape its fields. */
final class CardPayloadFieldsTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;
    use WorkflowProjects;

    private EntityManagerInterface $em;
    private CardPayload $payload;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $payload = self::getContainer()->get(CardPayload::class);
        self::assertInstanceOf(CardPayload::class, $payload);
        $this->payload = $payload;

        $this->project = $this->workflowProject('card-payload-fields');
        $this->bindLifecycle($this->project);
        $this->actAsMcpTokenBoundTo($this->project);
        $create = self::getContainer()->get(CardCreateTool::class);
        self::assertInstanceOf(CardCreateTool::class, $create);
        for ($i = 0; $i < 6; ++$i) {
            $create('Card '.$i, 'Body '.$i, 'feature');
        }
        $repository = self::getContainer()->get(CardRepository::class);
        self::assertInstanceOf(CardRepository::class, $repository);
        foreach ($repository->findBy(['project' => $this->project]) as $card) {
            $this->em->persist(new CardPullRequest($card, 'https://github.com/loupe-ac/loupe/pull/'.$card->number, Forge::GitHub, 'loupe-ac/loupe', $card->number));
            $this->feedbackNote($this->project, $card, $card->number, screenshot: false);
        }
        $this->em->flush();
    }

    public function test_two_plain_fields_read_no_linked_set(): void
    {
        $small = $this->read('number,title', 3);
        $big = $this->read('number,title', 6);

        self::assertCount(3, $small['rows']);
        self::assertCount(6, $big['rows']);
        self::assertSame(['number', 'title'], array_keys($big['rows'][0]));
        self::assertSame(\count($small['sql']), \count($big['sql']));
        foreach (['board_card_pull_requests', 'board_card_site_review_comments', 'board_card_links', 'board_card_values', 'board_card_documents', 'card_pauses', 'board_card_artifacts'] as $table) {
            self::assertSame([], $this->reading($big['sql'], $table), $table);
        }
    }

    public function test_pull_requests_read_the_links_once_whatever_the_card_count(): void
    {
        $small = $this->read('pullRequests', 3);
        $big = $this->read('pullRequests', 6);

        // Guard: the page must read the links, or a count of one proves nothing.
        self::assertCount(1, $this->reading($small['sql'], 'board_card_pull_requests'));
        self::assertCount(1, $this->reading($big['sql'], 'board_card_pull_requests'));
        self::assertSame(\count($small['sql']), \count($big['sql']));
        self::assertSame([], $this->reading($big['sql'], 'board_card_site_review_comments'));
        self::assertSame(['pullRequestId', 'url', 'forge', 'repository', 'number', 'state', 'agentReview'], array_keys($big['rows'][0]['pullRequests'][0]));
    }

    public function test_feedback_reads_the_comments_once_and_no_pull_request(): void
    {
        $small = $this->read('siteReviewComments', 3);
        $big = $this->read('siteReviewComments', 6);

        self::assertCount(1, $this->reading($small['sql'], 'board_card_site_review_comments'));
        self::assertCount(1, $this->reading($big['sql'], 'board_card_site_review_comments'));
        self::assertSame(\count($small['sql']), \count($big['sql']));
        self::assertSame([], $this->reading($big['sql'], 'board_card_pull_requests'));
        self::assertArrayHasKey('body', $big['rows'][0]['siteReviewComments'][0]);
    }

    public function test_the_default_row_holds_refs_and_reads_the_same_whatever_the_card_count(): void
    {
        $small = $this->read(null, 3);
        $big = $this->read(null, 6);

        self::assertSame(\count($small['sql']), \count($big['sql']));
        self::assertCount(1, $this->reading($big['sql'], 'board_card_pull_requests'));
        self::assertArrayNotHasKey('body', $big['rows'][0]);
        self::assertSame(['pullRequestId', 'url', 'forge', 'repository', 'number'], array_keys($big['rows'][0]['pullRequests'][0]));
        self::assertSame(['id', 'status', 'url'], array_keys($big['rows'][0]['siteReviewComments'][0]));
    }

    public function test_all_reads_the_links_once_whatever_the_card_count(): void
    {
        $small = $this->read('all', 3);
        $big = $this->read('all', 6);

        self::assertSame(\count($small['sql']), \count($big['sql']));
        self::assertCount(1, $this->reading($big['sql'], 'board_card_pull_requests'));
        self::assertArrayHasKey('body', $big['rows'][0]);
    }

    /**
     * @param list<string> $sql
     *
     * @return list<string>
     */
    private function reading(array $sql, string $table): array
    {
        return array_values(array_filter($sql, static fn (string $statement): bool => str_contains($statement, 'FROM '.$table) || str_contains($statement, 'JOIN '.$table)));
    }

    /** @return array{sql: list<string>, rows: list<array<string, mixed>>} */
    private function read(?string $fields, int $perPage): array
    {
        $selection = FieldSelection::fromRequest(Request::create('/x'.(null === $fields ? '' : '?fields='.$fields)), CardResponse::FIELDS);
        $this->em->clear();
        $cards = self::getContainer()->get(CardRepository::class);
        self::assertInstanceOf(CardRepository::class, $cards);
        $page = array_slice($cards->findBy(['project' => $this->project], ['number' => 'ASC']), 0, $perPage);
        self::assertContainsOnlyInstancesOf(Card::class, $page);
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $queries->reset();

        $rows = $this->payload->forFields($page, $selection);

        $all = [];
        foreach ($queries->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $all[] = (string) $query['sql'];
            }
        }
        // Guard: the page must have run its own reads.
        self::assertNotSame([], $rows);

        return ['sql' => $all, 'rows' => $rows];
    }
}
