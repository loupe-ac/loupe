<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Action;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardPullRequests;
use App\Module\Board\Workflow\WorkRequestContexts;
use App\Module\Board\Workflow\WorkRequestOpener;
use App\Module\Bridge\Command\OpenWorkRequestHandler;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\ActionOutcomeKind;
use App\Module\Workflow\Contract\BoardColumns;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\EpicBranches;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\KindPrompts;
use App\Module\Workflow\Contract\RetryNotes;
use App\Module\Workflow\Contract\WorkHolds;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Expression\AllOf;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Module\Workflow\Service\ActionContexts;
use App\Module\Workflow\Template\AppRules;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\RuleOrigin;
use App\Module\Workflow\Template\TemplateParser;
use App\Module\Workflow\Template\TemplatePrompts;
use App\Module\Workflow\Template\TemplateSource;
use App\Outbox\OutboxWriter;
use App\Tests\Module\Workflow\Template\AppRulesTest;
use App\Tests\Module\Workflow\Template\TemplateParserTest;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Component\Clock\MockClock;
use Ubermuda\AuditBundle\Auditor;

/** Cards, pull requests and rules for the action tests that run against the database. */
trait ActionScenario
{
    use WorkflowProjects;

    private int $cardNumber = 0;

    private int $pullRequestNumber = 0;

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /** Built by hand, so a request takes the fixed time of the test. */
    private function openWorkRequestHandler(): OpenWorkRequestHandler
    {
        return new OpenWorkRequestHandler(
            $this->service(WorkRequestRepository::class),
            $this->service(OutboxWriter::class),
            $this->em(),
            new MockClock('2026-10-02 12:00:00'),
            $this->service(Auditor::class),
            $this->service(WorkRequestAnnouncer::class),
            $this->service(WorkerRunRepository::class),
            new WorkSubjectHandlers([]),
            $this->service(WorkHolds::class),
        );
    }

    /** The action did its work and opened a request, whose id the outcome carries. */
    private static function assertOpenedWork(ActionOutcome $outcome): void
    {
        self::assertSame(ActionOutcomeKind::Done, $outcome->kind);
        self::assertFalse($outcome->alreadyLive);
        self::assertNotNull($outcome->requestId);
    }

    private function opener(): WorkRequestOpener
    {
        return new WorkRequestOpener(
            $this->openWorkRequestHandler(),
            $this->service(CardRepository::class),
            new WorkRequestContexts($this->service(CardPullRequests::class), $this->service(CardPullRequestRepository::class), $this->service(EpicBranches::class)),
            $this->service(RetryNotes::class),
            $this->service(KindPrompts::class),
        );
    }

    private function contexts(?Actions $actions = null): ActionContexts
    {
        return new ActionContexts(
            $actions ?? $this->service(Actions::class),
            $this->service(ProjectRepository::class),
            $this->service(BoardColumns::class),
            $this->service(WorkflowSlotLinkRepository::class),
            new AppRules($this->service(TemplateParser::class), AppRulesTest::FIXTURE),
            $this->service(TemplateSource::class),
            new TemplatePrompts(TemplateParserTest::PROMPTS_FIXTURE),
        );
    }

    private function card(Project $project, string $column): Card
    {
        $card = new Card($project, $this->column($project, $column), 'Card', '', ++$this->cardNumber);
        $this->em()->persist($card);
        $this->em()->flush();

        return $card;
    }

    /**
     * @param array<string, int|string>   $params
     * @param array<string, list<string>> $lists
     */
    private function rule(string $key, array $params, string $id = 'test-rule', RuleOrigin $origin = RuleOrigin::Template, array $lists = []): Rule
    {
        return new Rule($id, null, new AllOf([]), [$this->service(Actions::class)->call($key, $params, lists: $lists)], $origin);
    }

    private function runAction(Action $action, Rule $rule, CardSnapshot $snapshot, Facts $facts, WorkflowRuleState $state): ActionOutcome
    {
        return $action->run($this->contexts()->for($rule, $snapshot, $facts, $state->fires));
    }

    private function state(Card $card, string $ruleId = 'test-rule'): WorkflowRuleState
    {
        return new WorkflowRuleState($card->id ?? throw new \LogicException('The card is persisted.'), $card->project, $ruleId);
    }

    private function pullRequest(
        Card $card,
        PullRequestState $state = PullRequestState::Open,
        ?string $base = 'main',
        ?string $head = null,
        ?string $headSha = 'head1',
        ?string $url = null,
        ?\DateTimeImmutable $linkedAt = null,
    ): ForgePullRequest {
        $number = ++$this->pullRequestNumber;
        $this->em()->persist(new CardPullRequest($card, $url ?? 'https://github.com/acme/widgets/pull/'.$number, Forge::GitHub, 'acme/widgets', $number, $linkedAt ?? new \DateTimeImmutable()));
        $pullRequest = new ForgePullRequest($card->project, 'github', 'acme/widgets', $number);
        $pullRequest->state = $state;
        $pullRequest->authorRead = true;
        $pullRequest->baseBranch = $base;
        $pullRequest->headBranch = $head ?? 'branch-'.$number;
        $pullRequest->headSha = $headSha;
        $pullRequest->defaultBranch = 'main';
        $this->em()->persist($pullRequest);
        $this->em()->flush();

        return $pullRequest;
    }

    /** @return list<array<mixed>> the detail of each fix-requested event of the card */
    private function fixEvents(Card $card): array
    {
        $rows = $this->service(CardEventRepository::class)->findKindsOfCards($card->project, [$card->id ?? throw new \LogicException('A flushed card has an id.')], [CardEventKind::FixRequested]);

        return array_values(array_map(static fn (array $row): array => $row['detail'], array_filter($rows, static fn (array $row): bool => CardEventKind::FixRequested === $row['kind'])));
    }
}
