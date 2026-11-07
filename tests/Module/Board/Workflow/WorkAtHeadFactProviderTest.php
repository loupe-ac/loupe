<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
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
        $request->context = new WorkRequestContext(pullRequestNumber: $pullRequest->number, pullRequestUrl: 'https://github.com/'.$pullRequest->repository.'/pull/'.$pullRequest->number, headSha: $head);
        $request->state = $state;
        $this->em()->persist($request);
        $this->em()->flush();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('liveStates')]
    public function test_a_live_request_that_named_an_older_head_marks_its_kind_moved(WorkRequestState $state): void
    {
        $this->request($this->card, 'fix', $this->pullRequest, 'bbbbbbb2', $state);

        self::assertSame([$this->id($this->pullRequest) => ['fix']], $this->build($this->card)->movedKinds);
    }

    /** @return iterable<string, array{WorkRequestState}> */
    public static function liveStates(): iterable
    {
        yield 'open' => [WorkRequestState::Open];
        yield 'claimed' => [WorkRequestState::Claimed];
    }

    public function test_a_live_request_of_the_current_head_or_a_settled_one_marks_nothing_moved(): void
    {
        $this->request($this->card, 'fix', $this->pullRequest, self::HEAD, WorkRequestState::Open);
        $this->request($this->card, 'review', $this->pullRequest, 'bbbbbbb2', WorkRequestState::Cancelled);

        self::assertSame([], $this->build($this->card)->movedKinds);
    }

    public function test_a_request_of_another_repository_with_the_same_number_marks_nothing_moved(): void
    {
        $other = new ForgePullRequest($this->card->project, 'github', 'acme/gadgets', $this->pullRequest->number);
        $other->state = PullRequestState::Open;
        $other->headSha = 'ccccccc3';
        $this->em()->persist(new CardPullRequest($this->card, 'https://github.com/acme/gadgets/pull/'.$other->number, Forge::GitHub, 'acme/gadgets', $other->number));
        $this->em()->persist($other);
        $this->em()->flush();

        $this->request($this->card, 'fix', $this->pullRequest, self::HEAD, WorkRequestState::Open);

        $facts = $this->build($this->card);
        self::assertSame([], $facts->movedKinds);
        self::assertSame(self::HEAD, $facts->heads[$this->id($this->pullRequest)]);
        self::assertSame('ccccccc3', $facts->heads[$this->id($other)]);
    }

    public function test_a_request_with_no_pull_request_url_marks_nothing_moved(): void
    {
        $request = new WorkRequest($this->card->project, WorkSubject::CARD, $this->card->id ?? throw new \LogicException('A flushed card has an id.'), $this->card->number, 'fix', null, 'fix', new \DateTimeImmutable());
        $request->context = new WorkRequestContext(pullRequestNumber: $this->pullRequest->number, headSha: 'bbbbbbb2');
        $this->em()->persist($request);
        $this->em()->flush();

        self::assertSame([], $this->build($this->card)->movedKinds);
    }

    public function test_the_fingerprint_changes_when_a_live_request_names_an_older_head(): void
    {
        $this->request($this->card, 'fix', $this->pullRequest, self::HEAD, WorkRequestState::Open);
        $current = $this->provider()->fingerprint($this->build($this->card));
        $this->request($this->card, 'review', $this->pullRequest, 'bbbbbbb2', WorkRequestState::Open);

        self::assertNotEquals($current, $this->provider()->fingerprint($this->build($this->card)));
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
