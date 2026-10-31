<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Contract\EndsReviewLoop;
use App\Module\Workflow\Contract\ParameterNames;
use App\Module\Workflow\Contract\ReadsDocumentTag;
use App\Module\Workflow\Contract\RepositorySuggestion;
use App\Module\Workflow\Contract\TemplateMetric;
use App\Module\Workflow\Contract\TemplateOutcome;
use App\Module\Workflow\Contract\TemplateValueKey;

final readonly class Template
{
    public const string EPIC_BRANCH_NUMBER = '{number}';

    public const int EPIC_BRANCH_MAX_LENGTH = 255;

    /** A Git branch name that holds the epic number placeholder exactly once. */
    public const string EPIC_BRANCH_RULE = '/^(?!.*\{number\}.*\{number\})(?!.*\.\.)(?!.*\.lock(?:\/|$))(?=.*\{number\})(?:[A-Za-z0-9_]|\{number\})(?:[A-Za-z0-9._-]|\{number\}|\/(?![\/.-]))*(?<![.\/])$/D';

    /** A metric or an outcome key: lowercase words joined with hyphens, at most 64 characters. */
    public const string KEY_PATTERN = '/^(?=.{1,64}$)[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D';

    private const string REQUEST_ACTION = 'request';

    private const string DESIGN_REVIEW_ROLE = 'design-review';

    /** The severities of an agent review finding, in their order. */
    public const array AGENT_REVIEW_SEVERITIES = ['important', 'nit', 'pre-existing'];

    public const array DEFAULT_AGENT_REVIEW_FAILING_SEVERITIES = ['important'];

    /**
     * @param list<TemplateCardType>          $types
     * @param list<Slot>                      $slots
     * @param list<Rule>                      $rules
     * @param list<ManualMove>                $manualMoves
     * @param list<int>                       $backoffMinutes
     * @param array<string, list<ActionCall>> $childChoices                     the actions of each choice an agent states for a card it files under a parent, by `inherit` or `own`
     * @param ?string                         $epicBranch                       the branch of an epic, with the card number as the placeholder, or null when the template has no epic branches
     * @param list<string>                    $agentReviewFailingSeverities     the severities of a finding that fail an agent review
     * @param list<TemplateMarker>            $markers                          the card markers, in template order
     * @param bool                            $approvalCoversConflictResolution whether an approval still covers a merge from the base that holds a conflict resolution
     * @param list<string>                    $inbox                            the keys of the markers that store one inbox entry per thing, in template order
     * @param list<TemplateOutcome>           $outcomes                         the outcomes a move may set, in template order
     * @param list<TemplateMetric>            $metrics                          the metrics the template declares, in template order
     * @param list<RepositorySuggestion>      $repositorySettings               the settings the template suggests for a connected repository, in template order
     * @param list<TemplateValueKey>          $values                           the value keys a card can hold, in template order
     */
    public function __construct(
        public string $key,
        public int $version,
        public array $slots,
        public array $rules,
        public array $manualMoves,
        public array $backoffMinutes,
        public int $workTimeoutMinutes,
        public array $types,
        public string $defaultType,
        public ?WorkFailurePolicy $onWorkFailed = null,
        public array $childChoices = [],
        public ?string $epicBranch = null,
        public array $agentReviewFailingSeverities = self::DEFAULT_AGENT_REVIEW_FAILING_SEVERITIES,
        public array $markers = [],
        public bool $approvalCoversConflictResolution = true,
        public array $inbox = [],
        public array $outcomes = [],
        public array $metrics = [],
        public array $repositorySettings = [],
        public array $values = [],
    ) {
    }

    public function epicBranchOf(int $number): ?string
    {
        return null === $this->epicBranch ? null : str_replace(self::EPIC_BRANCH_NUMBER, (string) $number, $this->epicBranch);
    }

    /** @return list<string> the document tag of each rule whose request has the design review role, each once */
    public function designReviewTags(): array
    {
        return self::designReviewTagsOf($this->rules);
    }

    /** @return list<string> the document tag of each design review request rule that can act in the slot, each once */
    public function designReviewTagsIn(?string $slot): array
    {
        return self::designReviewTagsOf($this->rulesFor($slot));
    }

    /**
     * @param list<Rule> $rules
     *
     * @return list<string>
     */
    private static function designReviewTagsOf(array $rules): array
    {
        $tags = [];
        foreach ($rules as $rule) {
            $tag = $rule->lead->params[ParameterNames::DOCUMENT_TAG] ?? null;
            if (self::REQUEST_ACTION === $rule->lead->key && self::DESIGN_REVIEW_ROLE === ($rule->lead->params['role'] ?? null) && \is_string($tag)) {
                $tags[] = $tag;
            }
        }

        return array_values(array_unique($tags));
    }

    public function type(string $key): ?TemplateCardType
    {
        foreach ($this->types as $type) {
            if ($type->key === $key) {
                return $type;
            }
        }

        return null;
    }

    public function slot(string $key): ?Slot
    {
        foreach ($this->slots as $slot) {
            if ($slot->key === $key) {
                return $slot;
            }
        }

        return null;
    }

    /** @return list<Rule> the rules of the slot and the global rules that can act in it, in template order */
    public function rulesFor(?string $slot): array
    {
        return array_values(array_filter(
            $this->rules,
            static fn (Rule $rule): bool => (null === $rule->slot || $rule->slot === $slot)
                && array_all($rule->then, static fn (ActionCall $call): bool => null === $call->from || $call->from === $slot),
        ));
    }

    /** @return list<string> the document tags that the rules of the slot read, each once */
    public function documentTagsFor(?string $slot): array
    {
        $tags = [];
        foreach ($this->rulesFor($slot) as $rule) {
            foreach ($rule->when->leaves() as $leaf) {
                $tag = $leaf->condition instanceof ReadsDocumentTag ? $leaf->condition->documentTag($leaf->params) : null;
                if (null !== $tag) {
                    $tags[] = $tag;
                }
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * The smallest `rounds` of a review-loop check that a rule publishing a document with one of the tags requires.
     *
     * @param list<string> $tags the tags of one document
     */
    public function reviewRoundLimit(array $tags): ?int
    {
        $limits = [];
        foreach ($this->rules as $rule) {
            if (!$rule->lead->traits->publishesDocuments) {
                continue;
            }
            $published = $rule->lead->lists['tags'] ?? [];
            foreach ($rule->when->requiredLeaves() as $leaf) {
                $condition = $leaf->condition;
                if (!$condition instanceof EndsReviewLoop || !$condition instanceof ReadsDocumentTag) {
                    continue;
                }
                $tag = $condition->documentTag($leaf->params);
                if (null !== $tag && \in_array($tag, $tags, true) && \in_array($tag, $published, true)) {
                    $limits[] = $condition->roundLimit($leaf->params);
                }
            }
        }

        return [] === $limits ? null : min($limits);
    }
}
