<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use App\Module\Insights\Command\SavedGraphRow;
use App\Module\Insights\Service\SavedGraphShape;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** A saved graph, as the public API shows it. */
#[OA\Schema(required: ['id', 'name', 'position', 'width', 'showLegend', 'settings', 'formula', 'broken', 'undoUntil'])]
final readonly class GraphResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,

        #[OA\Property(maxLength: 120)]
        public string $name,

        #[OA\Property(description: 'The place among the graphs on the Dashboard, counted from 0. Null for a removed graph.', nullable: true, minimum: 0)]
        public ?int $position,

        #[OA\Property(description: 'How much of the Dashboard row the graph takes.', enum: ['half', 'full'])]
        public string $width,

        #[OA\Property(description: 'Whether the tile shows its legend.')]
        public bool $showLegend,

        #[OA\Property(ref: new Model(type: GraphSettingsResponse::class))]
        public GraphSettingsResponse $settings,

        #[OA\Property(description: 'The formula, or null for a graph that reads one metric.', type: 'object', nullable: true, allOf: [new OA\Schema(ref: new Model(type: GraphFormulaResponse::class))])]
        public ?GraphFormulaResponse $formula,

        #[OA\Property(description: 'What the graph still reads that the project no longer has, or null.', type: 'object', nullable: true, allOf: [new OA\Schema(ref: new Model(type: GraphBrokenResponse::class))])]
        public ?GraphBrokenResponse $broken,

        #[OA\Property(description: 'For a removed graph, the time until which `POST /graphs/{graph}/restore` brings it back. Null for a graph on the Dashboard.', format: 'date-time', nullable: true)]
        public ?string $undoUntil,
    ) {
    }

    public static function fromRow(SavedGraphRow $row): self
    {
        $graph = $row->graph;
        $formula = SavedGraphShape::formulaOf($graph);

        return new self(
            id: (string) $graph->id,
            name: $graph->name,
            position: $row->position,
            width: $graph->width->value,
            showLegend: $graph->showLegend,
            settings: GraphSettingsResponse::fromSettings(SavedGraphShape::settingsOf($graph)),
            formula: null === $formula ? null : new GraphFormulaResponse(
                array_map(static fn (array $part): GraphRowResponse => new GraphRowResponse($part['letter'], $part['metric'], $part['statistic']), $formula['rows']),
                $formula['formula'],
                $formula['format'],
            ),
            broken: null === $row->broken ? null : new GraphBrokenResponse($row->broken->reason->value, $row->broken->name),
            undoUntil: $graph->undoUntil()?->format(\DATE_ATOM),
        );
    }
}
