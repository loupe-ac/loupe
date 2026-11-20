<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** The documents list follows changes: a frame on page 1, and rows by id after it. */
final class DocumentsLiveListTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private Project $project;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->owner = $this->user('documents-live-owner@example.com');
        $this->project = new Project($this->owner, 'docs-live-'.bin2hex(random_bytes(4)));
        $this->em->persist($this->project);
        $this->em->flush();
        $this->client->loginUser($this->owner);
    }

    public function test_page_one_is_a_rows_region_with_a_row_id_on_each_row_and_no_cursor(): void
    {
        $first = $this->create('First');
        $second = $this->create('Second');

        $crawler = $this->client->request(Request::METHOD_GET, $this->list());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('turbo-frame#documents-live'));
        $region = $crawler->filter('turbo-frame#documents-list div#documents-live');
        self::assertCount(1, $region);
        self::assertSame('rows', $region->attr('data-live-region-mode-value'));
        self::assertSame(['documents.changed'], json_decode((string) $region->attr('data-live-region-types-value'), true));
        self::assertSame($this->list().'/live-rows?page=1', $region->attr('data-live-region-url-value'));
        $rows = $region->filter('[data-live-row]')->each(static fn (Crawler $row): ?string => $row->attr('data-live-row'));
        self::assertEqualsCanonicalizing([(string) $first->id, (string) $second->id], $rows);
        self::assertCount(2, $region->filter('[data-live-fade]'));
        self::assertCount(1, $region->filter('#document-row-'.$first->id));
        self::assertCount(1, $region->filter('#documents-rows'));
        self::assertCount(1, $region->filter('#documents-count'));
        self::assertCount(1, $region->filter('#documents-pagination'));
        self::assertCount(0, $crawler->filter('[data-live-since], [data-live-known], [data-live-total], #documents-live-band'));
    }

    public function test_the_list_page_is_not_a_frame_read_any_more(): void
    {
        $this->create('First');

        $this->client->request(Request::METHOD_GET, $this->list(), server: ['HTTP_TURBO_FRAME' => 'documents-live']);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('<turbo-stream', (string) $this->client->getResponse()->getContent());
    }

    public function test_page_one_morphs_a_kept_row_removes_a_left_row_and_inserts_a_new_row_before_the_row_that_follows_it(): void
    {
        $older = $this->create('Older');
        $left = $this->create('Left');
        $left->archivedAt = new \DateTimeImmutable();
        $this->create('Newer');
        $this->em->flush();

        $this->rows([$older, $left], time() - 3600, page: 1);

        self::assertResponseIsSuccessful();
        $streams = $this->streams();
        $byTarget = array_column(array_filter($streams, static fn (array $stream): bool => 'before' !== $stream['action']), null, 'target');
        self::assertSame(['replace', 'morph'], [$byTarget['document-row-'.$older->id]['action'], $byTarget['document-row-'.$older->id]['method']]);
        self::assertSame('remove', $byTarget['document-row-'.$left->id]['action']);
        $insert = array_values(array_filter($streams, static fn (array $stream): bool => 'before' === $stream['action']));
        self::assertCount(1, $insert);
        self::assertSame('document-row-'.$older->id, $insert[0]['target']);
        self::assertStringContainsString('Newer', $insert[0]['content']->text());
        self::assertContains('documents-count', array_column($streams, 'target'));
        self::assertContains('documents-pagination', array_column($streams, 'target'));
        self::assertNotContains('documents-live-band', array_column($streams, 'target'));
    }

    public function test_page_one_appends_a_row_that_is_last_and_inserts_the_rows_in_page_order(): void
    {
        $oldest = $this->create('Oldest');
        $this->create('Middle');
        $newest = $this->create('Newest');

        $this->rows([$newest], time() - 3600, page: 1);

        $streams = array_values(array_filter($this->streams(), static fn (array $stream): bool => \in_array($stream['action'], ['before', 'append'], true)));
        self::assertSame(
            [['append', 'documents-rows'], ['before', 'document-row-'.$oldest->id]],
            array_map(static fn (array $stream): array => [$stream['action'], $stream['target']], $streams),
            'the last row is appended first, so the row before it finds its follower in place',
        );
        self::assertStringContainsString('Oldest', $streams[0]['content']->text());
        self::assertStringContainsString('Middle', $streams[1]['content']->text());
    }

    public function test_page_one_replaces_the_list_when_the_screen_was_empty_and_the_page_is_not(): void
    {
        $this->create('Arrived');

        $this->rows([], time() - 3600, page: 1);

        $streams = array_column($this->streams(), null, 'target');
        self::assertSame('update', $streams['documents-results']['action']);
        self::assertStringContainsString('Arrived', $streams['documents-results']['content']->text());
        self::assertArrayNotHasKey('documents-pagination', $streams);
    }

    public function test_page_one_replaces_the_list_when_the_page_empties(): void
    {
        $gone = $this->create('Gone');
        $gone->archivedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->rows([$gone], time() - 3600, page: 1);

        $streams = array_column($this->streams(), null, 'target');
        self::assertSame('update', $streams['documents-results']['action']);
        self::assertCount(0, $streams['documents-results']['content']->filter('[data-live-row]'));
    }

    public function test_page_one_follows_the_filters_and_still_sends_the_filter_choices(): void
    {
        $shown = $this->create('Shown');
        $shown->status = DocumentStatus::Approved;
        $this->em->flush();

        $this->rows([$shown], time() - 3600, query: ['status' => 'in-review'], page: 1);

        $streams = array_column($this->streams(), null, 'target');
        self::assertSame('update', $streams['documents-results']['action']);
        self::assertArrayHasKey('documents-filter-choices', $streams);
    }

    public function test_the_page_subscribes_to_the_documents_topic(): void
    {
        $this->create('First');

        $this->client->request(Request::METHOD_GET, $this->list());

        $token = null;
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ('mercureAuthorization' === $cookie->getName()) {
                $token = $cookie->getValue();
            }
        }
        self::assertNotNull($token);
        $claims = json_decode((string) base64_decode(strtr(explode('.', $token)[1], '-_', '+/'), true), true);
        self::assertIsArray($claims);
        self::assertContains(
            static::getContainer()->get(ProjectTopicBuilder::class)->forDocuments($this->project->requireId()),
            $claims['mercure']['subscribe'],
        );
    }

    public function test_every_page_swaps_inside_one_list_frame_and_keeps_the_filter_bar_outside_it(): void
    {
        $this->createMany(21);

        foreach (['', '?page=2'] as $query) {
            $crawler = $this->client->request(Request::METHOD_GET, $this->list().$query);

            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('turbo-frame#documents-list #documents-live'));
            self::assertCount(0, $crawler->filter('turbo-frame#documents-list form.lp-filter-form'));
            self::assertSame('documents-list', $crawler->filter('form.lp-filter-form')->attr('data-turbo-frame'));
            self::assertSame('documents-list', $crawler->filter('.lp-pagination a')->first()->attr('data-turbo-frame'));
            self::assertSame('documents-list', $crawler->filter('.lp-document-row__action-form')->first()->attr('data-turbo-frame'));
            self::assertCount(1, $crawler->filter('turbo-frame#documents-list .lp-list-count'));
        }
    }

    public function test_a_later_page_reads_its_rows_by_id_after_the_cursor_it_loaded_at(): void
    {
        $this->createMany(21);

        $crawler = $this->client->request(Request::METHOD_GET, $this->list().'?page=2');

        self::assertResponseIsSuccessful();
        $region = $crawler->filter('div#documents-live');
        self::assertCount(1, $region);
        self::assertCount(0, $crawler->filter('turbo-frame#documents-live'));
        self::assertSame('rows', $region->attr('data-live-region-mode-value'));
        self::assertSame($this->list().'/live-rows?page=2', $region->attr('data-live-region-url-value'));
        self::assertMatchesRegularExpression('/^\d{10}$/', (string) $region->attr('data-live-since'));
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $region->attr('data-live-since-id'));
        self::assertCount(1, $region->filter('#documents-live-band'));
        self::assertCount(1, $region->filter('[data-live-row]'));
        self::assertCount(21, json_decode((string) $region->attr('data-live-known'), true));
    }

    public function test_the_rows_route_morphs_a_kept_row_and_removes_the_rest_and_counts_new_documents(): void
    {
        $kept = $this->create('Kept');
        $archived = $this->create('Archived');
        $archived->archivedAt = new \DateTimeImmutable();
        $this->em->flush();
        $since = time() - 3600;
        $newer = $this->create('Newer');

        $this->rows([$kept, $archived], $since, '0199aaaa-0000-7000-8000-000000000001');

        self::assertResponseIsSuccessful();
        $streams = $this->streams();
        $byTarget = [];
        foreach ($streams as $stream) {
            $byTarget[$stream['target']] = $stream;
        }
        self::assertSame(['replace', 'morph'], [$byTarget['document-row-'.$kept->id]['action'], $byTarget['document-row-'.$kept->id]['method']]);
        self::assertStringContainsString('Kept', $byTarget['document-row-'.$kept->id]['content']->text());
        self::assertSame('remove', $byTarget['document-row-'.$archived->id]['action']);
        self::assertSame('remove', $byTarget['document-row-0199aaaa-0000-7000-8000-000000000001']['action']);
        $band = $byTarget['documents-live-band']['content'];
        self::assertStringContainsString('1 new document.', $band->text());
        self::assertStringContainsString('/documents?page=1', (string) $band->filter('a')->attr('href'));
        self::assertNotContains('document-row-'.$newer->id, array_keys($byTarget), 'a new document is counted, never inserted');
    }

    public function test_the_rows_route_sends_the_tag_options_again_for_the_select_outside_the_list(): void
    {
        $shown = $this->create('Shown');
        $this->em->persist(new Tag($this->project, 'fresh tag'));
        $this->em->flush();

        $this->rows([$shown], time() - 3600);

        self::assertResponseIsSuccessful();
        $choices = array_column($this->streams(), null, 'target')['documents-filter-choices'];
        self::assertSame(['replace', 'morph'], [$choices['action'], $choices['method']]);
        self::assertStringContainsString('fresh tag', $choices['content']->text());
    }

    public function test_the_count_leaves_out_the_shown_rows_and_older_documents_and_follows_the_filters(): void
    {
        $shown = $this->create('Shown');
        $old = $this->create('Old');
        $this->em->getConnection()->executeStatement('UPDATE documents SET created_at = ? WHERE id = ?', [new \DateTimeImmutable('-2 days')->format('Y-m-d H:i:s'), (string) $old->id]);
        $approved = $this->create('Approved newer');
        $approved->status = DocumentStatus::Approved;
        $this->em->flush();
        $since = time() - 3600;

        $this->rows([$shown], $since);
        self::assertStringContainsString('1 new document.', $this->bandText(), 'Approved newer counts, Old does not, Shown is excluded');

        $this->rows([$shown], $since, query: ['status' => 'in-review']);
        self::assertSame('', $this->bandText());

        $this->rows([$shown], time() + 3600);
        self::assertSame('', $this->bandText());
    }

    public function test_an_older_document_that_changes_into_the_filter_counts_as_new(): void
    {
        $shown = $this->create('Shown');
        $shown->status = DocumentStatus::Approved;
        $old = $this->create('Old');
        $old->status = DocumentStatus::Draft;
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE documents SET created_at = ? WHERE id = ?', [new \DateTimeImmutable('-2 days')->format('Y-m-d H:i:s'), (string) $old->id]);
        $since = time() + 3600;
        $query = ['status' => 'approved'];

        $this->rows([$shown], $since, query: $query, total: 1);
        self::assertSame('', $this->bandText());

        $old->status = DocumentStatus::Approved;
        $this->em->flush();

        $this->rows([$shown], $since, query: $query, total: 1);
        self::assertStringContainsString('1 new document.', $this->bandText());

        $shown->status = DocumentStatus::Draft;
        $this->em->flush();
        $this->rows([$shown], $since, query: $query, total: 1);
        self::assertStringContainsString('1 new document.', $this->bandText(), 'a row that left the filter does not hide the one that entered');
    }

    public function test_a_document_that_enters_the_filter_while_another_leaves_counts_as_new(): void
    {
        $shown = $this->create('Shown');
        $leaving = $this->create('Leaving');
        $entering = $this->create('Entering');
        $shown->status = DocumentStatus::Approved;
        $leaving->status = DocumentStatus::Approved;
        $entering->status = DocumentStatus::Draft;
        $this->em->flush();
        $known = [(string) $shown->id, (string) $leaving->id];
        $query = ['status' => 'approved'];

        $this->rows([$shown], time() + 3600, query: $query, total: 2, known: $known);
        self::assertSame('', $this->bandText());

        $leaving->status = DocumentStatus::Draft;
        $entering->status = DocumentStatus::Approved;
        $this->em->flush();

        $this->rows([$shown], time() + 3600, query: $query, total: 2, known: $known);
        self::assertStringContainsString('1 new document.', $this->bandText(), 'the total stayed at 2, and the swap still counts');

        $shown->status = DocumentStatus::Draft;
        $this->em->flush();
        $this->rows([], time() + 3600, query: $query, total: 2, known: $known);
        self::assertStringContainsString('1 new document.', $this->bandText(), 'a shown row that left does not lower the count');
    }

    public function test_a_document_created_in_the_cursor_second_counts_after_the_cursor_id(): void
    {
        $cursor = $this->create('Cursor');
        $later = $this->create('Later');
        $at = '2026-01-01 10:00:00';
        $this->em->getConnection()->executeStatement('UPDATE documents SET created_at = ? WHERE id IN (?, ?)', [$at, (string) $cursor->id, (string) $later->id]);
        $since = new \DateTimeImmutable($at.' UTC')->getTimestamp();
        self::assertGreaterThan((string) $cursor->id, (string) $later->id);

        $this->rows([$cursor], $since, sinceId: (string) $cursor->id);
        self::assertStringContainsString('1 new document.', $this->bandText());

        $this->rows([$cursor, $later], $since, sinceId: (string) $later->id);
        self::assertSame('', $this->bandText(), 'a document that the cursor covers is not new');
    }

    public function test_a_row_that_a_status_filter_no_longer_keeps_is_removed(): void
    {
        $document = $this->create('Moves out');
        $document->status = DocumentStatus::Approved;
        $this->em->flush();

        $this->rows([$document], time() - 3600, query: ['status' => 'in-review']);

        $actions = array_column($this->streams(), 'action', 'target');
        self::assertSame('remove', $actions['document-row-'.$document->id]);
    }

    public function test_the_rows_route_is_refused_to_a_stranger(): void
    {
        $document = $this->create('Private');
        $this->client->loginUser($this->user('documents-live-stranger@example.com'));

        $this->rows([$document], time());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_a_stranger_gets_no_row_of_another_project(): void
    {
        $other = new Project($this->user('documents-live-other@example.com'), 'docs-other-'.bin2hex(random_bytes(4)));
        $this->em->persist($other);
        $this->em->flush();
        $foreign = $this->service(CreateDocumentHandler::class)(new CreateDocumentCommand($other, 'Foreign title', '# Foreign title'));

        $this->rows([$foreign], time() - 3600);

        self::assertStringNotContainsString('Foreign title', (string) $this->client->getResponse()->getContent());
        self::assertSame('remove', array_column($this->streams(), 'action', 'target')['document-row-'.$foreign->id]);
    }

    private function list(): string
    {
        return '/projects/'.$this->project->id.'/documents';
    }

    private function create(string $title): Document
    {
        return $this->service(CreateDocumentHandler::class)(new CreateDocumentCommand($this->project, $title, '# '.$title));
    }

    private function createMany(int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $this->create('Document '.$i);
        }
    }

    /**
     * @param list<Document>        $shown
     * @param array<string, string> $query
     * @param list<string>|null     $known
     */
    private function rows(array $shown, int $since, string $extraId = '', array $query = [], string $sinceId = '', ?int $total = null, ?array $known = null, int $page = 2): void
    {
        $ids = implode(',', array_filter([...array_map(static fn (Document $d): string => (string) $d->id, $shown), $extraId]));
        $parameters = ['ids' => $ids, 'page' => $page, 'since' => $since, 'sinceId' => $sinceId, ...(null === $total ? [] : ['total' => $total]), ...$query];
        $server = ['HTTP_X_LOUPE_LIVE_REFRESH' => '1', 'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'];
        if (null === $known) {
            $this->client->request(Request::METHOD_GET, $this->list().'/live-rows', $parameters, server: $server);

            return;
        }
        $this->client->request(Request::METHOD_POST, $this->list().'/live-rows?'.http_build_query($parameters), ['known' => implode(',', $known)], server: $server);
    }

    private function bandText(): string
    {
        return trim(array_column($this->streams(), 'content', 'target')['documents-live-band']->text());
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(fullName: 'Live User', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @return list<array{action: string, target: string, method: ?string, content: Crawler}> */
    private function streams(): array
    {
        $crawler = new Crawler((string) $this->client->getResponse()->getContent());
        $streams = [];
        foreach ($crawler->filter('turbo-stream') as $node) {
            $stream = new Crawler($node);
            $template = $stream->filter('template');
            $streams[] = [
                'action' => (string) $stream->attr('action'),
                'target' => (string) $stream->attr('target'),
                'method' => $stream->attr('method'),
                'content' => new Crawler(\count($template) > 0 ? $template->html() : ''),
            ];
        }

        return $streams;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = static::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
