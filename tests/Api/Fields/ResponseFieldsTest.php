<?php

declare(strict_types=1);

namespace App\Tests\Api\Fields;

use App\Module\Artifact\Controller\Api\ArtifactResponse;
use App\Module\Artifact\Controller\Api\ArtifactRowResponse;
use App\Module\Board\Controller\Api\BoardColumnResponse;
use App\Module\Board\Controller\Api\CardEventResponse;
use App\Module\Board\Controller\Api\FeedbackResponse;
use App\Module\Board\Controller\Api\FeedbackRowResponse;
use App\Module\Project\Controller\Api\ProjectResponse;
use App\Module\Review\Controller\Api\DocumentResponse;
use App\Module\Review\Controller\Api\DocumentRowResponse;
use App\Module\Review\Controller\Api\DocumentVersionResponse;
use App\Module\Review\Controller\Api\DocumentVersionRowResponse;
use App\Module\Review\Controller\Api\ReviewResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The FIELDS of each response class are the properties of its whole value, and each row class is that value cut. */
final class ResponseFieldsTest extends TestCase
{
    /** @return iterable<string, array{class-string, list<string>}> */
    public static function wholeClasses(): iterable
    {
        yield 'project' => [ProjectResponse::class, ProjectResponse::FIELDS];
        yield 'column' => [BoardColumnResponse::class, BoardColumnResponse::FIELDS];
        yield 'card event' => [CardEventResponse::class, CardEventResponse::FIELDS];
        yield 'feedback' => [FeedbackResponse::class, FeedbackResponse::FIELDS];
        yield 'document' => [DocumentResponse::class, DocumentResponse::FIELDS];
        yield 'document version' => [DocumentVersionResponse::class, DocumentVersionResponse::FIELDS];
        yield 'review' => [ReviewResponse::class, ReviewResponse::FIELDS];
        yield 'artifact' => [ArtifactResponse::class, ArtifactResponse::FIELDS];
    }

    /**
     * @param class-string $class
     * @param list<string> $fields
     */
    #[DataProvider('wholeClasses')]
    public function test_the_names_are_the_properties_in_order(string $class, array $fields): void
    {
        self::assertSame($fields, self::propertiesOf($class));
    }

    /** @return iterable<string, array{class-string, list<string>}> */
    public static function rowClasses(): iterable
    {
        yield 'document' => [DocumentRowResponse::class, array_values(array_diff(DocumentResponse::FIELDS, ['markdown']))];
        yield 'document version' => [DocumentVersionRowResponse::class, array_values(array_diff(DocumentVersionResponse::FIELDS, ['markdown']))];
        yield 'feedback' => [FeedbackRowResponse::class, array_values(array_diff(FeedbackResponse::FIELDS, FeedbackResponse::LEFT_OUT_OF_ROW))];
        yield 'artifact' => [ArtifactRowResponse::class, array_values(array_diff(ArtifactResponse::FIELDS, ['pageLink']))];
    }

    /**
     * @param class-string $class
     * @param list<string> $names
     */
    #[DataProvider('rowClasses')]
    public function test_a_row_is_its_whole_value_without_the_names_it_leaves_out(string $class, array $names): void
    {
        self::assertSame($names, self::propertiesOf($class));
    }

    public function test_the_lists_refuse_the_markdown_and_all(): void
    {
        self::assertSame(['markdown', 'all'], DocumentResponse::REFUSED_IN_LIST);
        self::assertSame(['markdown', 'all'], DocumentVersionResponse::REFUSED_IN_LIST);
        self::assertSame([], array_diff(DocumentResponse::REFUSED_IN_LIST, [...DocumentResponse::FIELDS, 'all']));
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private static function propertiesOf(string $class): array
    {
        return array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            new \ReflectionMethod($class, '__construct')->getParameters(),
        );
    }
}
