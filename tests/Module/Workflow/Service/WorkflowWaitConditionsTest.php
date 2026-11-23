<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Service\BoardPullRequestUrls;
use App\Module\Board\Service\PullRequestUrlResolver;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Workflow\Condition\Conditions;
use App\Module\Workflow\Contract\CardDirectory;
use App\Module\Workflow\Contract\WaitConditionCheck;
use App\Module\Workflow\Contract\WaitConditionRefusal;
use App\Module\Workflow\Contract\WaitSettings;
use App\Module\Workflow\Service\FactsBuilder;
use App\Module\Workflow\Service\ProjectTemplates;
use App\Module\Workflow\Service\WorkflowWaitConditions;
use App\Module\Workflow\Template\TemplateParser;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class WorkflowWaitConditionsTest extends KernelTestCase
{
    use ActionScenario;

    public function test_the_settings_of_a_project_are_the_waits_of_its_template(): void
    {
        $card = $this->lifecycleCard();

        self::assertEquals(new WaitSettings(120, 10), $this->waits()->settingsOf($card->project->requireId()));
    }

    public function test_a_condition_that_is_neither_in_the_registry_nor_a_marker_is_refused(): void
    {
        $card = $this->lifecycleCard();

        $check = $this->check($card, 'pr.unheard_of');

        self::assertSame(WaitConditionRefusal::UnknownCondition, $check->refusal);
        self::assertStringContainsString('pr.unheard_of', $check->message);
    }

    public function test_parameters_that_the_condition_does_not_accept_are_refused(): void
    {
        $card = $this->lifecycleCard();
        $this->pullRequest($card);

        $check = $this->check($card, 'pr.checks_passed', ['branch' => 'main']);

        self::assertSame(WaitConditionRefusal::InvalidParameters, $check->refusal);
        self::assertSame('condition: pr.checks_passed: unknown parameter "branch"', $check->message);
    }

    public function test_a_marker_key_is_tested_through_the_marker_condition(): void
    {
        $card = $this->lifecycleCard();

        $check = $this->check($card, 'paused');

        self::assertNull($check->refusal);
        self::assertFalse($check->isTrue);
        self::assertNull($check->pullRequestId);
        self::assertNull($check->pullRequestToken);
    }

    public function test_a_marker_takes_no_parameters(): void
    {
        $card = $this->lifecycleCard();

        $check = $this->check($card, 'paused', ['blocking' => true]);

        self::assertSame(WaitConditionRefusal::InvalidParameters, $check->refusal);
        self::assertStringContainsString('paused', $check->message);
    }

    public function test_the_one_open_pull_request_is_bound_when_the_worker_names_none(): void
    {
        $card = $this->lifecycleCard();
        $pullRequest = $this->pullRequest($card);

        $check = $this->check($card, 'pr.checks_passed');

        self::assertNull($check->refusal);
        self::assertFalse($check->isTrue);
        self::assertSame($pullRequest->id?->toRfc4122(), $check->pullRequestId?->toRfc4122());
        self::assertSame('pr:acme/widgets#'.$pullRequest->number, $check->pullRequestToken);
    }

    public function test_a_condition_that_is_true_already_says_so(): void
    {
        $card = $this->lifecycleCard();
        $pullRequest = $this->pullRequest($card);
        $pullRequest->checks = PullRequestChecks::Passed;
        $this->em()->flush();

        $check = $this->check($card, 'pr.checks_passed');

        self::assertNull($check->refusal);
        self::assertTrue($check->isTrue);
    }

    public function test_several_open_pull_requests_and_no_url_are_refused_with_their_tokens(): void
    {
        $card = $this->lifecycleCard();
        $first = $this->pullRequest($card);
        $second = $this->pullRequest($card);

        $check = $this->check($card, 'pr.checks_passed');

        self::assertSame(WaitConditionRefusal::PullRequestRequired, $check->refusal);
        self::assertNull($check->pullRequestId);
        self::assertStringContainsString('pr:acme/widgets#'.$first->number, $check->message);
        self::assertStringContainsString('pr:acme/widgets#'.$second->number, $check->message);
    }

    public function test_a_card_with_no_open_pull_request_and_no_url_is_refused(): void
    {
        $card = $this->lifecycleCard();
        $this->pullRequest($card, PullRequestState::Merged);

        $check = $this->check($card, 'pr.checks_passed');

        self::assertSame(WaitConditionRefusal::PullRequestRequired, $check->refusal);
        self::assertStringContainsString('no open pull request', $check->message);
    }

    public function test_the_url_picks_one_of_several_open_pull_requests(): void
    {
        $card = $this->lifecycleCard();
        $this->pullRequest($card);
        $second = $this->pullRequest($card);
        $second->checks = PullRequestChecks::Passed;
        $this->em()->flush();

        $check = $this->check($card, 'pr.checks_passed', url: 'https://github.com/acme/widgets/pull/'.$second->number);

        self::assertNull($check->refusal);
        self::assertTrue($check->isTrue);
        self::assertSame($second->id?->toRfc4122(), $check->pullRequestId?->toRfc4122());
    }

    public function test_the_url_matches_the_repository_whatever_its_case(): void
    {
        $card = $this->lifecycleCard();
        $pullRequest = $this->pullRequest($card);

        $check = $this->check($card, 'pr.checks_passed', url: 'https://github.com/ACME/Widgets/pull/'.$pullRequest->number);

        self::assertNull($check->refusal);
        self::assertSame($pullRequest->id?->toRfc4122(), $check->pullRequestId?->toRfc4122());
    }

    public function test_a_url_that_the_card_does_not_link_is_refused(): void
    {
        $card = $this->lifecycleCard();
        $this->pullRequest($card);

        $check = $this->check($card, 'pr.checks_passed', url: 'https://github.com/acme/widgets/pull/9999');

        self::assertSame(WaitConditionRefusal::PullRequestNotLinked, $check->refusal);
        self::assertNull($check->pullRequestId);
    }

    public function test_a_url_that_names_no_pull_request_is_refused_as_not_linked(): void
    {
        $card = $this->lifecycleCard();
        $this->pullRequest($card);

        $check = $this->check($card, 'pr.checks_passed', url: 'https://example.org/not-a-pull-request');

        self::assertSame(WaitConditionRefusal::PullRequestNotLinked, $check->refusal);
    }

    public function test_a_url_that_names_a_closed_pull_request_is_refused(): void
    {
        $card = $this->lifecycleCard();
        $open = $this->pullRequest($card);
        $closed = $this->pullRequest($card, PullRequestState::Closed);

        $check = $this->check($card, 'pr.checks_passed', url: 'https://github.com/acme/widgets/pull/'.$closed->number);

        self::assertSame(WaitConditionRefusal::PullRequestNotOpen, $check->refusal);
        self::assertStringContainsString('pr:acme/widgets#'.$closed->number, $check->message);
        self::assertNotSame($open->number, $closed->number);
    }

    public function test_a_condition_that_reads_no_pull_request_ignores_the_url(): void
    {
        $card = $this->lifecycleCard();

        $check = $this->check($card, 'card.paused', url: 'https://example.org/not-a-pull-request');

        self::assertNull($check->refusal);
        self::assertNull($check->pullRequestId);
        self::assertNull($check->pullRequestToken);
    }

    public function test_an_unknown_card_is_a_logic_error(): void
    {
        $this->lifecycleCard();

        $this->expectException(\LogicException::class);

        $this->waits()->check(Uuid::v7(), 'card.paused', [], null);
    }

    private function waits(): WorkflowWaitConditions
    {
        return new WorkflowWaitConditions(
            $this->service(CardDirectory::class),
            $this->service(ProjectTemplates::class),
            $this->service(Conditions::class),
            $this->service(TemplateParser::class),
            $this->service(FactsBuilder::class),
            new BoardPullRequestUrls($this->service(PullRequestUrlResolver::class)),
            new MockClock(),
        );
    }

    /** @param array<string, mixed> $params */
    private function check(Card $card, string $key, array $params = [], ?string $url = null): WaitConditionCheck
    {
        return $this->waits()->check($card->id ?? throw new \LogicException('The card is persisted.'), $key, $params, $url);
    }

    private function lifecycleCard(): Card
    {
        self::bootKernel();
        $project = $this->workflowProject('wait-conditions');
        $this->bindLifecycle($project);

        return $this->card($project, 'in-progress');
    }
}
