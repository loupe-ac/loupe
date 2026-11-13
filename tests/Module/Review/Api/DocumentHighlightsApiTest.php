<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Api;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Mcp\DocumentHighlightTool;
use App\Tests\Support\ApiKeyCredential;
use App\Tests\Support\PublicSpec;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

/** PUT /api/v1/projects/{project}/documents/{document}/highlights. */
final class DocumentHighlightsApiTest extends WebTestCase
{
    use DocumentApiFixtures;

    private const string HTML = '<p>We will issue short-lived JWTs.</p><p>Refresh tokens rotate on use.</p>';

    public function test_it_replaces_the_set_and_reports_what_it_skipped(): void
    {
        $this->setFlag(true);
        $document = $this->prose();

        $this->put($document, ['quotes' => ['short-lived JWTs', '  Refresh tokens rotate  ', 'no such passage', 'short-lived  JWTs', '   ']]);

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        PublicSpec::assertMatches('DocumentHighlights', $body);
        self::assertSame(['short-lived JWTs', 'Refresh tokens rotate'], $body['highlighted']);
        self::assertSame([
            ['quote' => 'no such passage', 'reason' => 'not_found'],
            ['quote' => 'short-lived  JWTs', 'reason' => 'duplicate'],
            ['quote' => '   ', 'reason' => 'blank'],
        ], $body['skipped']);
        self::assertSame(2, $this->highlightCount($document));
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
    }

    public function test_a_second_call_replaces_the_first_and_an_empty_list_clears_the_set(): void
    {
        $this->setFlag(true);
        $document = $this->prose();

        $this->put($document, ['quotes' => ['short-lived JWTs', 'Refresh tokens']]);
        self::assertSame(2, $this->highlightCount($document));

        $this->put($document, ['quotes' => ['rotate on use']]);
        self::assertSame(['rotate on use'], $this->json()['highlighted']);
        self::assertSame(1, $this->highlightCount($document));

        $this->put($document, ['quotes' => []]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['highlighted' => [], 'skipped' => []], $this->json());
        self::assertSame(0, $this->highlightCount($document));
    }

    public function test_the_same_call_twice_gives_the_same_set(): void
    {
        $this->setFlag(true);
        $document = $this->prose();

        $this->put($document, ['quotes' => ['short-lived JWTs']]);
        $first = $this->json();
        $this->put($document, ['quotes' => ['short-lived JWTs']]);

        self::assertSame($first, $this->json());
        self::assertSame(1, $this->highlightCount($document));
    }

    /** @return iterable<string, array{string}> */
    public static function refusedBodies(): iterable
    {
        yield 'missing' => ['{}'];
        yield 'null' => ['{"quotes":null}'];
        yield 'a string' => ['{"quotes":"short-lived JWTs"}'];
        yield 'a number in the list' => ['{"quotes":["ok",3]}'];
    }

    #[DataProvider('refusedBodies')]
    public function test_a_body_without_a_list_of_strings_is_refused_and_keeps_the_set(string $body): void
    {
        $this->setFlag(true);
        $document = $this->prose();
        $this->put($document, ['quotes' => ['short-lived JWTs']]);

        $this->send(Request::METHOD_PUT, $this->url($document), $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->json()['code']);
        self::assertSame(1, $this->highlightCount($document));
    }

    public function test_a_switched_off_flag_answers_route_not_found_and_changes_nothing(): void
    {
        $document = $this->prose();
        $this->setFlag(true);
        $this->put($document, ['quotes' => ['short-lived JWTs']]);
        $this->setFlag(false);

        $this->put($document, ['quotes' => []]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('route_not_found', $this->json()['code']);
        self::assertFalse($this->client->getResponse()->headers->has('RateLimit-Limit'), 'the gate runs before the budget');
        self::assertSame(1, $this->highlightCount($document));
    }

    public function test_a_document_of_another_project_is_not_found(): void
    {
        $this->setFlag(true);
        $other = $this->makeProject($this->owner, 'Other shop');
        $foreign = $this->prose($other);

        $this->send(Request::METHOD_PUT, '/api/v1/projects/'.$this->project->id.'/documents/'.$foreign->id.'/highlights', '{"quotes":[]}');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('document_not_found', $this->json()['code']);

        $this->send(Request::METHOD_PUT, '/api/v1/projects/'.$this->project->id.'/documents/'.Uuid::v7().'/highlights', '{"quotes":[]}');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('document_not_found', $this->json()['code']);
    }

    public function test_a_stranger_does_not_find_the_project_and_no_credential_is_refused(): void
    {
        $this->setFlag(true);
        $document = $this->prose();
        $stranger = $this->scenario->createUser('highlights-api-stranger-'.uniqid().'@example.com');
        $strangerToken = ApiKeyCredential::mint(static::getContainer(), $stranger);

        $this->send(Request::METHOD_PUT, $this->url($document), '{"quotes":[]}', [], $strangerToken);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);

        $this->send(Request::METHOD_PUT, $this->url($document), '{"quotes":[]}', [], null);
        self::assertResponseStatusCodeSame(401);
    }

    private function prose(?Project $project = null): Document
    {
        $em = $this->em();
        $project = $this->reference(Project::class, ($project ?? $this->project)->id);
        $document = new Document(owner: $this->reference(User::class, $project->owner->id), project: $project, title: 'Prose');
        $document->addVersion('We will issue short-lived JWTs.', self::HTML);
        $em->persist($document);
        $em->flush();

        return $document;
    }

    /** @param array<string, mixed> $payload */
    private function put(Document $document, array $payload): void
    {
        $this->send(Request::METHOD_PUT, $this->url($document), json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    private function url(Document $document): string
    {
        return '/api/v1/projects/'.$document->project->id.'/documents/'.$document->id.'/highlights';
    }

    private function highlightCount(Document $document): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM document_highlights h JOIN document_versions v ON v.id = h.version_id WHERE v.document_id = :id', ['id' => (string) $document->id]);
    }

    private function setFlag(bool $enabled): void
    {
        $flags = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[DocumentHighlightTool::FLAG]->value = $enabled;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $reader = static::getContainer()->get(FeatureFlagReaderInterface::class);
        self::assertInstanceOf(ResetInterface::class, $reader);
        $reader->reset();
    }
}
