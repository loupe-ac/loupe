<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Api;

use App\Api\Pagination\Cursor;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Entity\DocumentStatus;
use App\Tests\Support\AgentCredential;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** The read routes of the public document API: the list, one document, its versions and one version. */
final class DocumentReadApiTest extends WebTestCase
{
    use DocumentApiFixtures;

    public function test_the_list_holds_the_documents_oldest_first_in_the_contract_shape(): void
    {
        $second = $this->document('Second', createdAt: '2026-02-01 10:00:00');
        $first = $this->document('First', createdAt: '2026-01-01 10:00:00', description: 'The first draft');
        $this->tag($first, ['Plan', 'api']);
        $this->place($first, 'Release notes', 3);
        $this->document('Foreign', $this->makeProject($this->scenario->createUser('foreign-'.uniqid().'@example.com'), 'Other'), '2026-01-01 09:00:00');

        $this->send(Request::METHOD_GET, $this->base());

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame(['First', 'Second'], array_column($body['items'], 'title'));
        self::assertNull($body['next']);
        $this->assertReviewLink($first, $body['items'][0]['url']);
        self::assertSame([
            'documentId' => (string) $first->id,
            'url' => $body['items'][0]['url'],
            'title' => 'First',
            'language' => 'english',
            'status' => 'in-review',
            'archived' => false,
            'archiveReason' => null,
            'version' => 1,
            'versionDescription' => 'The first draft',
            'tags' => ['api', 'plan'],
            'series' => 'Release notes',
            'seriesOrdinal' => 3,
            'references' => [],
            'referencedBy' => [],
            'createdAt' => '2026-01-01T10:00:00+00:00',
        ], $body['items'][0]);
        self::assertNull($body['items'][1]['series']);
        self::assertSame((string) $second->id, $body['items'][1]['documentId']);
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
    }

    public function test_an_archived_document_is_left_out_unless_asked_for(): void
    {
        $this->document('Live', createdAt: '2026-01-01 10:00:00');
        $gone = $this->document('Gone', createdAt: '2026-01-02 10:00:00');
        $gone->archivedAt = new \DateTimeImmutable();
        $this->em()->flush();

        $this->send(Request::METHOD_GET, $this->base());
        self::assertSame(['Live'], array_column($this->json()['items'], 'title'));

        $this->send(Request::METHOD_GET, $this->base().'?includeArchived=true');
        self::assertSame(['Live', 'Gone'], array_column($this->json()['items'], 'title'));
        self::assertTrue($this->json()['items'][1]['archived']);
    }

    public function test_the_filters_narrow_the_list(): void
    {
        $plan = $this->document('Plan', createdAt: '2026-01-01 10:00:00');
        $this->tag($plan, ['alpha']);
        $audit = $this->document('Audit', createdAt: '2026-01-02 10:00:00');
        $audit->status = DocumentStatus::Approved;
        $this->tag($audit, ['beta']);
        $this->place($audit, 'Notes', 1);
        $this->em()->flush();

        $this->send(Request::METHOD_GET, $this->base().'?status=approved');
        self::assertSame(['Audit'], array_column($this->json()['items'], 'title'));

        $this->send(Request::METHOD_GET, $this->base().'?tag=%20ALPHA%20');
        self::assertSame(['Plan'], array_column($this->json()['items'], 'title'));

        $this->send(Request::METHOD_GET, $this->base().'?series=notes');
        self::assertSame(['Audit'], array_column($this->json()['items'], 'title'));
    }

    public function test_a_series_reads_by_position_and_a_search_puts_a_title_hit_first(): void
    {
        $three = $this->document('Part three', createdAt: '2026-01-01 10:00:00');
        $one = $this->document('Part one', createdAt: '2026-01-03 10:00:00');
        $two = $this->document('Part two', createdAt: '2026-01-02 10:00:00');
        $this->place($three, 'Blog', 3);
        $this->place($one, 'Blog', 1);
        $this->place($two, 'Blog', 2);

        $this->send(Request::METHOD_GET, $this->base().'?series=blog');
        self::assertSame(['Part one', 'Part two', 'Part three'], array_column($this->json()['items'], 'title'));

        $body = $this->document('Body hit', createdAt: '2026-02-01 10:00:00', markdown: 'the zebra grazes');
        $title = $this->document('Zebra in the title', createdAt: '2026-03-01 10:00:00');
        $this->send(Request::METHOD_GET, $this->base().'?q=zebra');
        self::assertSame(['Zebra in the title', 'Body hit'], array_column($this->json()['items'], 'title'));
        self::assertNotNull($body->id);
        self::assertNotNull($title->id);
    }

    public function test_an_unknown_filter_value_answers_422_with_the_fields(): void
    {
        $this->send(Request::METHOD_GET, $this->base().'?status=open&includeArchived=maybe');

        self::assertResponseStatusCodeSame(422);
        $body = $this->json();
        self::assertSame('validation_failed', $body['code']);
        self::assertSame(['status', 'includeArchived'], array_column($body['errors'], 'field'));
        self::assertSame(['status_invalid', 'include_archived_invalid'], array_column($body['errors'], 'code'));

        $this->send(Request::METHOD_GET, $this->base().'?sort=title');
        self::assertResponseStatusCodeSame(422);
    }

    public function test_a_cursor_walk_visits_every_document_once_in_either_direction(): void
    {
        foreach (range(1, 5) as $n) {
            $this->document('Doc '.$n, createdAt: sprintf('2026-01-0%d 10:00:00', $n));
        }

        $seen = [];
        $url = $this->base().'?limit=2';
        $pages = 0;
        while (null !== $url) {
            $this->send(Request::METHOD_GET, $url);
            self::assertResponseStatusCodeSame(200);
            $body = $this->json();
            $seen = [...$seen, ...array_column($body['items'], 'title')];
            if (null !== $body['next']) {
                self::assertStringContainsString('rel="next"', (string) $this->client->getResponse()->headers->get('Link'));
            }
            $url = $body['next'];
            ++$pages;
        }
        self::assertSame(3, $pages);
        self::assertSame(['Doc 1', 'Doc 2', 'Doc 3', 'Doc 4', 'Doc 5'], $seen);

        $seen = [];
        $url = $this->base().'?limit=2&sort=-createdAt';
        while (null !== $url) {
            $this->send(Request::METHOD_GET, $url);
            $body = $this->json();
            $seen = [...$seen, ...array_column($body['items'], 'title')];
            $url = $body['next'];
        }
        self::assertSame(['Doc 5', 'Doc 4', 'Doc 3', 'Doc 2', 'Doc 1'], $seen);
    }

    public function test_a_cursor_walk_holds_for_a_series_and_for_a_search(): void
    {
        foreach ([2, 1, 3] as $ordinal) {
            $this->place($this->document('Part '.$ordinal, createdAt: sprintf('2026-01-0%d 10:00:00', $ordinal)), 'Blog', $ordinal);
        }
        $seen = $this->walk($this->base().'?series=blog&limit=1');
        self::assertSame(['Part 1', 'Part 2', 'Part 3'], $seen);

        $this->document('Haystack one', createdAt: '2026-02-01 10:00:00', markdown: 'a needle inside');
        $this->document('Haystack two', createdAt: '2026-02-02 10:00:00', markdown: 'needle again');
        $this->document('Needle in the title', createdAt: '2026-02-03 10:00:00');
        $this->document('Needle second title', createdAt: '2026-02-04 10:00:00');
        $seen = $this->walk($this->base().'?q=needle&limit=1');
        self::assertSame(['Needle in the title', 'Needle second title', 'Haystack one', 'Haystack two'], $seen);
    }

    public function test_a_cursor_made_for_other_filters_is_refused(): void
    {
        $this->document('One', createdAt: '2026-01-01 10:00:00');
        $this->document('Two', createdAt: '2026-01-02 10:00:00');

        $this->send(Request::METHOD_GET, $this->base().'?limit=1');
        $next = $this->json()['next'];
        self::assertIsString($next);

        $this->send(Request::METHOD_GET, $next.'&status=approved');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_cursor', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->base().'?cursor=nonsense');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_cursor', $this->json()['code']);
    }

    /** @param array<string, scalar|null> $filters */
    #[DataProvider('brokenPositions')]
    public function test_a_cursor_whose_date_does_not_exist_is_refused(string $query, string $sort, string $position, array $filters): void
    {
        $this->document('One', createdAt: '2026-01-01 10:00:00');

        $cursor = new Cursor($sort, $position, (string) Uuid::v7(), $filters);
        $this->send(Request::METHOD_GET, $this->base().'?'.$query.'cursor='.$cursor->encode());

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_cursor', $this->json()['code']);
    }

    /** @return iterable<string, array{string, string, string, array<string, scalar|null>}> */
    public static function brokenPositions(): iterable
    {
        yield 'a month and a day out of range' => ['', 'oldest', '2026-99-99 00:00:00.000000', []];
        yield 'a day the month does not have' => ['', 'oldest', '2026-02-30 00:00:00.000000', []];
        yield 'a time out of range' => ['', 'oldest', '2026-01-01 25:00:00.000000', []];
        yield 'a date out of range under a search' => ['q=needle&', 'relevance', '1|2026-99-99 00:00:00.000000', ['q' => 'needle']];
    }

    public function test_the_limit_goes_to_200(): void
    {
        $this->send(Request::METHOD_GET, $this->base().'?limit=200');
        self::assertResponseStatusCodeSame(200);

        $this->send(Request::METHOD_GET, $this->base().'?limit=201');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('limit_out_of_range', $this->json()['errors'][0]['code']);
    }

    public function test_one_document_holds_its_markdown_and_both_directions_of_references(): void
    {
        $target = $this->document('Target', createdAt: '2026-01-01 10:00:00');
        $source = $this->document('Source', createdAt: '2026-01-02 10:00:00', markdown: '# Source text', description: 'v1');
        $source->addReference($target);
        $this->em()->flush();

        $this->send(Request::METHOD_GET, $this->base().'/'.$source->id);

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame(
            ['documentId', 'url', 'title', 'language', 'status', 'archived', 'archiveReason', 'version', 'versionDescription', 'markdown', 'tags', 'series', 'seriesOrdinal', 'references', 'referencedBy', 'createdAt'],
            array_keys($body),
        );
        $this->assertReviewLink($source, $body['url']);
        self::assertSame('# Source text', $body['markdown']);
        self::assertSame('v1', $body['versionDescription']);
        self::assertSame('english', $body['language']);
        self::assertSame([['documentId' => (string) $target->id, 'title' => 'Target', 'archived' => false]], $body['references']);
        self::assertSame('2026-01-02T10:00:00+00:00', $body['createdAt']);

        $this->send(Request::METHOD_GET, $this->base().'/'.$target->id);
        self::assertSame([['documentId' => (string) $source->id, 'title' => 'Source', 'archived' => false]], $this->json()['referencedBy']);
    }

    public function test_a_document_of_another_project_answers_404_whichever_route_asks(): void
    {
        $other = $this->makeProject($this->owner, 'Second project');
        $elsewhere = $this->document('Elsewhere', $other);

        foreach (['', '/versions', '/versions/1'] as $suffix) {
            $this->send(Request::METHOD_GET, $this->base().'/'.$elsewhere->id.$suffix);
            self::assertResponseStatusCodeSame(404, $suffix);
            self::assertSame('document_not_found', $this->json()['code'], $suffix);
        }

        $this->send(Request::METHOD_GET, $this->base($other).'/'.$elsewhere->id);
        self::assertResponseStatusCodeSame(200);
    }

    public function test_a_missing_or_malformed_document_id_answers_document_not_found(): void
    {
        foreach (['0198a000-0000-7000-8000-000000000000', 'not-a-uuid'] as $id) {
            $this->send(Request::METHOD_GET, $this->base().'/'.$id);
            self::assertResponseStatusCodeSame(404, $id);
            self::assertSame('document_not_found', $this->json()['code'], $id);
        }
    }

    public function test_a_project_of_another_owner_answers_project_not_found_and_leaks_nothing(): void
    {
        $stranger = $this->scenario->createUser('stranger-'.uniqid().'@example.com');
        $foreign = $this->makeProject($stranger, 'Foreign');
        $document = $this->document('Secret', $foreign);

        foreach ([$this->base($foreign), $this->base($foreign).'/'.$document->id, $this->base($foreign).'/'.$document->id.'/versions'] as $url) {
            $this->send(Request::METHOD_GET, $url);
            self::assertResponseStatusCodeSame(404, $url);
            self::assertSame('project_not_found', $this->json()['code'], $url);
        }
    }

    public function test_a_credential_bound_to_one_project_cannot_read_another(): void
    {
        $other = $this->makeProject($this->owner, 'Second project');
        $bound = AgentCredential::tokenFor(static::getContainer(), $this->owner, 'mcp public', $this->project);
        $inside = $this->document('Inside');
        $outside = $this->document('Outside', $other);

        $this->send(Request::METHOD_GET, $this->base().'/'.$inside->id, token: $bound);
        self::assertResponseStatusCodeSame(200);

        $this->send(Request::METHOD_GET, $this->base($other).'/'.$outside->id, token: $bound);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);
    }

    public function test_the_versions_list_counts_threads_and_walks_in_either_direction(): void
    {
        $document = $this->document('Revised', markdown: 'v1', description: 'First');
        $revise = static::getContainer()->get(ReviseDocumentHandler::class);
        foreach (['v2', 'v3'] as $text) {
            $revise(new ReviseDocumentCommand($document, $text, 'Changed to '.$text));
        }

        $this->send(Request::METHOD_GET, $this->base().'/'.$document->id.'/versions');

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame([1, 2, 3], array_column($body['items'], 'versionNumber'));
        self::assertSame(['versionNumber', 'createdAt', 'description', 'threadCount'], array_keys($body['items'][0]));
        self::assertSame('First', $body['items'][0]['description']);
        self::assertSame(0, $body['items'][0]['threadCount']);
        self::assertArrayNotHasKey('markdown', $body['items'][0]);

        self::assertSame([3, 2, 1], $this->walkVersions($this->base().'/'.$document->id.'/versions?sort=-createdAt&limit=1'));
        self::assertSame([1, 2, 3], $this->walkVersions($this->base().'/'.$document->id.'/versions?limit=2'));
    }

    public function test_one_version_holds_its_markdown(): void
    {
        $document = $this->document('Revised', markdown: 'first text', description: 'First');
        static::getContainer()->get(ReviseDocumentHandler::class)(new ReviseDocumentCommand($document, 'second text', 'Second'));

        $this->send(Request::METHOD_GET, $this->base().'/'.$document->id.'/versions/1');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['versionNumber' => 1, 'createdAt' => $this->json()['createdAt'], 'description' => 'First', 'threadCount' => 0, 'markdown' => 'first text'], $this->json());

        $this->send(Request::METHOD_GET, $this->base().'/'.$document->id.'/versions/2');
        self::assertSame('second text', $this->json()['markdown']);

        $this->send(Request::METHOD_GET, $this->base().'/'.$document->id.'/versions/3');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('document_version_not_found', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->base().'/'.$document->id.'/versions/0');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('route_not_found', $this->json()['code']);
    }

    public function test_a_credential_without_the_public_scope_gets_403_and_no_credential_gets_401(): void
    {
        $document = $this->document('Guarded');

        foreach (['mcp', 'agent'] as $scope) {
            $this->send(Request::METHOD_GET, $this->base().'/'.$document->id, token: AgentCredential::tokenFor(static::getContainer(), $this->owner, $scope, 'mcp' === $scope ? $this->project : null));
            self::assertResponseStatusCodeSame(403, $scope);
            self::assertSame('credential_not_allowed', $this->json()['code'], $scope);
        }

        $this->send(Request::METHOD_GET, $this->base(), token: null);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('invalid_credentials', $this->json()['code']);
    }

    /** @return list<string> the titles, in the order the walk met them */
    private function walk(string $url): array
    {
        $seen = [];
        while (null !== $url) {
            $this->send(Request::METHOD_GET, $url);
            self::assertResponseStatusCodeSame(200);
            $body = $this->json();
            $seen = [...$seen, ...array_column($body['items'], 'title')];
            $url = $body['next'];
        }

        return $seen;
    }

    /** @return list<int> */
    private function walkVersions(string $url): array
    {
        $seen = [];
        while (null !== $url) {
            $this->send(Request::METHOD_GET, $url);
            self::assertResponseStatusCodeSame(200);
            $body = $this->json();
            $seen = [...$seen, ...array_column($body['items'], 'versionNumber')];
            $url = $body['next'];
        }

        return $seen;
    }
}
