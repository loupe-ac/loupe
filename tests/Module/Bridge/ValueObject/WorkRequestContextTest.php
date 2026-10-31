<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\ValueObject;

use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Workflow\Template\Template;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkRequestContextTest extends TestCase
{
    public function test_a_context_round_trips_through_its_array(): void
    {
        $context = new WorkRequestContext(42, 'https://github.com/acme/widgets/pull/42', 'abc1234', 'checks-failed', '01a10beb-ba65-736b-8626-a6e3fa59dfc5', '01a13789-911f-7d8c-b2b8-e9b63c0ae283', 41, '01a137da-4de8-70ae-991f-2ef19584f2a6', 'epic/852');

        self::assertSame([
            'pullRequestNumber' => 42,
            'pullRequestUrl' => 'https://github.com/acme/widgets/pull/42',
            'headSha' => 'abc1234',
            'reason' => 'checks-failed',
            'documentId' => '01a10beb-ba65-736b-8626-a6e3fa59dfc5',
            'inboxActionId' => '01a13789-911f-7d8c-b2b8-e9b63c0ae283',
            'inboxItemNumber' => 41,
            'inboxItemId' => '01a137da-4de8-70ae-991f-2ef19584f2a6',
            'epicBranch' => 'epic/852',
        ], $context->toArray());
        self::assertEquals($context, WorkRequestContext::fromArray($context->toArray()));
    }

    public function test_an_empty_context_holds_a_null_for_each_key(): void
    {
        self::assertSame(
            ['pullRequestNumber' => null, 'pullRequestUrl' => null, 'headSha' => null, 'reason' => null, 'documentId' => null, 'inboxActionId' => null, 'inboxItemNumber' => null, 'inboxItemId' => null, 'epicBranch' => null],
            new WorkRequestContext()->toArray(),
        );
    }

    public function test_a_stored_row_with_the_five_old_keys_reads_the_inbox_keys_as_null(): void
    {
        $context = WorkRequestContext::fromArray(['pullRequestNumber' => 3, 'pullRequestUrl' => null, 'headSha' => null, 'reason' => null, 'documentId' => null]);

        self::assertSame([3, null, null, null], [$context->pullRequestNumber, $context->inboxActionId, $context->inboxItemNumber, $context->inboxItemId]);
    }

    public function test_a_stored_row_with_no_epic_branch_key_reads_it_as_null(): void
    {
        $context = WorkRequestContext::fromArray(['pullRequestNumber' => 3, 'pullRequestUrl' => null, 'headSha' => null, 'reason' => null, 'documentId' => null, 'inboxActionId' => null, 'inboxItemNumber' => null, 'inboxItemId' => null]);

        self::assertNull($context->epicBranch);
    }

    public function test_a_null_column_is_an_empty_context(): void
    {
        self::assertEquals(new WorkRequestContext(), WorkRequestContext::fromArray(null));
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function malformedArrays(): iterable
    {
        $valid = ['pullRequestNumber' => 1, 'pullRequestUrl' => null, 'headSha' => null, 'reason' => null, 'documentId' => null];

        yield 'a missing key' => [['pullRequestNumber' => 1, 'pullRequestUrl' => null, 'headSha' => null, 'reason' => null]];
        yield 'an unknown key' => [$valid + ['tag' => null]];
        yield 'a document id that is a number' => [['documentId' => 7] + $valid];
        yield 'a number that is a string' => [['pullRequestNumber' => '1'] + $valid];
        yield 'a url that is a number' => [['pullRequestUrl' => 5] + $valid];
        yield 'a sha that is a list' => [['headSha' => ['abc1234']] + $valid];
        yield 'a reason that is a bool' => [['reason' => true] + $valid];
        yield 'an inbox action id that is a number' => [['inboxActionId' => 7] + $valid];
        yield 'an inbox item number that is a string' => [['inboxItemNumber' => '7'] + $valid];
        yield 'an inbox item id that is a number' => [['inboxItemId' => 7] + $valid];
        yield 'an epic branch that is a number' => [['epicBranch' => 7] + $valid];
    }

    /** @param array<mixed> $data */
    #[DataProvider('malformedArrays')]
    public function test_a_malformed_array_is_refused(array $data): void
    {
        $this->expectException(\UnexpectedValueException::class);

        WorkRequestContext::fromArray($data);
    }

    /** @return iterable<string, array{?int, ?string, ?string, ?string, ?string, ?string, ?int, ?string}> */
    public static function invalidValues(): iterable
    {
        yield 'a zero number' => [0, null, null, null, null, null, null, null];
        yield 'a number past a 32-bit integer' => [2147483648, null, null, null, null, null, null, null];
        yield 'a url that is not https' => [null, 'http://github.com/acme/widgets/pull/1', null, null, null, null, null, null];
        yield 'a url with a space' => [null, 'https://github.com/acme/widgets/pull/1 now', null, null, null, null, null, null];
        yield 'a url with no host' => [null, 'https:///pull/1', null, null, null, null, null, null];
        yield 'a url past 2000 characters' => [null, 'https://github.com/'.str_repeat('a', 2000), null, null, null, null, null, null];
        yield 'a short sha' => [null, null, 'abc12', null, null, null, null, null];
        yield 'an upper-case sha' => [null, null, 'ABC1234', null, null, null, null, null];
        yield 'a reason with a space' => [null, null, null, 'checks failed', null, null, null, null];
        yield 'a document id that is not a uuid' => [null, null, null, null, 'design', null, null, null];
        yield 'an upper-case document id' => [null, null, null, null, '01A10BEB-BA65-736B-8626-A6E3FA59DFC5', null, null, null];
        yield 'an inbox action id that is not a uuid' => [null, null, null, null, null, 'action', null, null];
        yield 'an upper-case inbox action id' => [null, null, null, null, null, '01A13789-911F-7D8C-B2B8-E9B63C0AE283', null, null];
        yield 'a zero inbox item number' => [null, null, null, null, null, null, 0, null];
        yield 'an inbox item number past a 32-bit integer' => [null, null, null, null, null, null, 2147483648, null];
        yield 'an inbox item id that is not a uuid' => [null, null, null, null, null, null, null, 'item'];
        yield 'an upper-case inbox item id' => [null, null, null, null, null, null, null, '01A137DA-4DE8-70AE-991F-2EF19584F2A6'];
    }

    #[DataProvider('invalidValues')]
    public function test_an_invalid_value_is_refused(?int $number, ?string $url, ?string $sha, ?string $reason, ?string $documentId, ?string $inboxActionId, ?int $inboxItemNumber, ?string $inboxItemId): void
    {
        $this->expectException(\UnexpectedValueException::class);

        new WorkRequestContext($number, $url, $sha, $reason, $documentId, $inboxActionId, $inboxItemNumber, $inboxItemId);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedEpicBranches(): iterable
    {
        yield 'an empty name' => [''];
        yield 'a leading dash' => ['-epic/1'];
        yield 'a leading slash' => ['/epic/1'];
        yield 'a leading dot' => ['.epic/1'];
        yield 'a double dot' => ['epic/..1'];
        yield 'a double slash' => ['epic//1'];
        yield 'a segment that starts with a dot' => ['epic/.1'];
        yield 'a segment that starts with a dash' => ['epic/-1'];
        yield 'a trailing slash' => ['epic/1/'];
        yield 'a trailing dot' => ['epic/1.'];
        yield 'a lock suffix' => ['epic/1.lock'];
        yield 'a lock segment' => ['epic.lock/1'];
        yield 'a space' => ['epic /1'];
        yield 'a dollar sign' => ['epic/$(id)'];
        yield 'a name past the longest filled pattern' => [str_repeat('a', Template::EPIC_BRANCH_MAX_LENGTH + 11)];
    }

    #[DataProvider('refusedEpicBranches')]
    public function test_a_bad_epic_branch_is_refused(string $branch): void
    {
        $this->expectException(\UnexpectedValueException::class);

        new WorkRequestContext(epicBranch: $branch);
    }

    /** @return iterable<string, array{string}> */
    public static function epicBranchPatterns(): iterable
    {
        yield 'the default pattern' => ['epic/{number}'];
        yield 'a leading underscore' => ['_epic/{number}'];
        yield 'a leading number' => ['{number}-epic'];
        yield 'a nested path' => ['team/epics/e-{number}.x'];
        yield 'a dotted name' => ['release.v1/{number}'];
        yield 'the longest pattern' => [str_repeat('a', Template::EPIC_BRANCH_MAX_LENGTH - \strlen(Template::EPIC_BRANCH_NUMBER)).Template::EPIC_BRANCH_NUMBER];
    }

    #[DataProvider('epicBranchPatterns')]
    public function test_the_context_takes_every_name_a_template_pattern_fills(string $pattern): void
    {
        self::assertSame(1, preg_match(Template::EPIC_BRANCH_RULE, $pattern));
        $template = new Template('test', 1, [], [], [], [], 1, [], 'feature', epicBranch: $pattern);

        foreach ([1, 852, 2147483647] as $number) {
            $branch = $template->epicBranchOf($number);

            self::assertNotNull($branch);
            self::assertSame($branch, new WorkRequestContext(epicBranch: $branch)->epicBranch);
        }
    }

    public function test_the_length_limit_is_the_longest_pattern_plus_the_digits_of_the_largest_card_number(): void
    {
        self::assertSame(Template::EPIC_BRANCH_MAX_LENGTH + \strlen('2147483647'), WorkRequestContext::MAX_EPIC_BRANCH_LENGTH);
    }

    public function test_the_accepts_checks_tell_a_valid_value_from_an_invalid_one(): void
    {
        self::assertTrue(WorkRequestContext::acceptsUrl('https://github.com/acme/widgets/pull/1'));
        self::assertFalse(WorkRequestContext::acceptsUrl('javascript:alert(1)'));
        self::assertTrue(WorkRequestContext::acceptsHeadSha('0123456789abcdef'));
        self::assertFalse(WorkRequestContext::acceptsHeadSha('not-a-sha'));
    }
}
