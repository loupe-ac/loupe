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

    public function test_page_one_is_a_morphing_frame_with_a_row_id_on_each_row(): void
    {
        $first = $this->create('First');
        $second = $this->create('Second');

        $crawler = $this->client->request(Request::METHOD_GET, $this->list());

        self::assertResponseIsSuccessful();
        $frame = $crawler->filter('turbo-frame#documents-live');
        self::assertCount(1, $frame);
        self::assertSame('morph', $frame->attr('refresh'));
        self::assertSame('_top', $frame->attr('target'));
        self::assertSame('frame', $frame->attr('data-live-region-mode-value'));
        self::assertSame(['documents.changed'], json_decode((string) $frame->attr('data-live-region-types-value'), true));
        self::assertSame($this->list().'?page=1', $frame->attr('data-live-region-url-value'));
        $rows = $frame->filter('[data-live-row]')->each(static fn (Crawler $row): ?string => $row->attr('data-live-row'));
        self::assertEqualsCanonicalizing([(string) $first->id, (string) $second->id], $rows);
        self::assertCount(2, $frame->filter('[data-live-fade]'));
        self::assertCount(1, $frame->filter('#document-row-'.$first->id));
        self::assertCount(0, $crawler->filter('[data-live-since]'));
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
     */
    private function rows(array $shown, int $since, string $extraId = '', array $query = [], string $sinceId = ''): void
    {
        $ids = implode(',', array_filter([...array_map(static fn (Document $d): string => (string) $d->id, $shown), $extraId]));
        $this->client->request(Request::METHOD_GET, $this->list().'/live-rows', ['ids' => $ids, 'since' => $since, 'sinceId' => $sinceId, ...$query], server: ['HTTP_X_LOUPE_LIVE_REFRESH' => '1', 'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
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
