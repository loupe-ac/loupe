<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use OpenApi\Attributes as OA;

/** One card of an experiment, as the spec shows it. ExperimentCardPayload builds the real body. */
#[OA\Schema(required: ['cardId', 'number', 'title', 'variant', 'pullRequestUrls', 'leftOut', 'exclusion'])]
final readonly class ExperimentCardRowResponse
{
    /**
     * @param list<string>                                                        $pullRequestUrls
     * @param list<string>                                                        $leftOut
     * @param array{reason: string, excludedBy: ?string, excludedAt: string}|null $exclusion
     */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $cardId,

        #[OA\Property(description: 'The card number, or null for a card that the experiment lists but the board and the runs no longer name. A card with no number cannot be excluded.', nullable: true, minimum: 1)]
        public ?int $number,

        #[OA\Property(description: 'The card title, or null when the card is gone.', nullable: true)]
        public ?string $title,

        #[OA\Property(description: 'The variant that did the work, or null when none is known.', nullable: true)]
        public ?string $variant,

        #[OA\Property(description: 'Every pull request link of the card, oldest first.', type: 'array', items: new OA\Items(type: 'string', format: 'uri'))]
        public array $pullRequestUrls,

        #[OA\Property(description: 'Why the comparison leaves the card out. Empty for a card that counts.', type: 'array', items: new OA\Items(type: 'string', enum: ['switched', 'mixed', 'before-test', 'covered-without-variant', 'no-history', 'no-run', 'excluded']))]
        public array $leftOut,

        #[OA\Property(description: 'The manual exclusion of the card, or null.', required: ['reason', 'excludedBy', 'excludedAt'], properties: [
            new OA\Property(property: 'reason', type: 'string'),
            new OA\Property(property: 'excludedBy', description: 'The full name of the author, or null when the account is gone.', type: 'string', nullable: true),
            new OA\Property(property: 'excludedAt', type: 'string', format: 'date-time'),
        ], type: 'object', nullable: true)]
        public ?array $exclusion,
    ) {
    }
}
