<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox;

use App\Module\Board\Entity\Card;
use App\Module\Inbox\Entity\InboxDecision;
use App\Module\Inbox\Entity\InboxDecisionMadeBy;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;

/** A decision entry on a card, for the tests that read or change one. Requires InboxFixtures. */
trait DecisionFixtures
{
    private function decision(EntityManagerInterface $em, Project $project, Card $card, string $choice = 'beta', InboxDecisionMadeBy $madeBy = InboxDecisionMadeBy::Logged): InboxDecision
    {
        $decision = new InboxDecision($project, $card, 'naming', $choice, ['alpha'], $madeBy);
        $em->persist($decision);

        return $decision;
    }
}
