<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Workflow\Service\PassMemo;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** A read that goes through the fact pass shares one result inside the pass, and sees a new row once a pass is open again. */
final class PassMemoRepositoryReadTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_rebuild_in_a_new_pass_reads_the_work_request_that_an_action_just_wrote(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('memo-rebuild');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'in-progress');
        $requests = $this->service(WorkRequestRepository::class);
        $memo = $this->service(PassMemo::class);
        $cardId = $card->id ?? throw new \LogicException('A stored card has an id.');

        $firstPass = $memo->during(function () use ($requests, $cardId, $card): array {
            $before = $requests->findLiveForCard($cardId);
            $this->seedRequestFor($card);

            return [$before, $requests->findLiveForCard($cardId)];
        });
        $secondPass = $memo->during(static fn (): array => $requests->findLiveForCard($cardId));

        self::assertSame([], $firstPass[0]);
        self::assertSame([], $firstPass[1], 'A read inside the same pass shares the first result.');
        self::assertCount(1, $secondPass, 'A rebuild opens a new pass, so it reads the request that was just written.');
        self::assertCount(1, $requests->findLiveForCard($cardId), 'Outside a pass every read goes to the database.');
    }

    private function seedRequestFor(Card $card): void
    {
        $this->em()->persist(new WorkRequest($card->project, WorkSubject::CARD, $card->id ?? throw new \LogicException('A stored card has an id.'), $card->number, 'implement', null, 'work', new \DateTimeImmutable('2026-11-06 12:00:00')));
        $this->em()->flush();
    }
}
