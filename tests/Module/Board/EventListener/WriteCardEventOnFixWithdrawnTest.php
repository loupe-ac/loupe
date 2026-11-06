<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use App\Tests\Support\AgentCredential;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WriteCardEventOnFixWithdrawnTest extends KernelTestCase
{
    use BoardToolScenario;

    private const string OLD = 'aaaaaaa1234567';
    private const string NEW = 'bbbbbbb7654321';

    private EntityManagerInterface $em;
    private Project $project;
    private ForgePullRequest $pullRequest;
    private Card $card;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->project = $this->makeProject('fix-withdrawn');
        $this->pullRequest = new ForgePullRequest($this->project, 'github', 'Acme/Widgets', 5);
        $this->pullRequest->headSha = self::NEW;
        $this->card = new Card($this->project, $this->column($this->project, 'in-progress'), 'Ship it', '', 1);
        $this->em->persist($this->pullRequest);
        $this->em->persist($this->card);
        $this->em->persist(new CardPullRequest($this->card, 'https://github.com/Acme/Widgets/pull/5', Forge::GitHub, 'Acme/Widgets', 5));
        $this->em->flush();
    }

    public function test_a_cancelled_fix_for_an_old_head_leaves_one_history_row_with_both_heads(): void
    {
        $this->announce($this->request('fix', self::OLD, 5, WorkRequestState::Cancelled));

        $events = $this->events();
        self::assertCount(1, $events);
        self::assertSame(CardEventKind::FixWithdrawn, $events[0]->kind);
        self::assertSame(['pullRequest' => 5, 'oldHeadSha' => self::OLD, 'newHeadSha' => self::NEW], $events[0]->detail);
    }

    public function test_a_cancelled_fix_for_the_current_head_leaves_no_row(): void
    {
        $this->announce($this->request('fix', self::NEW, 5, WorkRequestState::Cancelled));

        self::assertSame([], $this->events());
    }

    public function test_a_fix_for_another_pull_request_leaves_no_row(): void
    {
        $this->announce($this->request('fix', self::OLD, 6, WorkRequestState::Cancelled));

        self::assertSame([], $this->events());
    }

    public function test_a_review_that_moved_head_leaves_no_row(): void
    {
        $this->announce($this->request('review', self::OLD, 5, WorkRequestState::Cancelled));

        self::assertSame([], $this->events());
    }

    public function test_a_fix_that_is_not_cancelled_leaves_no_row(): void
    {
        $this->announce($this->request('fix', self::OLD, 5, WorkRequestState::Done));

        self::assertSame([], $this->events());
    }

    private function request(string $kind, string $head, int $number, WorkRequestState $state): WorkRequest
    {
        $request = new WorkRequest(
            project: AgentCredential::managed($this->em, $this->project, $this->project->id),
            subjectType: WorkSubject::CARD,
            subjectId: $this->card->id ?? throw new \LogicException('A flushed card has an id.'),
            cardNumber: 1,
            kind: $kind,
            capability: null,
            ruleId: 'fix-in-implementation',
            createdAt: new \DateTimeImmutable('2026-10-01 12:00:00'),
        );
        $request->context = new WorkRequestContext(pullRequestNumber: $number, headSha: $head);
        $request->state = $state;
        $request->settledAt = new \DateTimeImmutable('2026-10-01 12:05:00');
        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    private function announce(WorkRequest $request): void
    {
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $events->dispatch(new WorkRequestChanged(
            $this->project->id ?? throw new \LogicException('A flushed project has an id.'),
            $request->subjectType,
            $request->subjectId,
            $request->id ?? throw new \LogicException('A flushed request has an id.'),
            $request->state,
        ));
    }

    /** @return list<CardEvent> */
    private function events(): array
    {
        $cardEvents = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $cardEvents);

        return array_values(array_filter($cardEvents->findForCard($this->card), static fn (CardEvent $event): bool => CardEventKind::FixWithdrawn === $event->kind));
    }
}
