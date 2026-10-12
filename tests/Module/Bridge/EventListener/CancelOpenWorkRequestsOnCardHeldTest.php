<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\EventListener;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CancelOpenWorkRequestsOnCardHeldTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_a_hold_cancels_the_open_requests_of_its_card_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'cancel-on-hold@example.com');
        $project = $this->project($em, $owner, 'Cancel On Hold');
        $cardId = Uuid::v7();
        $open = $this->seedWorkRequest($em, $project, cardId: $cardId);
        $claimed = $this->seedWorkRequest($em, $project, cardId: $cardId, kind: 'fix', state: WorkRequestState::Claimed);
        $other = $this->seedWorkRequest($em, $project);

        self::getContainer()->get(CardHolds::class)->hold($project, $cardId, $owner);

        $em->clear();
        self::assertSame(WorkRequestState::Cancelled, $em->find(WorkRequest::class, $open->id)?->state);
        self::assertSame(WorkRequestState::Claimed, $em->find(WorkRequest::class, $claimed->id)?->state);
        self::assertSame(WorkRequestState::Open, $em->find(WorkRequest::class, $other->id)?->state);
    }
}
