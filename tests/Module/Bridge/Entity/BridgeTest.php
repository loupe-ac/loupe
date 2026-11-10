<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Entity;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class BridgeTest extends TestCase
{
    /** @return iterable<string, array{list<string>|null, ?string, bool}> */
    public static function capabilities(): iterable
    {
        yield 'no report' => [null, null, false];
        yield 'commands only' => [[Bridge::CAPABILITY_COMMANDS], null, false];
        yield 'work requests, no need' => [[Bridge::CAPABILITY_WORK_REQUESTS], null, true];
        yield 'work requests, a missing need' => [[Bridge::CAPABILITY_WORK_REQUESTS], Bridge::CAPABILITY_INTERACTIVE, false];
        yield 'work requests and the need' => [[Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_INTERACTIVE], Bridge::CAPABILITY_INTERACTIVE, true];
        yield 'the need alone' => [[Bridge::CAPABILITY_INTERACTIVE], Bridge::CAPABILITY_INTERACTIVE, false];
    }

    /** @param list<string>|null $capabilities */
    #[DataProvider('capabilities')]
    public function test_a_bridge_runs_a_request_when_it_takes_work_requests_and_reports_the_need(?array $capabilities, ?string $need, bool $runs): void
    {
        $bridge = new Bridge(new User('Riley Chen', 'riley@example.com', 'x'), Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable());
        $bridge->capabilities = $capabilities;

        self::assertSame($runs, $bridge->canRun($this->request($need)));
        self::assertSame(null !== $capabilities && \in_array(Bridge::CAPABILITY_WORK_REQUESTS, $capabilities, true), $bridge->takesWorkRequests());
    }

    public function test_a_bridge_accepts_entries_only_when_it_reports_the_capability(): void
    {
        $bridge = new Bridge(new User('Riley Chen', 'riley@example.com', 'x'), Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable());

        self::assertFalse($bridge->acceptsEntries());

        $bridge->capabilities = [Bridge::CAPABILITY_RUN_COMMAND];
        self::assertFalse($bridge->acceptsEntries());

        $bridge->capabilities = [Bridge::CAPABILITY_RUN_COMMAND, Bridge::CAPABILITY_ADD_ENTRY];
        self::assertTrue($bridge->acceptsEntries());
    }

    /** @return iterable<string, array{list<string>, ?string, ?string, bool}> */
    public static function appPrompts(): iterable
    {
        $reports = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_APP_PROMPTS];

        yield 'a subject request with a prompt' => [$reports, 'subject-analysis', 'Run it.', true];
        yield 'a subject request with no prompt' => [$reports, 'subject-analysis', null, false];
        yield 'an interactive request with a prompt' => [$reports, Bridge::CAPABILITY_INTERACTIVE, 'Run it.', false];
        yield 'a subject request with a prompt, no app prompts' => [[Bridge::CAPABILITY_WORK_REQUESTS], 'subject-analysis', 'Run it.', false];
        yield 'app prompts without work requests' => [[Bridge::CAPABILITY_APP_PROMPTS], 'subject-analysis', 'Run it.', false];
    }

    /** @param list<string> $capabilities */
    #[DataProvider('appPrompts')]
    public function test_app_prompts_stand_in_for_a_subject_capability_only_when_the_request_carries_a_prompt(array $capabilities, ?string $need, ?string $prompt, bool $runs): void
    {
        $bridge = new Bridge(new User('Riley Chen', 'riley@example.com', 'x'), Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable());
        $bridge->capabilities = $capabilities;

        self::assertSame($runs, $bridge->canRun($this->request($need, $prompt)));
    }

    public function test_a_named_bridge_takes_its_name_as_its_label(): void
    {
        $bridge = $this->bridge('0199a3c4-0000-7000-8000-0123456789ab');
        $bridge->name = 'laptop';

        self::assertSame('laptop', $bridge->label);
    }

    public function test_a_bridge_with_no_name_takes_the_tail_of_its_id(): void
    {
        $bridge = $this->bridge('0199a3c4-0000-7000-8000-0123456789ab');
        $bridge->requestedName = 'laptop';

        self::assertSame('0123456789ab', $bridge->label);
    }

    public function test_a_string_id_gives_the_same_label_as_a_uuid(): void
    {
        self::assertSame('0123456789ab', Bridge::labelFor('0199A3C4-0000-7000-8000-0123456789AB', null));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function accountsForAKind(): iterable
    {
        yield 'the account that lists the kind' => ['work', 'implement', true];
        yield 'the app prompts account for a kind it lists' => ['prompts', 'review', true];
        yield 'the app prompts account for a kind another account lists' => ['prompts', 'implement', false];
        yield 'the app prompts account for an unmapped kind' => ['prompts', 'discovery', true];
        yield 'another account for an unmapped kind' => ['work', 'discovery', false];
        yield 'an unlisted account for an unmapped kind' => ['other', 'discovery', false];
        yield 'the app prompts account for a mapped kind that no account runs' => ['prompts', 'teardown', false];
    }

    #[DataProvider('accountsForAKind')]
    public function test_an_account_runs_the_kinds_it_lists_and_the_app_prompts_account_runs_the_unmapped_kinds(string $account, string $workKind, bool $runs): void
    {
        self::assertSame($runs, $this->bridgeWithAppPrompts()->accountRuns($account, $workKind));
    }

    public function test_a_bridge_with_an_app_prompts_account_runs_no_unmapped_kind_on_no_account(): void
    {
        $bridge = $this->bridgeWithAppPrompts();

        self::assertFalse($bridge->runsOnNoAccount('discovery'));
        self::assertTrue($bridge->runsOnNoAccount('teardown'));
    }

    public function test_a_bridge_with_no_app_prompts_account_runs_an_unmapped_kind_on_no_account(): void
    {
        $bridge = $this->bridgeWithAppPrompts(appPrompts: false);

        self::assertTrue($bridge->runsOnNoAccount('discovery'));
        self::assertFalse($bridge->accountRuns('prompts', 'discovery'));
    }

    public function test_a_failed_app_prompts_account_fails_the_project_for_an_unmapped_kind_alone(): void
    {
        $bridge = $this->bridgeWithAppPrompts(failedProjects: [['projectId' => 'p1', 'reason' => 'The login expired.']]);

        self::assertTrue($bridge->failsProjectFor('p1', 'discovery'));
        self::assertFalse($bridge->failsProjectFor('p1', 'implement'));
        self::assertFalse($bridge->failsProjectFor('p2', 'discovery'));
    }

    /** @param list<array{projectId: string, reason: string}> $failedProjects */
    private function bridgeWithAppPrompts(bool $appPrompts = true, array $failedProjects = []): Bridge
    {
        $bridge = $this->bridge('0199a3c4-0000-7000-8000-0123456789ab');
        $bridge->workKinds = ['implement', 'review', 'teardown'];
        $bridge->accounts = [
            ['name' => 'work', 'harness' => 'claude', 'state' => 'ready', 'reason' => null, 'kinds' => ['implement']],
            ['name' => 'prompts', 'harness' => 'claude', 'state' => 'ready', 'reason' => null, 'kinds' => ['review'], 'failedProjects' => $failedProjects, 'appPrompts' => $appPrompts],
        ];

        return $bridge;
    }

    private function request(?string $capability, ?string $prompt = null): WorkRequest
    {
        $request = new WorkRequest(
            project: $this->createStub(Project::class),
            subjectType: 'analysis',
            subjectId: Uuid::v7(),
            cardNumber: null,
            kind: 'analysis',
            capability: $capability,
            ruleId: 'insights.analysis',
            createdAt: new \DateTimeImmutable(),
        );
        $request->prompt = $prompt;

        return $request;
    }

    private function bridge(string $id): Bridge
    {
        return new Bridge(new User('Riley Chen', 'riley@example.com', 'x'), Uuid::fromString($id), [], 'b4e39aa7', new \DateTimeImmutable());
    }
}
