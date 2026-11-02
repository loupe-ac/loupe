<?php

declare(strict_types=1);

namespace App\Tests\Api\Fields;

use App\Api\Fields\FieldSelection;
use App\Api\Problem\ApiProblemException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class FieldSelectionTest extends TestCase
{
    private const array ALLOWED = ['cardId', 'number', 'title', 'body'];

    public function test_a_request_with_no_fields_is_the_default(): void
    {
        $selection = FieldSelection::fromRequest(Request::create('/x'), self::ALLOWED);

        self::assertTrue($selection->isDefault());
        self::assertFalse($selection->isAll());
        self::assertFalse($selection->has('title'));
        self::assertSame(['number' => 1], $selection->keep(['number' => 1]));
    }

    public function test_all_stands_for_the_whole_resource(): void
    {
        $selection = FieldSelection::fromRequest(Request::create('/x?fields=all'), self::ALLOWED);

        self::assertFalse($selection->isDefault());
        self::assertTrue($selection->isAll());
        self::assertTrue($selection->has('body'));
        self::assertSame(['number' => 1, 'title' => 'T'], $selection->keep(['number' => 1, 'title' => 'T']));
    }

    public function test_named_fields_keep_the_order_of_the_row_and_count_once(): void
    {
        $selection = FieldSelection::fromRequest(Request::create('/x?fields=title,number,title'), self::ALLOWED);

        self::assertFalse($selection->isDefault());
        self::assertFalse($selection->isAll());
        self::assertSame(['number', 'title'], $selection->names);
        self::assertTrue($selection->has('number'));
        self::assertFalse($selection->has('body'));
        self::assertSame(
            ['number' => 1, 'title' => 'T'],
            $selection->keep(['cardId' => 'c', 'number' => 1, 'title' => 'T', 'body' => 'B']),
        );
        self::assertSame(['number', 'title'], array_keys($selection->keep(['cardId' => 'c', 'number' => 1, 'title' => 'T'])));
    }

    public function test_a_row_holds_exactly_the_named_fields_and_no_id(): void
    {
        $selection = FieldSelection::fromRequest(Request::create('/x?fields=title'), self::ALLOWED);

        self::assertSame(['title' => 'T'], $selection->keep(['cardId' => 'c', 'number' => 1, 'title' => 'T']));
    }

    public function test_an_unknown_name_gets_one_error_that_lists_the_allowed_names(): void
    {
        $errors = $this->errorsOf('/x?fields=title,nope,state.kind,nope');

        self::assertSame(['fields_invalid', 'fields_invalid'], array_column($errors, 'code'));
        self::assertSame(['fields', 'fields'], array_column($errors, 'field'));
        self::assertStringContainsString('nope', $errors[0]['message']);
        self::assertStringContainsString('state.kind', $errors[1]['message']);
        self::assertStringContainsString('cardId, number, title, body, all', $errors[0]['message']);
    }

    #[TestWith(['/x?fields='])]
    #[TestWith(['/x?fields=title,'])]
    #[TestWith(['/x?fields=,title'])]
    #[TestWith(['/x?fields=title,,number'])]
    public function test_an_empty_value_or_name_is_refused(string $uri): void
    {
        $errors = $this->errorsOf($uri);

        self::assertCount(1, $errors);
        self::assertSame('fields_invalid', $errors[0]['code']);
    }

    #[TestWith(['/x?fields[]=title&fields[]=number'])]
    #[TestWith(['/x?fields[a]=title'])]
    public function test_a_repeated_parameter_is_refused(string $uri): void
    {
        $errors = $this->errorsOf($uri);

        self::assertSame(['fields_invalid'], array_column($errors, 'code'));
    }

    public function test_all_cannot_join_another_name(): void
    {
        $errors = $this->errorsOf('/x?fields=all,title');

        self::assertSame(['fields_invalid'], array_column($errors, 'code'));
        self::assertStringContainsString('all', $errors[0]['message']);
    }

    public function test_a_refused_name_has_its_own_code(): void
    {
        $errors = $this->errorsOf('/x?fields=title,body,nope', ['body', 'all']);

        self::assertSame(['fields_refused', 'fields_invalid'], array_column($errors, 'code'));
        self::assertStringContainsString('body', $errors[0]['message']);
        self::assertStringContainsString('one', $errors[0]['message']);
        self::assertStringNotContainsString('body', $errors[1]['message']);
        self::assertStringContainsString('cardId, number, title', $errors[1]['message']);
    }

    public function test_all_is_refused_when_the_list_refuses_it(): void
    {
        $errors = $this->errorsOf('/x?fields=all', ['body', 'all']);

        self::assertSame(['fields_refused'], array_column($errors, 'code'));
    }

    public function test_a_refused_name_the_request_leaves_out_changes_nothing(): void
    {
        $selection = FieldSelection::fromRequest(Request::create('/x?fields=title'), self::ALLOWED, ['body', 'all']);

        self::assertSame(['title'], $selection->names);
    }

    /**
     * @param list<string> $refused
     *
     * @return list<array{field: string, code: string, message: string}>
     */
    private function errorsOf(string $uri, array $refused = []): array
    {
        try {
            FieldSelection::fromRequest(Request::create($uri), self::ALLOWED, $refused);
        } catch (ApiProblemException $e) {
            self::assertSame(422, $e->problem->status);
            self::assertSame('validation_failed', $e->problem->code);

            return $e->problem->errors;
        }
        self::fail('The request must be refused.');
    }
}
