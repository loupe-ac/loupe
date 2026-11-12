<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Bridge\Command\MetricQueryCommand;
use App\Module\Bridge\Command\MetricQueryHandler;
use App\Module\Bridge\Metric\Formula\FormulaRow;
use App\Module\Bridge\Metric\Formula\MetricFormula;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricQueryPayload;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use App\Module\Workflow\Contract\TemplateMetrics;
use App\Module\Workflow\Contract\TemplateMetricSet;
use App\Security\CredentialRateLimitKey;
use OpenApi\Attributes as OA;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[PublicGet(
    operationId: 'queryMetric',
    summary: 'Read a metric over time',
    tags: ['Analytics'],
    description: 'Reads one metric of the worker runs or the finished cards of the project, or a formula over up to six metrics. Pass `metric` and `statistic`, or `rows` and `formula`, and not both. `rows` is a comma-separated list of `letter:metric:statistic`, such as `A:cache-read-tokens:sum,B:cache-write-tokens:sum`. A metric key can hold a colon, as `bucket-time:tests` does, so the letter is the text before the first colon and the statistic is the text after the last one. The metric list route gives the metrics, and a refused combination names the values to use. A query spends from its own limit of 30 a minute.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'unit', description: 'What one row stands for.', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['run', 'card', 'reading'])),
        new OA\Parameter(name: 'metric', description: 'A key from the metric list. Leave it out when you pass `rows`.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'statistic', description: 'How the rows combine. Leave it out when you pass `rows`.', in: 'query', schema: new OA\Schema(type: 'string', enum: ['median', 'mean', 'sum', 'p90', 'count', 'max'])),
        new OA\Parameter(name: 'group', description: 'Splits the rows into one series per value.', in: 'query', schema: new OA\Schema(type: 'string', default: 'none')),
        new OA\Parameter(name: 'range', description: 'The window, counted back from now.', in: 'query', schema: new OA\Schema(type: 'string', default: 'thirty-days', enum: ['seven-days', 'thirty-days', 'ninety-days', 'all'])),
        new OA\Parameter(name: 'bucket', description: 'The size of a point. The range seven-days takes day alone.', in: 'query', schema: new OA\Schema(type: 'string', default: 'week', enum: ['day', 'week', 'month'])),
        new OA\Parameter(name: 'compare', description: 'With `previous`, the answer also reads the period of the same length just before the range. The range all has no previous period.', in: 'query', schema: new OA\Schema(type: 'string', default: 'off', enum: ['off', 'previous'])),
        new OA\Parameter(name: 'rows', description: 'The lettered rows of a formula, as `A:metric:statistic`, separated by commas. The letters are A to F.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'formula', description: 'The letters of the rows combined with + - * / and parentheses, such as `A / B`, in at most 200 characters.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'formulaFormat', description: 'How the result of a formula reads. Auto keeps money and duration, and shows every other result as a number.', in: 'query', schema: new OA\Schema(type: 'string', default: 'auto', enum: ['auto', 'number', 'percent', 'money', 'duration'])),
    ],
    responses: [
        new SuccessResponse(200, 'The series of the metric.', MetricQueryResponse::class),
        new ProblemResponse(404, ['project_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[RateLimit('public_metric_query', key: new Expression('this.rateLimitKey.forRequest(request)'))]
#[Route(
    '/api/v1/projects/{project}/metrics/query',
    name: 'api_metric_query',
    methods: ['GET'],
)]
final class QueryMetricController extends AppController
{
    public function __construct(
        private readonly MetricQueryHandler $query,
        private readonly TemplateMetrics $templateMetrics,
        private readonly TranslatorInterface $translator,
        public readonly CredentialRateLimitKey $rateLimitKey,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project): JsonResponse
    {
        $input = $request->query;
        $text = static fn (string $name, string $default = ''): string => \is_string($value = $input->all()[$name] ?? null) ? $value : $default;
        $errors = [];
        foreach (['unit', 'metric', 'statistic', 'group', 'range', 'bucket', 'compare', 'rows', 'formula', 'formulaFormat'] as $name) {
            if (isset($input->all()[$name]) && !\is_string($input->all()[$name])) {
                $errors[] = ['field' => $name, 'code' => 'invalid_value', 'message' => sprintf('The %s must be given once, as text.', $name)];
            }
        }
        if ([] !== $errors) {
            throw ApiProblemException::validationFailed($errors);
        }

        $templates = $this->templateMetrics->forProject($project->requireId());
        $metric = $text('metric');
        $statistic = $text('statistic');
        $rows = $text('rows');
        $formulaText = $text('formula');

        $formula = null;
        if ('' !== $rows || '' !== $formulaText) {
            if ('' !== $metric || '' !== $statistic) {
                throw ApiProblemException::validationFailed([['field' => 'rows', 'code' => 'metric_and_rows', 'message' => 'Pass metric and statistic, or rows and formula, and not both.']]);
            }
            $formula = MetricFormula::parse($rows, $formulaText, $text('formulaFormat', 'auto'), $templates);
            $earliest = $formula->earliestRow();
            if (null === $earliest) {
                throw ApiProblemException::validationFailed([['field' => 'formula', 'code' => 'formula_invalid', 'message' => $this->formulaText($formula)]]);
            }
            [$shape, $parsedStatistic] = [$earliest->shape, $earliest->statistic];
        } else {
            if ('' === $metric || '' === $statistic) {
                throw ApiProblemException::validationFailed([['field' => 'metric', 'code' => 'metric_required', 'message' => 'Pass metric and statistic to read one metric, or rows and formula to combine metrics.']]);
            }
            $shape = $this->shapeOf($metric, $templates);
            $parsedStatistic = MetricStatistic::tryFrom($statistic);
        }

        $unit = MetricUnit::tryFrom($text('unit'));
        $group = MetricGroup::tryFrom($text('group', 'none'));
        $range = MetricRange::tryFrom($text('range', 'thirty-days'));
        $bucket = MetricBucket::tryFrom($text('bucket', 'week'));
        $compare = MetricCompare::tryFrom($text('compare', 'off'));
        foreach (['unit' => [$unit, MetricUnit::class], 'statistic' => [$parsedStatistic, MetricStatistic::class], 'group' => [$group, MetricGroup::class], 'range' => [$range, MetricRange::class], 'bucket' => [$bucket, MetricBucket::class], 'compare' => [$compare, MetricCompare::class]] as $field => [$value, $enum]) {
            if (null === $value) {
                $errors[] = ['field' => $field, 'code' => 'invalid_value', 'message' => sprintf('Unknown %s. Use one of: %s.', $field, self::valuesOf($enum::cases()))];
            }
        }
        if ([] !== $errors || null === $unit || null === $parsedStatistic || null === $group || null === $range || null === $bucket || null === $compare) {
            throw ApiProblemException::validationFailed($errors);
        }

        $command = new MetricQueryCommand($project, $unit, $shape, $parsedStatistic, $group, $range, $bucket, $compare, formula: $formula);
        try {
            $view = ($this->query)($command);
        } catch (DomainErrors $e) {
            throw ApiProblemException::validationFailed($this->refusals($command, $e));
        }

        return new JsonResponse(new MetricQueryResponse(
            unit: $unit->value,
            metric: null === $formula ? $shape->key->key() : null,
            statistic: null === $formula ? $parsedStatistic->value : null,
            group: $group->value,
            range: $range->value,
            bucket: $bucket->value,
            compare: $compare->value,
            window: ['current' => MetricQueryPayload::window($view->current), 'previous' => null === $view->previous ? null : MetricQueryPayload::window($view->previous)],
            series: array_map(static fn (MetricSeries $series): array => MetricQueryPayload::series($series, null !== $formula), $view->series),
            formula: null === $formula ? null : [
                'rows' => array_map(static fn (FormulaRow $row): array => ['letter' => $row->letter, 'metric' => $row->shape->key->key(), 'statistic' => $row->statistic->value], $formula->rows),
                'formula' => $formula->formula,
                'format' => $formula->format->value,
                'valueType' => $formula->valueType()?->value,
            ],
        ));
    }

    private function shapeOf(string $metric, TemplateMetricSet $templates): MetricShape
    {
        $key = MetricKey::tryParse($metric, $templates);
        $shape = null === $key ? null : MetricShape::of($key, $templates);

        return $shape ?? throw ApiProblemException::validationFailed([['field' => 'metric', 'code' => 'unknown_metric', 'message' => sprintf('Unknown metric "%s". The metric list route gives the metrics of the project.', mb_substr($metric, 0, 80))]]);
    }

    private function formulaText(MetricFormula $formula): string
    {
        return ($formula->problem ?? throw new \LogicException('A formula with no row has a problem.'))->english($this->translator);
    }

    /** @return list<array{field: string, code: string, message: string}> */
    private function refusals(MetricQueryCommand $command, DomainErrors $errors): array
    {
        $formula = $command->formula;
        $refusals = [];
        foreach (array_keys($errors->errors) as $field) {
            $refusals[] = match ($field) {
                MetricQueryHandler::FORMULA_FIELD => ['field' => 'formula', 'code' => 'formula_invalid', 'message' => $this->formulaText($formula ?? throw new \LogicException('Only a formula query has a formula error.'))],
                'bucket' => ['field' => 'bucket', 'code' => 'bucket_not_allowed', 'message' => sprintf('The range %s does not take the bucket "%s". Use one of: %s.', $command->range->value, $command->bucket->value, self::valuesOf($command->range->allowedBuckets()))],
                'compare' => ['field' => 'compare', 'code' => 'compare_not_allowed', 'message' => sprintf('The range %s has no previous period to compare with. Use compare off, or another range.', $command->range->value)],
                'unit' => $this->notAllowed($command, 'unit', $command->unit, $formula?->units() ?? $command->metric->units()),
                'statistic' => $this->notAllowed($command, 'statistic', $command->statistic, $command->metric->statistics()),
                'group' => $this->notAllowed($command, 'group', $command->group, $formula?->groups() ?? $command->metric->groups()),
                default => throw new \LogicException(sprintf('The metric query refuses no field "%s".', $field)),
            };
        }

        return $refusals;
    }

    /**
     * @param list<\BackedEnum> $allowed
     *
     * @return array{field: string, code: string, message: string}
     */
    private function notAllowed(MetricQueryCommand $command, string $field, \BackedEnum $given, array $allowed): array
    {
        $message = null === $command->formula
            ? sprintf('The metric %s does not take the %s "%s". Use one of: %s.', $command->metric->key->key(), $field, $given->value, self::valuesOf($allowed))
            : sprintf('The rows of this formula do not all take the %s "%s". Use one of: %s.', $field, $given->value, self::valuesOf($allowed));

        return ['field' => $field, 'code' => $field.'_not_allowed', 'message' => $message];
    }

    /** @param list<\BackedEnum> $cases */
    private static function valuesOf(array $cases): string
    {
        return implode(', ', array_map(static fn (\BackedEnum $case): string => (string) $case->value, $cases));
    }
}
