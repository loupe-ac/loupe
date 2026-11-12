<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Bridge\Messenger\RecomputeBucketTimes;
use App\Module\Insights\Repository\InsightsBucketRuleRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Moves a bucket rule to a place in the order the rules apply. The rules reuse their own positions
 * in the new order. A move that changes nothing sends no signal.
 */
final readonly class PlaceBucketRuleHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private InsightsBucketRuleRepository $insightsBucketRules,
        private MessageBusInterface $bus,
        private Auditor $auditor,
    ) {
    }

    /** @throws NotFoundHttpException when the rule is gone */
    public function __invoke(PlaceBucketRuleCommand $command): void
    {
        $project = $command->rule->project;
        $projectId = (string) ($project->id ?? throw new \LogicException('A stored project has an id.'));
        $ruleId = (string) ($command->rule->id ?? throw new \LogicException('A stored rule has an id.'));

        $moved = $this->em->wrapInTransaction(function () use ($command, $project, $ruleId): ?bool {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            $ordered = $this->insightsBucketRules->findOrderedFresh($project);
            $from = array_find_key($ordered, static fn ($rule): bool => (string) $rule->id === $ruleId);
            if (null === $from) {
                return null;
            }
            $to = max(0, min(\count($ordered) - 1, $command->place));
            if ($to === $from) {
                return false;
            }

            $positions = array_map(static fn ($rule): int => $rule->position, $ordered);
            [$moving] = array_splice($ordered, $from, 1);
            array_splice($ordered, $to, 0, [$moving]);
            foreach ($ordered as $index => $rule) {
                $rule->position = $positions[$index];
            }
            $this->em->flush();

            return true;
        });
        if (null === $moved) {
            throw new NotFoundHttpException('The project has no such rule.');
        }
        if (!$moved) {
            return;
        }

        $this->auditor->record(
            'insights.bucket_rule_moved',
            AuditOutcome::Success,
            ['projectId' => $projectId, 'ruleId' => $ruleId, 'place' => $command->place],
            new AuditSubject('project', $projectId),
        );
        $this->bus->dispatch(new RecomputeBucketTimes($projectId));
    }
}
