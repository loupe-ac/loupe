<?php

declare(strict_types=1);

namespace App\Tests\Api\OpenApi;

use App\Module\Artifact\Controller\Api\ArtifactResponse;
use App\Module\Board\Controller\Api\BoardColumnResponse;
use App\Module\Board\Controller\Api\CardEventResponse;
use App\Module\Board\Controller\Api\CardResponse;
use App\Module\Board\Controller\Api\FeedbackResponse;
use App\Module\Project\Controller\Api\ProjectResponse;
use App\Module\Review\Controller\Api\DocumentResponse;
use App\Module\Review\Controller\Api\DocumentVersionResponse;
use App\Module\Review\Controller\Api\ReviewResponse;
use App\Tests\Support\PublicSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The committed public spec publishes each shape that a read can answer: the whole resource, the light row and the named fields. */
final class FieldsSchemasTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function resources(): iterable
    {
        yield 'project' => ['Project', ProjectResponse::FIELDS];
        yield 'column' => ['BoardColumn', BoardColumnResponse::FIELDS];
        yield 'card' => ['Card', CardResponse::FIELDS];
        yield 'card event' => ['CardEvent', CardEventResponse::FIELDS];
        yield 'feedback' => ['FeedbackItem', FeedbackResponse::FIELDS];
        yield 'document' => ['Document', DocumentResponse::FIELDS];
        yield 'document version' => ['DocumentVersion', DocumentVersionResponse::FIELDS];
        yield 'review' => ['DocumentReview', ReviewResponse::FIELDS];
        yield 'artifact' => ['Artifact', ArtifactResponse::FIELDS];
    }

    /** @param list<string> $fields */
    #[DataProvider('resources')]
    public function test_the_fields_constant_names_the_properties_of_the_whole_component_in_order(string $component, array $fields): void
    {
        $schema = PublicSpec::schema($component);

        self::assertSame($fields, array_keys($schema['properties']));
        self::assertSame($fields, $schema['required']);
    }

    /** @param list<string> $fields */
    #[DataProvider('resources')]
    public function test_the_partial_component_holds_the_properties_of_the_whole_and_requires_none(string $component, array $fields): void
    {
        $whole = PublicSpec::schema($component);
        $partial = PublicSpec::schema('Partial'.$component);

        self::assertSame($fields, array_keys($partial['properties']));
        self::assertEquals($whole['properties'], $partial['properties']);
        self::assertArrayNotHasKey('required', $partial);
        self::assertArrayNotHasKey('x-fields-partial', $partial);
        self::assertArrayNotHasKey('x-fields-partial', $whole);
    }

    /** @return iterable<string, array{string, string, list<string>, list<string>}> */
    public static function rows(): iterable
    {
        yield 'card' => ['CardRow', 'Card', ['body'], ['pullRequests', 'siteReviewComments']];
        yield 'feedback' => ['FeedbackRow', 'FeedbackItem', FeedbackResponse::LEFT_OUT_OF_ROW, []];
        yield 'artifact' => ['ArtifactRow', 'Artifact', ['pageLink'], ['versions', 'files']];
        yield 'document' => ['DocumentRow', 'Document', ['markdown'], []];
        yield 'document version' => ['DocumentVersionRow', 'DocumentVersion', ['markdown'], []];
    }

    /**
     * @param list<string> $leftOut the names the row leaves out
     * @param list<string> $refs    the names the row holds as a short form
     */
    #[DataProvider('rows')]
    public function test_a_row_requires_the_whole_value_without_the_names_it_leaves_out(string $row, string $whole, array $leftOut, array $refs): void
    {
        $rowSchema = PublicSpec::schema($row);
        $wholeSchema = PublicSpec::schema($whole);
        $names = array_values(array_diff(array_keys($wholeSchema['properties']), $leftOut));

        self::assertSame($names, array_keys($rowSchema['properties']));
        self::assertSame($names, $rowSchema['required']);
        foreach (array_diff($names, $refs) as $name) {
            self::assertEquals(self::withoutProse($wholeSchema['properties'][$name]), self::withoutProse($rowSchema['properties'][$name]), $row.' '.$name);
        }
        foreach ($refs as $name) {
            self::assertNotEquals(self::withoutProse($wholeSchema['properties'][$name]), self::withoutProse($rowSchema['properties'][$name]), $row.' '.$name);
        }
    }

    public function test_a_ref_component_is_a_short_form_of_the_whole_value(): void
    {
        self::assertSame(['pullRequestId', 'url', 'forge', 'repository', 'number'], PublicSpec::schema('PullRequestRef')['required']);
        self::assertSame(['id', 'status', 'url'], PublicSpec::schema('FeedbackRef')['required']);
        self::assertSame(['number', 'storedAt'], PublicSpec::schema('ArtifactVersionRef')['required']);
        self::assertSame(['path'], PublicSpec::schema('ArtifactFileRef')['required']);
        self::assertSame('#/components/schemas/PullRequestRef', PublicSpec::schema('CardRow')['properties']['pullRequests']['items']['$ref']);
        self::assertSame('#/components/schemas/FeedbackRef', PublicSpec::schema('CardRow')['properties']['siteReviewComments']['items']['$ref']);
        self::assertSame('#/components/schemas/ArtifactVersionRef', PublicSpec::schema('ArtifactRow')['properties']['versions']['items']['$ref']);
        self::assertSame('#/components/schemas/ArtifactFileRef', PublicSpec::schema('ArtifactRow')['properties']['files']['items']['$ref']);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function lists(): iterable
    {
        yield 'listCards' => ['CardList', ['CardRow', 'Card', 'PartialCard']];
        yield 'listFeedback' => ['FeedbackList', ['FeedbackRow', 'FeedbackItem', 'PartialFeedbackItem']];
        yield 'listArtifacts' => ['ArtifactList', ['ArtifactRow', 'Artifact', 'PartialArtifact']];
        yield 'listDocuments' => ['DocumentList', ['DocumentRow', 'PartialDocument']];
        yield 'listDocumentVersions' => ['DocumentVersionList', ['DocumentVersionRow', 'PartialDocumentVersion']];
        yield 'listProjects' => ['ProjectList', ['Project', 'PartialProject']];
        yield 'listBoardColumns' => ['BoardColumnList', ['BoardColumn', 'PartialBoardColumn']];
        yield 'listCardEvents' => ['CardEventList', ['CardEvent', 'PartialCardEvent']];
    }

    /** @param list<string> $shapes */
    #[DataProvider('lists')]
    public function test_a_list_publishes_each_shape_of_its_items_and_keeps_items_and_next_required(string $list, array $shapes): void
    {
        $schema = PublicSpec::schema($list);

        self::assertContains('items', $schema['required']);
        self::assertContains('next', $schema['required']);
        self::assertSame(
            array_map(static fn (string $shape): string => '#/components/schemas/'.$shape, $shapes),
            array_column($schema['properties']['items']['items']['anyOf'], '$ref'),
        );
        self::assertArrayNotHasKey('oneOf', $schema['properties']['items']['items']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function singleReads(): iterable
    {
        yield 'getProject' => ['/api/v1/projects/{project}', 'Project'];
        yield 'getCard' => ['/api/v1/projects/{project}/cards/{number}', 'Card'];
        yield 'getFeedback' => ['/api/v1/projects/{project}/feedback/{feedback}', 'FeedbackItem'];
        yield 'getDocument' => ['/api/v1/projects/{project}/documents/{document}', 'Document'];
        yield 'getDocumentVersion' => ['/api/v1/projects/{project}/documents/{document}/versions/{version}', 'DocumentVersion'];
        yield 'getDocumentReview' => ['/api/v1/projects/{project}/documents/{document}/review', 'DocumentReview'];
        yield 'getArtifact' => ['/api/v1/projects/{project}/artifacts/{artifact}', 'Artifact'];
    }

    #[DataProvider('singleReads')]
    public function test_a_single_read_answers_the_whole_resource_or_the_named_fields(string $path, string $component): void
    {
        $spec = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/openapi/public.json'), true, flags: \JSON_THROW_ON_ERROR);
        $operation = $spec['paths'][$path]['get'];
        $schema = $operation['responses']['200']['content']['application/json']['schema'];

        self::assertSame(
            ['#/components/schemas/'.$component, '#/components/schemas/Partial'.$component],
            array_column($schema['anyOf'], '$ref'),
            $operation['operationId'],
        );
        self::assertArrayHasKey('422', $operation['responses'], $operation['operationId']);
    }

    public function test_a_write_that_shares_a_resource_keeps_the_plain_schema(): void
    {
        $spec = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/openapi/public.json'), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('#/components/schemas/Card', $spec['paths']['/api/v1/projects/{project}/cards']['post']['responses']['201']['content']['application/json']['schema']['$ref']);
        self::assertSame('#/components/schemas/Card', $spec['paths']['/api/v1/projects/{project}/cards/{number}']['patch']['responses']['200']['content']['application/json']['schema']['$ref']);
    }

    public function test_the_two_document_lists_leave_the_markdown_and_all_out_of_the_fields_enum(): void
    {
        $spec = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/openapi/public.json'), true, flags: \JSON_THROW_ON_ERROR);

        $enums = [];
        foreach (['/api/v1/projects/{project}/documents' => 'listDocuments', '/api/v1/projects/{project}/documents/{document}/versions' => 'listDocumentVersions'] as $path => $id) {
            $operation = $spec['paths'][$path]['get'];
            self::assertSame($id, $operation['operationId']);
            $parameter = array_values(array_filter($operation['parameters'], static fn (array $parameter): bool => 'fields' === ($parameter['name'] ?? null)))[0];
            self::assertSame('form', $parameter['style']);
            self::assertFalse($parameter['explode']);
            self::assertNotContains('markdown', $parameter['schema']['items']['enum']);
            self::assertNotContains('all', $parameter['schema']['items']['enum']);
            self::assertStringContainsString('fields_refused', $parameter['description']);
            self::assertStringContainsString('`fields_refused`', json_encode($operation['responses']['422'], \JSON_THROW_ON_ERROR));
            $enums[] = $parameter['schema']['items']['enum'];
        }

        self::assertContains('references', $enums[0]);
        self::assertContains('threadCount', $enums[1]);
    }

    public function test_a_wrong_type_fails_the_partial_and_the_row(): void
    {
        PublicSpec::assertMatches('PartialCard', ['number' => 1, 'title' => 'Plan']);
        PublicSpec::assertDoesNotMatch('PartialCard', ['title' => 5]);
        PublicSpec::assertDoesNotMatch('CardRow', ['title' => 'Plan']);
        PublicSpec::assertDoesNotMatch('CardList', ['items' => [['title' => 5]], 'next' => null]);
        PublicSpec::assertMatches('CardList', ['items' => [['number' => 1], ['title' => 'x']], 'next' => null]);
        PublicSpec::assertDoesNotMatch('CardList', ['items' => []]);
    }

    /**
     * A description is prose for a reader, and a row may word it for itself.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function withoutProse(array $schema): array
    {
        unset($schema['description']);

        return array_map(static fn (mixed $value): mixed => \is_array($value) ? self::withoutProse($value) : $value, $schema);
    }
}
