<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\View;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\View\ProjectCursor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ProjectCursorTest extends TestCase
{
    public function test_a_cursor_survives_a_round_trip(): void
    {
        $id = Uuid::v7();
        $cursor = ProjectCursor::parse('2026-11-14T10:00:00+00:00|'.$id->toRfc4122());

        self::assertEquals(new \DateTimeImmutable('2026-11-14T10:00:00+00:00'), $cursor->createdAt);
        self::assertEquals($id, $cursor->id);
        self::assertSame('2026-11-14T10:00:00+00:00|'.$id->toRfc4122(), $cursor->toString());
    }

    public function test_no_project_gives_the_empty_cursor(): void
    {
        $cursor = ProjectCursor::of(null);

        self::assertNull($cursor->createdAt);
        self::assertSame('', $cursor->toString());
    }

    /** @param non-empty-string|string $value */
    #[\PHPUnit\Framework\Attributes\DataProvider('garbage')]
    public function test_a_value_that_does_not_parse_gives_the_empty_cursor(string $value): void
    {
        self::assertSame('', ProjectCursor::parse($value)->toString());
    }

    /** @return iterable<string, array{string}> */
    public static function garbage(): iterable
    {
        yield 'empty' => [''];
        yield 'no id' => ['2026-11-14T10:00:00+00:00'];
        yield 'bad id' => ['2026-11-14T10:00:00+00:00|nope'];
        yield 'bad time' => ['not a time|0197c8a0-0000-7000-8000-000000000000'];
    }

    public function test_it_reads_the_newest_project(): void
    {
        $project = new Project(new User(fullName: 'R', email: 'cursor@example.com', password: 'x'), 'cursor');

        // A project that is not stored has no id, so its cursor is empty.
        self::assertSame('', ProjectCursor::of($project)->toString());
    }
}
