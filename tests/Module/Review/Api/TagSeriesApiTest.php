<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Api;

use App\Tests\Support\ApiKeyCredential;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** The tag list, series list and series rename routes of the public document API. */
final class TagSeriesApiTest extends WebTestCase
{
    use DocumentApiFixtures;

    public function test_the_tag_list_counts_the_documents_and_walks_by_name(): void
    {
        $one = $this->document('One');
        $two = $this->document('Two');
        $this->tag($one, ['alpha', 'beta']);
        $this->tag($two, ['beta', 'gamma']);

        $this->send(Request::METHOD_GET, $this->tags().'?limit=2');
        self::assertResponseStatusCodeSame(200);
        $first = $this->json();
        self::assertSame([['name' => 'alpha', 'documentCount' => 1], ['name' => 'beta', 'documentCount' => 2]], $first['items']);
        self::assertIsString($first['next']);

        $this->send(Request::METHOD_GET, $first['next']);
        $second = $this->json();
        self::assertSame([['name' => 'gamma', 'documentCount' => 1]], $second['items']);
        self::assertNull($second['next']);
    }

    public function test_a_tag_that_no_document_carries_stays_listed_with_a_zero_count(): void
    {
        $document = $this->document('One');
        $this->tag($document, ['alpha']);
        $this->tag($document, []);

        $this->send(Request::METHOD_GET, $this->tags());

        self::assertSame([['name' => 'alpha', 'documentCount' => 0]], $this->json()['items']);
    }

    public function test_an_empty_vocabulary_is_an_empty_page(): void
    {
        $this->send(Request::METHOD_GET, $this->tags());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['items' => [], 'next' => null], $this->json());
    }

    public function test_the_series_list_gives_the_count_and_the_highest_ordinal_and_walks_by_name(): void
    {
        $this->place($this->document('A1'), 'Atomics', 1);
        $this->place($this->document('A2'), 'Atomics', 4);
        $this->place($this->document('B1'), 'Borrowing', 2);

        $this->send(Request::METHOD_GET, $this->series().'?limit=1');
        self::assertResponseStatusCodeSame(200);
        $first = $this->json();
        self::assertSame([['name' => 'Atomics', 'documentCount' => 2, 'highestOrdinal' => 4]], $first['items']);

        $this->send(Request::METHOD_GET, $first['next']);
        $second = $this->json();
        self::assertSame([['name' => 'Borrowing', 'documentCount' => 1, 'highestOrdinal' => 2]], $second['items']);
        self::assertNull($second['next']);
    }

    public function test_a_bad_cursor_and_a_bad_limit_are_refused(): void
    {
        $this->send(Request::METHOD_GET, $this->tags().'?cursor=nonsense');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_cursor', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->series().'?limit=201');
        self::assertResponseStatusCodeSame(422);
    }

    public function test_a_rename_keeps_the_documents_and_matches_the_name_ignoring_case(): void
    {
        $this->place($this->document('A1'), 'Rust Atomics', 3);

        $this->send(Request::METHOD_PATCH, $this->series().'/rust%20atomics', '{"name":"  Atomics in Rust "}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['name' => 'Atomics in Rust', 'documentCount' => 1, 'highestOrdinal' => 3], $this->json());
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));

        $this->send(Request::METHOD_GET, $this->series());
        self::assertSame(['Atomics in Rust'], array_column($this->json()['items'], 'name'));
    }

    public function test_a_series_name_with_a_slash_is_reached_by_its_encoded_name(): void
    {
        $this->place($this->document('A1'), 'Part 1/2', 1);

        $this->send(Request::METHOD_PATCH, $this->series().'/'.rawurlencode('Part 1/2'), '{"name":"Part one"}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Part one', $this->json()['name']);
    }

    public function test_a_rename_to_the_name_of_another_series_is_a_conflict(): void
    {
        $this->place($this->document('A1'), 'Atomics', 1);
        $this->place($this->document('B1'), 'Borrowing', 1);

        $this->send(Request::METHOD_PATCH, $this->series().'/Atomics', '{"name":"borrowing"}');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('series_name_taken', $this->json()['code']);
    }

    public function test_a_rename_with_a_blank_or_long_name_is_refused(): void
    {
        $this->place($this->document('A1'), 'Atomics', 1);

        foreach (['{}', '{"name":""}', '{"name":"   "}', '{"name":null}', '{"name":"'.str_repeat('x', 101).'"}'] as $body) {
            $this->send(Request::METHOD_PATCH, $this->series().'/Atomics', $body);
            self::assertResponseStatusCodeSame(422, $body);
        }
    }

    public function test_an_unknown_series_and_a_series_of_another_project_are_not_found(): void
    {
        $other = $this->makeProject($this->owner, 'Other shop');
        $this->place($this->document('X1', $other), 'Elsewhere', 1);

        foreach (['/Nothing', '/Elsewhere'] as $suffix) {
            $this->send(Request::METHOD_PATCH, $this->series().$suffix, '{"name":"New"}');
            self::assertResponseStatusCodeSame(404, $suffix);
            self::assertSame('series_not_found', $this->json()['code']);
        }
    }

    public function test_a_project_of_another_owner_is_not_found_and_no_token_is_refused(): void
    {
        $this->place($this->document('A1'), 'Atomics', 1);
        $stranger = $this->scenario->createUser('documents-api-stranger-'.uniqid().'@example.com');
        $strangerToken = ApiKeyCredential::mint(static::getContainer(), $stranger);

        foreach ([[Request::METHOD_PATCH, $this->series().'/Atomics', '{"name":"New"}'], [Request::METHOD_GET, $this->tags(), ''], [Request::METHOD_GET, $this->series(), '']] as [$method, $url, $body]) {
            $this->send($method, $url, $body, [], $strangerToken);
            self::assertResponseStatusCodeSame(404, $url);
            self::assertSame('project_not_found', $this->json()['code']);
        }

        $this->send(Request::METHOD_GET, $this->tags(), '', [], null);
        self::assertResponseStatusCodeSame(401);
    }

    private function tags(): string
    {
        return '/api/v1/projects/'.$this->project->id.'/tags';
    }

    private function series(): string
    {
        return '/api/v1/projects/'.$this->project->id.'/series';
    }
}
