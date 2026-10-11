<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\DocumentStatuses;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\SwapsDocumentStatus;
use App\Module\Workflow\Engine\RuleSubject;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\Template;

/**
 * Asks, for the documents of a card, whether a verdict on one of them would make a rule of the card's slot turn true.
 * It evaluates rules on copies of the facts, so it moves no card and writes no rule state.
 */
final readonly class VerdictWouldFire
{
    public function __construct(
        private FactsBuilder $facts,
        private RuleSubject $subject,
    ) {
    }

    /**
     * @param list<string> $documentIds the documents in review of the card
     *
     * @return list<string> the ids of the documents whose approval or change request would make a rule of the card's slot turn true
     */
    public function forCard(CardSnapshot $card, Template $template, array $documentIds): array
    {
        $facts = $this->facts->build($card, new \DateTimeImmutable());
        $rules = array_values(array_filter(
            $template->rulesFor($facts->slot),
            fn (Rule $rule): bool => !$this->subject->bind($rule, $facts)->truth,
        ));
        if ([] === $rules) {
            return [];
        }

        return array_values(array_filter($documentIds, function (string $documentId) use ($facts, $rules): bool {
            foreach ([DocumentStatuses::APPROVED, DocumentStatuses::CHANGES_REQUESTED] as $status) {
                $copy = self::withStatus($facts, $documentId, $status);
                if (array_any($rules, fn (Rule $rule): bool => $this->subject->bind($rule, $copy)->truth)) {
                    return true;
                }
            }

            return false;
        }));
    }

    private static function withStatus(Facts $facts, string $documentId, string $status): Facts
    {
        $swapped = [];
        foreach ($facts->provided as $class => $provided) {
            if ($provided instanceof SwapsDocumentStatus) {
                $swapped[$class] = $provided->withDocumentStatus($documentId, $status);
            }
        }

        return $facts->withProvided($swapped);
    }
}
