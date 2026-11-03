<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Api;

use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\PageLink;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** The write routes of the public document API: create, change, publish and revise. */
final class DocumentWriteApiTest extends WebTestCase
{
    use DocumentApiFixtures;

    public function test_a_create_answers_201_with_the_location_and_the_review_url(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), json_encode([
            'title' => '  Release plan ',
            'markdown' => '# Plan',
            'description' => 'The first cut.',
            'tags' => ['Plan', 'plan', 'API'],
            'series' => 'Release notes',
            'seriesOrdinal' => 2,
            'language' => 'french',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertSame(['documentId', 'url', 'tags', 'series', 'seriesOrdinal', 'language', 'status'], array_keys($body));
        self::assertSame(['api', 'plan'], $body['tags']);
        self::assertSame('Release notes', $body['series']);
        self::assertSame(2, $body['seriesOrdinal']);
        self::assertSame('french', $body['language']);
        self::assertSame('in-review', $body['status']);
        self::assertStringEndsWith('/projects/'.$this->project->id.'/documents/'.$body['documentId'].'/review', $body['url']);
        self::assertSame('app_document_review', PageLink::match(static::getContainer(), $body['url'])['_route']);
        self::assertStringEndsWith('/api/v1/projects/'.$this->project->id.'/documents/'.$body['documentId'], (string) $this->client->getResponse()->headers->get('Location'));

        $documents = $this->stored();
        self::assertCount(1, $documents);
        self::assertSame('Release plan', $documents[0]->title);
        self::assertSame($body['documentId'], (string) $documents[0]->id);

        $this->send(Request::METHOD_GET, (string) $this->client->getResponse()->headers->get('Location'));
        self::assertResponseStatusCodeSame(200);
        self::assertSame('The first cut.', $this->json()['versionDescription']);
        self::assertSame('# Plan', $this->json()['markdown']);
    }

    public function test_a_draft_stays_a_draft_and_the_language_defaults_to_the_project(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), '{"title":"Draft","markdown":"x","draft":true}');

        self::assertResponseStatusCodeSame(201);
        self::assertSame('draft', $this->json()['status']);
        self::assertSame($this->project->searchLanguage->value, $this->json()['language']);
    }

    public function test_a_retry_with_the_same_key_repeats_the_answer_and_creates_one_document(): void
    {
        $body = '{"title":"Once","markdown":"x"}';
        $this->send(Request::METHOD_POST, $this->base(), $body, ['HTTP_IDEMPOTENCY_KEY' => 'create-once']);
        self::assertResponseStatusCodeSame(201);
        $first = (string) $this->client->getResponse()->getContent();
        $location = $this->client->getResponse()->headers->get('Location');

        $this->send(Request::METHOD_POST, $this->base(), $body, ['HTTP_IDEMPOTENCY_KEY' => 'create-once']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($first, (string) $this->client->getResponse()->getContent());
        self::assertSame($location, $this->client->getResponse()->headers->get('Location'));
        self::assertCount(1, $this->stored());

        $this->send(Request::METHOD_POST, $this->base(), '{"title":"Other","markdown":"x"}', ['HTTP_IDEMPOTENCY_KEY' => 'create-once']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('idempotency_key_reused', $this->json()['code']);
        self::assertCount(1, $this->stored());
    }

    public function test_a_refused_create_lists_every_field_and_stores_nothing(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), json_encode(['title' => '  ', 'markdown' => ''], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        $body = $this->json();
        self::assertSame('validation_failed', $body['code']);
        self::assertSame(['title', 'markdown'], array_column($body['errors'], 'field'));
        self::assertSame(['title_blank', 'markdown_blank'], array_column($body['errors'], 'code'));
        self::assertSame([], $this->stored());
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function refusedCreates(): iterable
    {
        yield 'title too long' => [['title' => str_repeat('t', 256), 'markdown' => 'x'], 'title', 'title_too_long'];
        yield 'tag too long' => [['title' => 'T', 'markdown' => 'x', 'tags' => [str_repeat('g', 51)]], 'tags', 'tag_too_long'];
        yield 'series without ordinal' => [['title' => 'T', 'markdown' => 'x', 'series' => 'Blog'], 'seriesOrdinal', 'ordinal_required'];
        yield 'ordinal without series' => [['title' => 'T', 'markdown' => 'x', 'seriesOrdinal' => 1], 'series', 'series_name_required'];
        yield 'ordinal below one' => [['title' => 'T', 'markdown' => 'x', 'series' => 'Blog', 'seriesOrdinal' => 0], 'seriesOrdinal', 'ordinal_not_positive'];
        yield 'reference to nothing' => [['title' => 'T', 'markdown' => 'x', 'references' => ['0198a000-0000-7000-8000-000000000000']], 'references', 'other_project'];
        yield 'markdown over 1 MiB' => [['title' => 'T', 'markdown' => str_repeat('a', Document::MAX_MARKDOWN_BYTES + 1)], 'markdown', 'markdown_too_large'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedCreates')]
    public function test_a_create_names_the_code_of_a_refused_field(array $payload, string $field, string $code): void
    {
        $this->send(Request::METHOD_POST, $this->base(), json_encode($payload, \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => $field, 'code' => $code]], array_map(static fn (array $error): array => ['field' => $error['field'], 'code' => $error['code']], $this->json()['errors']));
        self::assertSame([], $this->stored());
    }

    public function test_an_unknown_language_is_refused(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), '{"title":"T","markdown":"x","language":"klingon"}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('language', $this->json()['errors'][0]['field']);
        self::assertSame([], $this->stored());
    }

    public function test_a_reference_into_another_project_is_refused_like_a_missing_one(): void
    {
        $other = $this->makeProject($this->owner, 'Second project');
        $elsewhere = $this->document('Elsewhere', $other);

        $this->send(Request::METHOD_POST, $this->base(), json_encode(['title' => 'T', 'markdown' => 'x', 'references' => [(string) $elsewhere->id]], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('other_project', $this->json()['errors'][0]['code']);
        self::assertSame([], $this->stored());
    }

    public function test_a_taken_series_ordinal_answers_409(): void
    {
        $this->place($this->document('Holder'), 'Blog', 1);

        $this->send(Request::METHOD_POST, $this->base(), '{"title":"Second","markdown":"x","series":"blog","seriesOrdinal":1}');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('series_ordinal_taken', $this->json()['code']);
        self::assertCount(1, $this->stored());
    }

    public function test_a_body_that_is_not_json_answers_400_and_another_content_type_415(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), '{nope');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_json', $this->json()['code']);

        $this->send(Request::METHOD_POST, $this->base(), 'title=x', ['CONTENT_TYPE' => 'text/plain']);
        self::assertResponseStatusCodeSame(415);
        self::assertSame('unsupported_media_type', $this->json()['code']);
    }

    public function test_a_project_of_another_owner_cannot_be_written(): void
    {
        $foreign = $this->makeProject($this->scenario->createUser('stranger-'.uniqid().'@example.com'), 'Foreign');

        $this->send(Request::METHOD_POST, $this->base($foreign), '{"title":"T","markdown":"x"}');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);
        self::assertSame([], $this->stored($foreign));
    }

    public function test_a_credential_without_the_public_scope_cannot_write(): void
    {
        $document = $this->document('Guarded');
        $agent = AgentCredential::tokenFor(static::getContainer(), $this->owner, 'agent');

        foreach ([
            [Request::METHOD_POST, $this->base(), '{"title":"T","markdown":"x"}'],
            [Request::METHOD_PATCH, $this->base().'/'.$document->id, '{"title":"New"}'],
            [Request::METHOD_POST, $this->base().'/'.$document->id.'/publish', ''],
            [Request::METHOD_POST, $this->base().'/'.$document->id.'/versions', '{"markdown":"y","description":"d"}'],
        ] as [$method, $url, $body]) {
            $this->send($method, $url, $body, token: $agent);
            self::assertResponseStatusCodeSame(403, $method.' '.$url);
            self::assertSame('credential_not_allowed', $this->json()['code']);
        }
        self::assertSame('Guarded', $this->stored()[0]->title);
        self::assertCount(1, $this->stored());
    }

    public function test_a_patch_changes_every_field_it_names_and_leaves_the_rest(): void
    {
        $document = $this->document('Old title', markdown: '# Keep me');
        $target = $this->document('Target');
        $this->tag($document, ['old']);

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$document->id, json_encode([
            'title' => ' New title ',
            'tags' => ['Fresh', 'api'],
            'references' => [(string) $target->id],
            'series' => 'Blog',
            'seriesOrdinal' => 4,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame('New title', $body['title']);
        $this->assertReviewLink($document, $body['url']);
        self::assertSame(['api', 'fresh'], $body['tags']);
        self::assertSame('Blog', $body['series']);
        self::assertSame(4, $body['seriesOrdinal']);
        self::assertSame([(string) $target->id], array_column($body['references'], 'documentId'));
        self::assertSame('# Keep me', $body['markdown']);
        self::assertSame(1, $body['version']);
        self::assertSame(1, $this->em()->find(Document::class, $document->id)?->versions->count());
    }

    public function test_an_empty_patch_changes_nothing_and_a_null_series_takes_the_document_out(): void
    {
        $document = $this->document('Steady');
        $this->place($document, 'Blog', 1);

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$document->id, '{}');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Blog', $this->json()['series']);

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$document->id, '{"series":null,"seriesOrdinal":null}');
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->json()['series']);
        self::assertNull($this->json()['seriesOrdinal']);
    }

    public function test_a_patch_that_names_half_a_placement_is_refused(): void
    {
        $document = $this->document('Steady');
        $this->place($document, 'Blog', 1);

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$document->id, '{"series":"Other"}');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('ordinal_required', $this->json()['errors'][0]['code']);

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$document->id, '{"seriesOrdinal":2}');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('series_name_required', $this->json()['errors'][0]['code']);

        self::assertSame('Blog', $this->stored()[0]->series?->name);
    }

    public function test_a_patch_archives_with_a_reason_and_restores(): void
    {
        $document = $this->document('Old');

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$document->id, '{"archived":true,"archiveReason":"Superseded"}');
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->json()['archived']);
        self::assertSame('Superseded', $this->json()['archiveReason']);

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$document->id, '{"archived":false}');
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->json()['archived']);
        self::assertNull($this->json()['archiveReason']);
    }

    public function test_a_patch_that_refuses_one_field_changes_none_of_them(): void
    {
        $document = $this->document('Unchanged');
        $this->tag($document, ['keep']);

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$document->id, json_encode([
            'title' => 'Changed',
            'tags' => ['new'],
            'archived' => true,
            'references' => [(string) $document->id],
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        $codes = array_column($this->json()['errors'], 'code', 'field');
        ksort($codes);
        self::assertSame(['archiveReason' => 'archive_reason_blank', 'references' => 'self_reference'], $codes);
        $stored = $this->stored()[0];
        self::assertSame('Unchanged', $stored->title);
        self::assertNull($stored->archivedAt);
        self::assertSame(['keep'], array_values(array_map(static fn ($tag): string => $tag->name, $stored->tags->toArray())));
    }

    public function test_a_patch_that_hits_a_taken_ordinal_changes_nothing_else(): void
    {
        $this->place($this->document('Holder'), 'Blog', 1);
        $document = $this->document('Mover');

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$document->id, '{"title":"Moved","series":"Blog","seriesOrdinal":1}');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('series_ordinal_taken', $this->json()['code']);
        $titles = array_map(static fn (Document $stored): string => $stored->title, $this->stored());
        self::assertContains('Mover', $titles);
        self::assertNotContains('Moved', $titles);
    }

    public function test_a_patch_refuses_a_null_field_and_a_blank_title(): void
    {
        $document = $this->document('Steady');

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$document->id, '{"title":null}');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('title', $this->json()['errors'][0]['field']);

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$document->id, '{"title":"   "}');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('title_blank', $this->json()['errors'][0]['code']);
    }

    public function test_a_document_of_another_project_cannot_be_written(): void
    {
        $other = $this->makeProject($this->owner, 'Second project');
        $elsewhere = $this->document('Elsewhere', $other);

        foreach ([
            [Request::METHOD_PATCH, '{"title":"New"}', ''],
            [Request::METHOD_POST, '', '/publish'],
            [Request::METHOD_POST, '{"markdown":"y","description":"d"}', '/versions'],
        ] as [$method, $body, $suffix]) {
            $this->send($method, $this->base().'/'.$elsewhere->id.$suffix, $body);
            self::assertResponseStatusCodeSame(404, $suffix);
            self::assertSame('document_not_found', $this->json()['code'], $suffix);
        }
        self::assertSame('Elsewhere', $this->stored($other)[0]->title);
        self::assertSame(1, $this->stored($other)[0]->versions->count());
    }

    public function test_a_publish_sends_a_draft_to_review_and_leaves_any_other_document_alone(): void
    {
        $draft = $this->document('Draft');
        $draft->status = DocumentStatus::Draft;
        $this->em()->flush();

        $this->send(Request::METHOD_POST, $this->base().'/'.$draft->id.'/publish');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['documentId' => (string) $draft->id, 'url' => $this->json()['url'], 'status' => 'in-review', 'published' => true], $this->json());
        $this->assertReviewLink($draft, $this->json()['url']);

        $this->send(Request::METHOD_POST, $this->base().'/'.$draft->id.'/publish');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['documentId' => (string) $draft->id, 'url' => $this->json()['url'], 'status' => 'in-review', 'published' => false], $this->json());
        $this->assertReviewLink($draft, $this->json()['url']);
    }

    public function test_an_archived_draft_cannot_be_published(): void
    {
        $draft = $this->document('Draft');
        $draft->status = DocumentStatus::Draft;
        $draft->archivedAt = new \DateTimeImmutable();
        $this->em()->flush();

        $this->send(Request::METHOD_POST, $this->base().'/'.$draft->id.'/publish');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('document_archived', $this->json()['code']);
        self::assertSame(DocumentStatus::Draft, $this->stored()[0]->status);
    }

    public function test_a_revision_adds_a_version_and_answers_201_with_the_counts(): void
    {
        $document = $this->document('Revised', markdown: 'first');

        $this->send(Request::METHOD_POST, $this->base().'/'.$document->id.'/versions', json_encode([
            'markdown' => 'second',
            'description' => 'Rewrote it',
            'title' => 'Revised again',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['url' => $this->json()['url'], 'versionNumber' => 2, 'carried' => 0, 'orphaned' => 0], $this->json());
        $this->assertReviewLink($document, $this->json()['url']);
        self::assertStringEndsWith('/documents/'.$document->id.'/versions/2', (string) $this->client->getResponse()->headers->get('Location'));

        $this->send(Request::METHOD_GET, $this->base().'/'.$document->id);
        self::assertSame('second', $this->json()['markdown']);
        self::assertSame('Revised again', $this->json()['title']);
        self::assertSame(2, $this->json()['version']);
    }

    public function test_a_retry_of_a_revision_with_the_same_key_adds_one_version(): void
    {
        $document = $this->document('Revised');
        $body = '{"markdown":"second","description":"Once"}';

        $this->send(Request::METHOD_POST, $this->base().'/'.$document->id.'/versions', $body, ['HTTP_IDEMPOTENCY_KEY' => 'revise-once']);
        $first = (string) $this->client->getResponse()->getContent();
        $this->send(Request::METHOD_POST, $this->base().'/'.$document->id.'/versions', $body, ['HTTP_IDEMPOTENCY_KEY' => 'revise-once']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($first, (string) $this->client->getResponse()->getContent());
        self::assertSame(2, $this->stored()[0]->versions->count());
    }

    public function test_a_revision_replaces_the_references_inside_the_project_only(): void
    {
        $document = $this->document('Revised');
        $target = $this->document('Target');
        $elsewhere = $this->document('Elsewhere', $this->makeProject($this->owner, 'Second project'));
        $url = $this->base().'/'.$document->id.'/versions';

        $this->send(Request::METHOD_POST, $url, json_encode(['markdown' => 'x', 'description' => 'Link it', 'references' => [(string) $target->id]], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $this->send(Request::METHOD_GET, $this->base().'/'.$document->id);
        self::assertSame([(string) $target->id], array_column($this->json()['references'], 'documentId'));

        $this->send(Request::METHOD_POST, $url, json_encode(['markdown' => 'y', 'description' => 'Leave it'], \JSON_THROW_ON_ERROR));
        $this->send(Request::METHOD_GET, $this->base().'/'.$document->id);
        self::assertCount(1, $this->json()['references']);

        $this->send(Request::METHOD_POST, $url, json_encode(['markdown' => 'z', 'description' => 'Bad link', 'references' => [(string) $elsewhere->id]], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('other_project', $this->json()['errors'][0]['code']);

        $this->send(Request::METHOD_POST, $url, json_encode(['markdown' => 'w', 'description' => 'Clear it', 'references' => []], \JSON_THROW_ON_ERROR));
        $this->send(Request::METHOD_GET, $this->base().'/'.$document->id);
        self::assertSame([], $this->json()['references']);
        self::assertSame(4, $this->json()['version']);
    }

    public function test_a_refused_revision_adds_no_version(): void
    {
        $document = $this->document('Revised');

        $this->send(Request::METHOD_POST, $this->base().'/'.$document->id.'/versions', json_encode(['markdown' => '', 'description' => '  '], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['markdown', 'description'], array_column($this->json()['errors'], 'field'));

        $this->send(Request::METHOD_POST, $this->base().'/'.$document->id.'/versions', json_encode(['markdown' => str_repeat('a', Document::MAX_MARKDOWN_BYTES + 1), 'description' => 'Huge'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('markdown_too_large', $this->json()['errors'][0]['code']);

        $this->send(Request::METHOD_POST, $this->base().'/'.$document->id.'/versions', json_encode(['markdown' => 'x', 'description' => 'd', 'series' => 'Blog'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('ordinal_required', $this->json()['errors'][0]['code']);

        self::assertSame(1, $this->em()->find(Document::class, $document->id)?->versions->count());
    }
}
