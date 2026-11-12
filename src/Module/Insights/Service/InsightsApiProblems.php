<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Api\Problem\ApiProblem;
use App\Api\Problem\ApiProblemException;
use App\Exception\DomainErrors;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Turns what an Insights handler refuses with into the coded problems of the public API. */
final readonly class InsightsApiProblems
{
    private const string GRAPH_KEY_PREFIX = 'insights.saved_graph.error.';

    private const string RULE_KEY_PREFIX = 'insights.bucket_rule.error.';

    private const string SETTINGS_KEY_PREFIX = 'insights.settings.error.';

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function graphNotFound(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(404, 'graph_not_found', 'Graph not found', 'This project has no saved graph with this id.'));
    }

    public function bucketRuleNotFound(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(404, 'bucket_rule_not_found', 'Bucket rule not found', 'This project has no bucket rule with this id.'));
    }

    public function restoreExpired(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(409, 'restore_expired', 'Restore expired', 'This graph was removed more than a day ago, so it cannot come back.'));
    }

    public function dashboardFull(int $limit): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(409, 'dashboard_full', 'Dashboard full', sprintf('The Dashboard holds at most %d graphs. Remove a graph first.', $limit)));
    }

    /**
     * @param list<array{field: string, message: string}> $problems
     */
    public function invalidSettings(array $problems): ApiProblemException
    {
        return ApiProblemException::validationFailed(array_map(
            static fn (array $problem): array => ['field' => self::requestField($problem['field']), 'code' => $problem['field'].'_invalid', 'message' => $problem['message']],
            $problems,
        ));
    }

    public function changeRefused(SavedGraphChangeRefused $refused): ApiProblemException
    {
        return ApiProblemException::validationFailed(array_map(
            static fn (string $field): array => ['field' => self::requestField($field), 'code' => $field.'_conflict', 'message' => $refused->reason],
            $refused->fields,
        ));
    }

    /** A refusal of a graph write: a full Dashboard is a conflict, a locked chart type is a field the client can correct. */
    public function forGraph(DomainErrors $errors): ApiProblemException|DomainErrors
    {
        $fields = [];
        foreach ($errors->errors as $field => $key) {
            if (SavedGraphCap::ERROR_KEY === $key) {
                return new ApiProblemException($this->dashboardFull((int) ($errors->parameters[$field]['%limit%'] ?? 0))->problem, $errors);
            }
            if (!str_starts_with($key, self::GRAPH_KEY_PREFIX)) {
                return $errors;
            }
            $fields[] = ['field' => 'settings.'.$field, 'code' => substr($key, \strlen(self::GRAPH_KEY_PREFIX)), 'message' => $this->sentence($key)];
        }

        return new ApiProblemException(ApiProblemException::validationFailed($fields)->problem, $errors);
    }

    public function forBucketRule(DomainErrors $errors): ApiProblemException|DomainErrors
    {
        $fields = [];
        foreach ($errors->errors as $field => $key) {
            if (BucketRuleWriter::LIMIT_REACHED === $key) {
                return new ApiProblemException(new ApiProblem(409, 'bucket_rule_limit_reached', 'Bucket rule limit reached', $this->sentence($key)), $errors);
            }
            if (!str_starts_with($key, self::RULE_KEY_PREFIX)) {
                return $errors;
            }
            $fields[] = ['field' => $field, 'code' => substr($key, \strlen(self::RULE_KEY_PREFIX)), 'message' => $this->sentence($key)];
        }

        return new ApiProblemException(ApiProblemException::validationFailed($fields)->problem, $errors);
    }

    public function forSettings(DomainErrors $errors): ApiProblemException|DomainErrors
    {
        $names = ['model' => 'defaultModel', 'effort' => 'defaultEffort'];
        $fields = [];
        foreach ($errors->errors as $field => $key) {
            if (!str_starts_with($key, self::SETTINGS_KEY_PREFIX)) {
                return $errors;
            }
            $fields[] = ['field' => $names[$field] ?? $field, 'code' => substr($key, \strlen(self::SETTINGS_KEY_PREFIX)), 'message' => $this->sentence($key)];
        }

        return new ApiProblemException(ApiProblemException::validationFailed($fields)->problem, $errors);
    }

    /** The place of a setting in the body of a request. */
    private static function requestField(string $setting): string
    {
        return match ($setting) {
            'rows', 'formula', 'clearFormula' => $setting,
            'format' => 'formulaFormat',
            default => 'settings.'.$setting,
        };
    }

    private function sentence(string $key): string
    {
        return $this->translator->trans($key, [], null, 'en');
    }
}
