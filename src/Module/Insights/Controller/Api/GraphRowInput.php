<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/** One lettered row of a formula. */
#[OA\Schema(required: ['letter', 'metric', 'statistic'])]
final class GraphRowInput
{
    #[Assert\NotBlank]
    #[Assert\Regex('/^[^,]+$/')]
    #[OA\Property(description: 'The letter that the formula uses for this row, `A` to `F`.', nullable: false)]
    public ?string $letter = null;

    #[Assert\NotBlank]
    #[Assert\Regex('/^[^,]+$/')]
    #[OA\Property(description: 'The key of a metric that adds up over runs.', nullable: false)]
    public ?string $metric = null;

    #[Assert\NotBlank]
    #[Assert\Regex('/^[^,]+$/')]
    #[OA\Property(description: '`median`, `mean`, `sum`, `p90`, `count` or `max`.', nullable: false)]
    public ?string $statistic = null;

    /**
     * The rows, the formula and the format that a request names, as the settings of a graph.
     *
     * @param list<self> $rows
     *
     * @return array<string, string>
     */
    public static function formulaSettings(array $rows, ?string $formula, ?string $format): array
    {
        return array_filter(
            [
                'rows' => [] === $rows ? null : implode(',', array_map(static fn (self $row): string => $row->letter.':'.$row->metric.':'.$row->statistic, $rows)),
                'formula' => $formula,
                'format' => $format,
            ],
            static fn (?string $value): bool => null !== $value,
        );
    }
}
