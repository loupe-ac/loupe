<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Experiment\ExperimentCard;
use App\Module\Bridge\Experiment\LeftOutReason;

/** The wire form of one card of an experiment. */
final readonly class ExperimentCardPayload
{
    /** @return array{cardId: string, number: ?int, title: ?string, variant: ?string, pullRequestUrls: list<string>, leftOut: list<string>, exclusion: array{reason: string, excludedBy: ?string, excludedAt: string}|null} */
    public function forCard(ExperimentCard $card): array
    {
        return [
            'cardId' => $card->cardId->toRfc4122(),
            'number' => $card->number,
            'title' => $card->title,
            'variant' => $card->variant,
            'pullRequestUrls' => $card->pullRequestUrls,
            'leftOut' => array_map(static fn (LeftOutReason $reason): string => $reason->value, $card->leftOut),
            'exclusion' => null === $card->exclusion ? null : [
                'reason' => $card->exclusion->reason,
                'excludedBy' => $card->exclusion->authorName,
                'excludedAt' => $card->exclusion->excludedAt->format(\DateTimeInterface::ATOM),
            ],
        ];
    }
}
