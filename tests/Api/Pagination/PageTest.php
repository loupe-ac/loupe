<?php

declare(strict_types=1);

namespace App\Tests\Api\Pagination;

use App\Api\Pagination\Cursor;
use App\Api\Pagination\Page;
use App\Api\Pagination\PageRequest;
use App\Api\Problem\ApiProblemException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PageTest extends TestCase
{
    public function test_a_short_list_has_no_next_page(): void
    {
        $page = Page::fromRows([1, 2], new PageRequest(2, null), static fn (int $row): Cursor => new Cursor('number', (string) $row, (string) $row));

        self::assertSame([1, 2], $page->items);
        self::assertNull($page->next);
    }

    public function test_an_extra_row_cuts_the_page_and_names_the_next_cursor_after_the_last_kept_row(): void
    {
        $page = Page::fromRows([1, 2, 3], new PageRequest(2, null), static fn (int $row): Cursor => new Cursor('number', (string) $row, 'id'.$row));

        self::assertSame([1, 2], $page->items);
        self::assertSame('2', $page->next?->sortValue);
    }

    public function test_it_answers_items_next_and_a_link_header(): void
    {
        $request = Request::create('https://loupe.test/api/v1/projects/x/cards?limit=2&column=next');
        $page = new Page([['number' => 1], ['number' => 2]], new Cursor('number', '2', 'b'));

        $response = $page->toResponse($request, static fn (array $item): array => $item);
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($body);
        self::assertCount(2, $body['items']);
        self::assertStringStartsWith('https://loupe.test/api/v1/projects/x/cards?limit=2&column=next&cursor=', $body['next']);
        self::assertSame(sprintf('<%s>; rel="next"', $body['next']), $response->headers->get('Link'));
    }

    public function test_the_last_page_has_a_null_next_and_no_link_header(): void
    {
        $response = new Page([], null)->toResponse(Request::create('https://loupe.test/api/v1/projects'), static fn (mixed $item): mixed => $item);

        self::assertSame('{"items":[],"next":null}', $response->getContent());
        self::assertFalse($response->headers->has('Link'));
    }

    public function test_the_default_limit_is_fifty(): void
    {
        $request = PageRequest::fromRequest(Request::create('/x'), 'number');

        self::assertSame(50, $request->limit);
        self::assertSame(51, $request->fetchSize());
        self::assertNull($request->cursor);
    }

    #[TestWith(['0'])]
    #[TestWith(['201'])]
    #[TestWith(['-5'])]
    #[TestWith(['many'])]
    public function test_a_limit_outside_one_to_two_hundred_is_a_validation_problem(string $limit): void
    {
        try {
            PageRequest::fromRequest(Request::create('/x', parameters: ['limit' => $limit]), 'number');
            self::fail('The limit was accepted.');
        } catch (ApiProblemException $exception) {
            self::assertSame(422, $exception->problem->status);
            self::assertSame('limit', $exception->problem->errors[0]['field']);
        }
    }

    public function test_it_reads_the_cursor_against_the_filters_of_the_request(): void
    {
        $token = new Cursor('number', '5', 'x', ['column' => 'next'])->encode();

        $request = PageRequest::fromRequest(Request::create('/x', parameters: ['cursor' => $token, 'limit' => '200']), 'number', ['column' => 'next']);

        self::assertSame(200, $request->limit);
        self::assertSame('5', $request->cursor?->sortValue);
    }
}
