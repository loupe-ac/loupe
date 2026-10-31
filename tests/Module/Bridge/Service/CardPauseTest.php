<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Command\ClaimWorkRequestCommand;
use App\Module\Bridge\Command\ClaimWorkRequestHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\CardPause;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CardPauseTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_a_hold_cancels_the_open_request_and_leaves_the_claimed_one(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'pause-cancel@example.com');
        $project = $this->project($em, $owner, 'Pause Cancel');
        $cardId = Uuid::v7();
        $open = $this->seedWorkRequest($em, $project, $cardId, 'tech-design');
        $claimed = $this->seedWorkRequest($em, $project, $cardId, 'implement', state: WorkRequestState::Claimed, bridgeId: Uuid::v4(), claimToken: Uuid::v4(), leaseUntil: new \DateTimeImmutable('+1 hour'));
        $other = $this->seedWorkRequest($em, $project);

        $this->pause($project, $cardId, $owner);

        $em->clear();
        self::assertSame(WorkRequestState::Cancelled, $em->find(WorkRequest::class, $open->id)?->state);
        self::assertSame(WorkRequestState::Claimed, $em->find(WorkRequest::class, $claimed->id)?->state);
        self::assertSame(WorkRequestState::Open, $em->find(WorkRequest::class, $other->id)?->state);
    }

    public function test_a_bridge_that_starts_after_the_card_moves_on_gets_no_work(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'pause-sequence@example.com');
        $project = $this->project($em, $owner, 'Pause Sequence');
        $cardId = Uuid::v7();
        $request = $this->seedWorkRequest($em, $project, $cardId, 'tech-design');
        $bridge = $this->seedBridge($em, $owner, projects: [(string) $project->id]);
        $bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS];
        $bridge->accounts = [['name' => 'work', 'harness' => 'claude', 'state' => 'ready', 'reason' => null, 'kinds' => ['tech-design']]];
        $em->flush();

        $this->pause($project, $cardId, $owner);

        $repository = self::getContainer()->get(WorkRequestRepository::class);
        self::assertInstanceOf(WorkRequestRepository::class, $repository);
        self::assertSame([], $repository->findOpenOffers(Uuid::v4(), [(string) $project->id], [Bridge::CAPABILITY_WORK_REQUESTS], 10, []));
        $claim = self::getContainer()->get(ClaimWorkRequestHandler::class);
        self::assertInstanceOf(ClaimWorkRequestHandler::class, $claim);
        self::assertNull($claim(new ClaimWorkRequestCommand($owner, $bridge->id, $request->id ?? throw new \LogicException('A flushed request has an id.'), 'work'))->request);
        $em->clear();
        self::assertSame(WorkRequestState::Cancelled, $em->find(WorkRequest::class, $request->id)?->state);
    }

    private function pause(\App\Module\Project\Entity\Project $project, Uuid $cardId, \App\Module\Account\Entity\User $owner): void
    {
        $pause = self::getContainer()->get(CardPause::class);
        self::assertInstanceOf(CardPause::class, $pause);
        self::assertTrue($this->em()->wrapInTransaction(static fn (): bool => $pause->take($project, $cardId, $owner, 'agent')));
        $pause->announce($project, $cardId);
    }
}
