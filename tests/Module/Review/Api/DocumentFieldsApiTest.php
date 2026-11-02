<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Api;

use App\Module\Account\Entity\User;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Controller\Api\DocumentResponse;
use App\Module\Review\Controller\Api\DocumentVersionResponse;
use App\Module\Review\Controller\Api\ReviewResponse;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\ValueObject\Anchor;
use App\Tests\Support\PublicSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** The fields parameter on the document reads: the two lists refuse the text, and every name means the same on a list and on a single read. */
final class DocumentFieldsApiTest extends WebTestCase
{
    use DocumentApiFixtures;

    public function test_each_name_a_document_list_accepts_equals_the_same_name_of_the_single_read(): void
    {
        [$target, $source] = $this->linkedDocuments();

        foreach ([$target, $source] as $index => $document) {
            $this->send(Request::METHOD_GET, $this->base().'/'.$document->id);
            $single = $this->json();
            self::assertNotSame([], $single['references'] + $single['referencedBy']);

            foreach (array_diff(DocumentResponse::FIELDS, ['markdown']) as $field) {
                $this->send(Request::METHOD_GET, $this->base().'?includeArchived=true&fields='.$field);
                self::assertResponseStatusCodeSame(200, $field);
                self::assertSame([$field => $single[$field]], $this->json()['items'][$index], $field);
            }
        }
    }

    public function test_the_default_document_row_is_the_single_read_without_its_markdown(): void
    {
        [$target, $source] = $this->linkedDocuments();
        $this->send(Request::METHOD_GET, $this->base().'/'.$source->id);
        $single = $this->json();
        unset($single['markdown']);

        $this->send(Request::METHOD_GET, $this->base().'?includeArchived=true');

        self::assertSame($single, $this->json()['items'][1]);
        self::assertSame(array_values(array_diff(DocumentResponse::FIELDS, ['markdown'])), array_keys($this->json()['items'][1]));
        self::assertNotNull($target->id);
    }

    /** @param list<string> $refused */
    #[DataProvider('refusedValues')]
    public function test_the_lists_refuse_the_markdown_and_all_with_their_own_code(string $query, array $refused): void
    {
        $document = $this->document('Plan');

        foreach ([$this->base(), $this->base().'/'.$document->id.'/versions'] as $url) {
            $this->send(Request::METHOD_GET, $url.'?fields='.$query);

            self::assertResponseStatusCodeSame(422, $url.' '.$query);
            $body = $this->json();
            self::assertSame('validation_failed', $body['code']);
            self::assertSame(array_fill(0, \count($refused), 'fields_refused'), array_column($body['errors'], 'code'));
            self::assertSame(array_fill(0, \count($refused), 'fields'), array_column($body['errors'], 'field'));
        }
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function refusedValues(): iterable
    {
        yield 'markdown' => ['markdown', ['markdown']];
        yield 'all' => ['all', ['all']];
    }

    public function test_a_plain_name_beside_the_markdown_still_refuses_only_the_markdown(): void
    {
        $document = $this->document('Plan');

        foreach ([$this->base() => 'title', $this->base().'/'.$document->id.'/versions' => 'description'] as $url => $plain) {
            $this->send(Request::METHOD_GET, $url.'?fields='.$plain.',markdown');

            self::assertResponseStatusCodeSame(422, $url);
            self::assertSame(['fields_refused'], array_column($this->json()['errors'], 'code'));
        }
    }

    public function test_an_unknown_name_on_a_list_is_a_wrong_name_and_not_a_refusal(): void
    {
        $document = $this->document('Plan');

        foreach ([$this->base(), $this->base().'/'.$document->id.'/versions'] as $url) {
            $this->send(Request::METHOD_GET, $url.'?fields=nope');

            self::assertResponseStatusCodeSame(422, $url);
            self::assertSame('fields_invalid', $this->json()['errors'][0]['code']);
            self::assertStringNotContainsString('markdown', $this->json()['errors'][0]['message']);
            self::assertStringNotContainsString('all', str_replace('Allowed', '', $this->json()['errors'][0]['message']));
        }
    }

    public function test_a_document_list_never_reads_the_text_of_a_version(): void
    {
        [, $source] = $this->linkedDocuments();
        $this->comment($source);
        $this->client->disableReboot();

        foreach (['', '?fields=title', '?fields=references,referencedBy,tags', '?fields=versionDescription'] as $query) {
            $statements = $this->statementsOf($this->base().$query);

            self::assertNotSame([], $statements, $query);
            self::assertSame([], $this->readingText($statements), $query);
        }
        $versions = $this->statementsOf($this->base().'/'.$source->id.'/versions');
        self::assertNotSame([], $versions);
        self::assertSame([], $this->readingText($versions));
    }

    public function test_a_narrow_document_list_skips_the_reference_reads(): void
    {
        $this->linkedDocuments();
        $this->client->disableReboot();

        $narrow = $this->statementsOf($this->base().'?fields=title');
        $whole = $this->statementsOf($this->base());

        self::assertNotSame([], $narrow);
        self::assertSame([], array_values(array_filter($narrow, static fn (string $sql): bool => str_contains($sql, 'document_references'))));
        self::assertNotSame([], array_values(array_filter($whole, static fn (string $sql): bool => str_contains($sql, 'document_references'))));
    }

    public function test_the_references_of_a_page_read_in_the_same_statements_whatever_the_page_size(): void
    {
        foreach (range(1, 4) as $n) {
            $target = $this->document('Target '.$n, createdAt: sprintf('2026-01-0%d 10:00:00', $n));
            $this->document('Source '.$n, createdAt: sprintf('2026-02-0%d 10:00:00', $n))->addReference($target);
        }
        $this->em()->flush();
        $this->client->disableReboot();

        $two = $this->statementsOf($this->base().'?limit=2');
        $eight = $this->statementsOf($this->base().'?limit=8');

        $ofDocuments = static fn (array $statements): int => \count(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'FROM documents ') || str_contains($sql, 'FROM document_versions ')));
        self::assertGreaterThanOrEqual(5, $ofDocuments($two));
        self::assertSame($ofDocuments($two), $ofDocuments($eight));
    }

    public function test_each_name_a_version_list_accepts_equals_the_same_name_of_the_single_read(): void
    {
        $document = $this->document('Revised', markdown: 'v1', description: 'First');
        static::getContainer()->get(ReviseDocumentHandler::class)(new ReviseDocumentCommand($document, 'v2', 'Second'));
        $this->comment($document, 1);
        $this->comment($document, 1);

        foreach ([1, 2] as $number) {
            $this->send(Request::METHOD_GET, $this->base().'/'.$document->id.'/versions/'.$number);
            $single = $this->json();
            self::assertSame(1 === $number ? 2 : 0, $single['threadCount']);

            foreach (array_diff(DocumentVersionResponse::FIELDS, ['markdown']) as $field) {
                $this->send(Request::METHOD_GET, $this->base().'/'.$document->id.'/versions?fields='.$field);
                self::assertSame([$field => $single[$field]], $this->json()['items'][$number - 1], $field);
            }

            $this->send(Request::METHOD_GET, $this->base().'/'.$document->id.'/versions');
            unset($single['markdown']);
            self::assertSame($single, $this->json()['items'][$number - 1]);
        }
    }

    public function test_a_single_read_only_removes_keys_and_refuses_an_unknown_name(): void
    {
        [, $source] = $this->linkedDocuments();
        $reads = [
            $this->base().'/'.$source->id => DocumentResponse::FIELDS,
            $this->base().'/'.$source->id.'/versions/1' => DocumentVersionResponse::FIELDS,
            $this->base().'/'.$source->id.'/review' => ReviewResponse::FIELDS,
        ];

        foreach ($reads as $url => $names) {
            $this->send(Request::METHOD_GET, $url);
            $whole = $this->json();
            self::assertSame($names, array_keys($whole), $url);

            $this->send(Request::METHOD_GET, $url.'?fields=all');
            self::assertSame($whole, $this->json(), $url);

            $this->send(Request::METHOD_GET, $url.'?fields='.$names[1].','.$names[0]);
            self::assertSame([$names[0] => $whole[$names[0]], $names[1] => $whole[$names[1]]], $this->json(), $url);

            foreach (['nope', '', 'all,'.$names[0]] as $bad) {
                $this->send(Request::METHOD_GET, $url.'?fields='.$bad);
                self::assertResponseStatusCodeSame(422, $url.' '.$bad);
                self::assertSame('fields_invalid', $this->json()['errors'][0]['code']);
            }
        }
    }

    public function test_each_answer_matches_the_schema_the_spec_publishes_for_it(): void
    {
        [, $source] = $this->linkedDocuments();
        $this->comment($source);
        $base = $this->base();

        $this->send(Request::METHOD_GET, $base.'?includeArchived=true');
        PublicSpec::assertMatches('DocumentList', $this->json());
        PublicSpec::assertMatches('DocumentRow', $this->json()['items'][1]);
        $this->send(Request::METHOD_GET, $base.'/'.$source->id.'/versions');
        PublicSpec::assertMatches('DocumentVersionList', $this->json());
        PublicSpec::assertMatches('DocumentVersionRow', $this->json()['items'][0]);

        foreach (['document' => ['', 'Document', 'PartialDocument', 'title,references'], 'versions/1' => ['', 'DocumentVersion', 'PartialDocumentVersion', 'description,threadCount'], 'review' => ['', 'DocumentReview', 'PartialDocumentReview', 'status,comments']] as $path => [, $whole, $partial, $fields]) {
            $url = $base.'/'.$source->id.('document' === $path ? '' : '/'.$path);
            $this->send(Request::METHOD_GET, $url);
            PublicSpec::assertMatches($whole, $this->json(), $path);
            $this->send(Request::METHOD_GET, $url.'?fields=all');
            PublicSpec::assertMatches($whole, $this->json(), $path.' all');
            $this->send(Request::METHOD_GET, $url.'?fields='.$fields);
            PublicSpec::assertMatches($partial, $this->json(), $path.' narrowed');
        }

        $this->send(Request::METHOD_GET, $base.'?includeArchived=true&fields=title,references');
        PublicSpec::assertMatches('DocumentList', $this->json());
        PublicSpec::assertMatches('PartialDocument', $this->json()['items'][0]);
        $this->send(Request::METHOD_GET, $base.'/'.$source->id.'/versions?fields=description,threadCount');
        PublicSpec::assertMatches('DocumentVersionList', $this->json());
        PublicSpec::assertMatches('PartialDocumentVersion', $this->json()['items'][0]);
    }

    /** @return array{Document, Document} the target, then the source that points at it, archived with a reason, tagged and in a series */
    private function linkedDocuments(): array
    {
        $target = $this->document('Target', createdAt: '2026-01-01 10:00:00', description: 'The target');
        $source = $this->document('Source', createdAt: '2026-01-02 10:00:00', markdown: '# Source text', description: 'The source');
        $source->addReference($target);
        $source->archivedAt = new \DateTimeImmutable('2026-01-05 10:00:00');
        $source->archiveReason = 'Superseded';
        $this->em()->flush();
        $this->tag($source, ['plan', 'api']);
        $this->place($source, 'Release notes', 2);

        return [$target, $source];
    }

    private function comment(Document $document, int $versionNumber = 1): void
    {
        $versions = static::getContainer()->get(DocumentVersionRepository::class);
        self::assertInstanceOf(DocumentVersionRepository::class, $versions);
        $version = $versions->findByNumber($this->reference(Document::class, $document->id), $versionNumber);
        self::assertNotNull($version);
        $this->em()->persist(new Comment($version, $this->reference(User::class, $this->owner->id), 'Please check.', new Anchor('Plan', '', '', 0), null));
        $this->em()->flush();
    }

    /** @return list<string> */
    private function statementsOf(string $url): array
    {
        $queries = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $queries->reset();
        $this->send(Request::METHOD_GET, $url);
        self::assertResponseStatusCodeSame(200, $url);

        $all = [];
        foreach ($queries->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $all[] = (string) $query['sql'];
            }
        }

        return $all;
    }

    /**
     * @param list<string> $statements
     *
     * @return list<string>
     */
    private function readingText(array $statements): array
    {
        return array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'markdown_source') || str_contains($sql, 'rendered_html')));
    }
}
