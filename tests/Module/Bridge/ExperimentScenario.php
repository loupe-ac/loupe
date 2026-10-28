<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** Board cards worked by experiment runs, for the experiment pages. */
trait ExperimentScenario
{
    use BoardColumnFixtures;
    use BridgeScenario;

    private function boardProject(EntityManagerInterface $em, User $owner): Project
    {
        $project = $this->project($em, $owner, 'Experiment board');
        $this->seedColumns($project);
        $em->flush();

        return $project;
    }

    /** A card whose history starts a day ago, so the report keeps it. */
    private function experimentCard(EntityManagerInterface $em, Project $project, int $number, string $column = 'done', bool $merged = false): Card
    {
        $card = new Card(project: $project, column: $this->column($project, $column), title: 'Card '.$number, body: '', number: $number);
        $em->persist($card);
        $em->flush();
        $this->cardEvent($em, $card, CardEventKind::Created, [], '-1 day');
        if ($merged) {
            $this->cardEvent($em, $card, CardEventKind::Moved, ['from' => [], 'to' => [], 'cause' => ['type' => 'workflow-rule', 'rule' => 'merged', 'outcome' => 'merged']], '-1 hour');
        }

        return $card;
    }

    /** A fix request the card opened, which the experiment counts as a fix round of the reason. */
    private function fixRequest(EntityManagerInterface $em, Project $project, Card $card, string $reason): void
    {
        $request = $this->seedWorkRequest($em, $project, $card->id ?? throw new \LogicException('A flushed card has an id.'), 'fix', state: WorkRequestState::Done);
        $request->role = 'fix';
        $request->context = new WorkRequestContext(reason: $reason);
        $em->flush();
    }

    /** @param array<string, mixed> $detail */
    private function cardEvent(EntityManagerInterface $em, Card $card, CardEventKind $kind, array $detail, string $at): void
    {
        $events = static::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $events);
        $events->record($card, $kind, Actor::System, null, $detail, new \DateTimeImmutable($at));
        $em->flush();
    }

    private function experimentRun(
        EntityManagerInterface $em,
        Project $project,
        Card $card,
        string $variant,
        string $experiment = 'model-test',
        WorkerRunState $state = WorkerRunState::Succeeded,
        ?string $switchedFrom = null,
        string $at = '-2 hours',
    ): WorkerRun {
        // A run with no key reads as one from an older bridge, and a trigger rewrites its failed state.
        $run = $this->seedRun($em, $project, receivedAt: new \DateTimeImmutable($at), cardNumber: $card->number, cardId: $card->id, state: $state, runKey: Uuid::v7(), workKind: 'implement');
        $run->experiment = $experiment;
        $run->variant = $variant;
        $run->switchedFrom = $switchedFrom;
        $run->requestedModel = 'model-'.$variant;
        $em->flush();

        return $run;
    }
}
