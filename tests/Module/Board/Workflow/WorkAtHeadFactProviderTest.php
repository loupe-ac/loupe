<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Workflow\WorkAtHeadFactProvider;
use App\Module\Board\Workflow\WorkAtHeadFacts;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WorkAtHeadFactProviderTest extends KernelTestCase
{
    use ActionScenario;

    private const string HEAD = 'aaaaaaa1';

    private Card $card;

    private ForgePullRequest $pullRequest;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->card = $this->card($this->workflowProject('work-at-head'), 'in-review');
        $this->pullRequest = $this->pullRequest($this->card, headSha: self::HEAD);
    }

    public function test_a_done_request_of_the_pull_request_at_its_head_covers_it(): void
    {
        $this->request($this->card, 'epic-preview', $this->pullRequest, self::HEAD, WorkRequestState::Done);

        $facts = $this->build($this->card);

        self::assertSame([$this->id($this->pullRequest) => ['epic-preview']], $facts->doneKinds);
        self::assertSame([$this->id($this->pullRequest) => self::HEAD], $facts->heads);
    }

    public function test_a_request_of_an_older_head_covers_nothing(): void
    {
        $this->request($this->card, 'epic-preview', $this->pullRequest, 'bbbbbbb2', WorkRequestState::Done);

        self::assertSame([], $this->build($this->card)->doneKinds);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unfinishedStates')]
    public function test_a_request_that_did_not_end_done_covers_nothing(WorkRequestState $state): void
    {
        $this->request($this->card, 'epic-preview', $this->pullRequest, self::HEAD, $state);

        self::assertSame([], $this->build($this->card)->doneKinds);
    }

    /** @return iterable<string, array{WorkRequestState}> */
    public static function unfinishedStates(): iterable
    {
        foreach ([WorkRequestState::Refused, WorkRequestState::Expired, WorkRequestState::Cancelled] as $state) {
            yield $state->value => [$state];
        }
    }

    public function test_a_request_of_another_pull_request_at_the_same_head_covers_nothing(): void
    {
        $other = $this->pullRequest($this->card, headSha: self::HEAD);
        $this->request($this->card, 'epic-preview', $other, self::HEAD, WorkRequestState::Done);

        $facts = $this->build($this->card);

        self::assertSame([$this->id($other) => ['epic-preview']], $facts->doneKinds);
        self::assertArrayNotHasKey($this->id($this->pullRequest), $facts->doneKinds);
    }

    public function test_a_request_of_another_card_covers_nothing(): void
    {
        $other = $this->card($this->card->project, 'in-review');
        $this->request($other, 'epic-preview', $this->pullRequest, self::HEAD, WorkRequestState::Done);

        self::assertSame([], $this->build($this->card)->doneKinds);
    }

    public function test_a_closed_pull_request_is_left_out(): void
    {
        $this->request($this->card, 'epic-preview', $this->pullRequest, self::HEAD, WorkRequestState::Done);
        $this->pullRequest->state = PullRequestState::Closed;
        $this->em()->flush();

        $facts = $this->build($this->card);

        self::assertSame([], $facts->doneKinds);
        self::assertSame([], $facts->heads);
    }

    public function test_the_fingerprint_changes_with_the_head_and_with_the_kinds(): void
    {
        $before = $this->provider()->fingerprint($this->build($this->card));

        $this->request($this->card, 'epic-preview', $this->pullRequest, self::HEAD, WorkRequestState::Done);
        $covered = $this->provider()->fingerprint($this->build($this->card));
        $this->pullRequest->headSha = 'bbbbbbb2';
        $this->em()->flush();
        $moved = $this->provider()->fingerprint($this->build($this->card));

        self::assertNotEquals($before, $covered);
        self::assertNotEquals($covered, $moved);
    }

    private function request(Card $card, string $kind, ForgePullRequest $pullRequest, string $head, WorkRequestState $state): void
    {
        $request = new WorkRequest($card->project, WorkSubject::CARD, $card->id ?? throw new \LogicException('A flushed card has an id.'), $card->number, $kind, null, $kind, new \DateTimeImmutable());
        $request->context = new WorkRequestContext(pullRequestNumber: $pullRequest->number, headSha: $head);
        $request->state = $state;
        $this->em()->persist($request);
        $this->em()->flush();
    }

    private function build(Card $card): WorkAtHeadFacts
    {
        $facts = $this->provider()->build($card->snapshot());
        self::assertInstanceOf(WorkAtHeadFacts::class, $facts);

        return $facts;
    }

    private function provider(): WorkAtHeadFactProvider
    {
        return $this->service(WorkAtHeadFactProvider::class);
    }

    private function id(ForgePullRequest $pullRequest): string
    {
        return ($pullRequest->id ?? throw new \LogicException('A flushed pull request has an id.'))->toRfc4122();
    }
}
