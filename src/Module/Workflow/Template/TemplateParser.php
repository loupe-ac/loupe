<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Action\EvaluateChildren;
use App\Module\Workflow\Action\MissingAction;
use App\Module\Workflow\Condition\Conditions;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\ActionTraits;
use App\Module\Workflow\Contract\CardValueType;
use App\Module\Workflow\Contract\ChecksParameters;
use App\Module\Workflow\Contract\ChildFacts;
use App\Module\Workflow\Contract\DocumentStatuses;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\LabelTone;
use App\Module\Workflow\Contract\MarkerKind;
use App\Module\Workflow\Contract\MarkerLink;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterNames;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\PullRequestFacts;
use App\Module\Workflow\Contract\ReadsCardType;
use App\Module\Workflow\Contract\RepositorySuggestion;
use App\Module\Workflow\Contract\RepositorySuggestionKey;
use App\Module\Workflow\Contract\SuggestionMark;
use App\Module\Workflow\Contract\TemplateOutcome;
use App\Module\Workflow\Contract\TemplateValueKey;
use App\Module\Workflow\Contract\WorkKind;
use App\Module\Workflow\Expression\AllOf;
use App\Module\Workflow\Expression\AnyOf;
use App\Module\Workflow\Expression\ConditionLeaf;
use App\Module\Workflow\Expression\Expression;
use App\Module\Workflow\Expression\MissingActionLeaf;
use App\Module\Workflow\Expression\MissingConditionLeaf;
use App\Module\Workflow\Expression\NamedFact;
use App\Module\Workflow\Expression\Not;

/**
 * Turns a template array, from a shipped YAML file or from a stored JSON copy, into a Template.
 * It collects every error before it throws, so one run reports all of them.
 */
final readonly class TemplateParser
{
    private const array COLUMN_FLAGS = ['@backlog', '@terminal'];
    private const string ANY_COLUMN = '*';
    private const array RULE_KEYS = ['id', 'slot', 'when', 'then'];
    private const array CHILD_CHOICES = ['inherit', 'own'];
    private const array TYPE_KEYS = ['key', 'label', 'tone', 'capabilities'];
    private const int TYPE_KEY_MAX_LENGTH = 20;
    private const array TYPE_CAPABILITIES = ['children', 'lane'];
    private const string FACT_NAME = '/^[a-z][a-z0-9-]*$/D';
    private const string FACT_PREFIX = 'fact.';
    private const array MARKER_KEYS = ['key', 'kind', 'label', 'remedy', 'slots', 'when', 'fallback', 'link'];

    /** The names an action call keeps for the parameter types that have a field of their own. */
    private const array KEPT_PARAMETERS = ['expression' => ['until', 'refill', 'cancelWhen'], 'list' => ['checks', 'tags', 'methods'], 'options' => ['options']];

    /** The parameters that hold the tag and the status of a request's `document` map. A template cannot write them. */
    public const string DOCUMENT_TAG = ParameterNames::DOCUMENT_TAG;
    public const string DOCUMENT_STATUS = ParameterNames::DOCUMENT_STATUS;

    /** The parameter of a request that names a prompt file. */
    public const string PROMPT = ParameterNames::PROMPT;
    public const string PROMPT_PATTERN = ParameterNames::PROMPT_PATTERN;

    public function __construct(
        private Conditions $conditions,
        private Actions $actions,
        private TemplatePrompts $prompts,
        private TemplateMetricParser $metricParser,
    ) {
    }

    /**
     * @param array<mixed> $source
     *
     * @throws InvalidTemplate
     */
    public function parse(array $source): Template
    {
        return $this->parseWith($source, lenient: false);
    }

    /**
     * Parses a stored copy. A condition this instance no longer has becomes a leaf that is never readable.
     * A rule whose action this version does not know never fires.
     *
     * @param array<mixed> $source
     *
     * @throws InvalidTemplate on every other error
     */
    public function parseStored(array $source): Template
    {
        return $this->parseWith($source, lenient: true);
    }

    /**
     * Parses the rules the app adds to every template. They act in the backlog, in a terminal column or in every column.
     *
     * @param array<mixed> $source
     *
     * @return list<Rule>
     *
     * @throws InvalidTemplate
     */
    public function parseAppRules(array $source): array
    {
        $errors = [];
        foreach (array_keys($source) as $key) {
            if (!\in_array($key, ['rules', 'requests', 'proposalSlot'], true)) {
                $errors[] = \sprintf('%s: unknown key, app rules hold only "rules", "requests" and "proposalSlot"', $key);
            }
        }
        if (\array_key_exists('proposalSlot', $source) && (!\is_string($source['proposalSlot']) || '' === $source['proposalSlot'])) {
            $errors[] = 'proposalSlot: must be a non-empty string';
        }
        $rules = $this->rules(self::topLevelList($source, 'rules', $errors), [], null, new NamedFacts([]), $errors, lenient: false, app: true);

        if ([] !== $errors) {
            throw new InvalidTemplate($errors);
        }

        return $rules;
    }

    /**
     * The slot key of the column where the cards of ticked proposals land, or null when the file names none.
     *
     * @param array<mixed> $source
     */
    public function parseAppProposalSlot(array $source): ?string
    {
        $slot = $source['proposalSlot'] ?? null;

        return \is_string($slot) && '' !== $slot ? $slot : null;
    }

    /**
     * Parses the work the app asks a bridge for outside the engine. The key is optional.
     *
     * @param array<mixed> $source
     *
     * @return list<AppRequest>
     *
     * @throws InvalidTemplate
     */
    public function parseAppRequests(array $source): array
    {
        $given = $source['requests'] ?? [];
        if (!\is_array($given) || !array_is_list($given)) {
            throw new InvalidTemplate(['requests: must be a list']);
        }

        $errors = [];
        $requests = [];
        $seen = [];
        foreach ($given as $index => $entry) {
            $where = \sprintf('requests[%d]', $index);
            if (!self::isMap($entry)) {
                $errors[] = $where.': must be a map';
                continue;
            }
            $errorCount = \count($errors);

            $id = $entry['id'] ?? null;
            if (!\is_string($id) || '' === $id) {
                $errors[] = $where.' id: must be a non-empty string';
            } else {
                $where .= \sprintf(' (%s)', $id);
                if (isset($seen[$id])) {
                    $errors[] = \sprintf('%s: duplicate request id "%s"', $where, $id);
                }
                $seen[$id] = true;
            }
            $kind = $entry['kind'] ?? null;
            if (!\is_string($kind) || '' === $kind) {
                $errors[] = $where.': parameter "kind" must be a non-empty string';
            }
            $prompt = $entry['prompt'] ?? null;
            if (!\is_string($prompt) || 1 !== preg_match(self::PROMPT_PATTERN, $prompt)) {
                $errors[] = $where.': parameter "prompt" must match [a-z][a-z0-9-], at most 40 characters';
            }
            $checks = self::checks($entry['checks'] ?? null, $where, 'checks', $errors);
            foreach (array_keys($entry) as $name) {
                if (!\in_array($name, ['id', 'kind', 'prompt', 'checks'], true)) {
                    $errors[] = \sprintf('%s: unknown key "%s"', $where, $name);
                }
            }

            if (\count($errors) === $errorCount && \is_string($id) && \is_string($kind) && \is_string($prompt)) {
                $requests[] = new AppRequest($id, $kind, $prompt, $checks);
            }
        }

        if ([] !== $errors) {
            throw new InvalidTemplate($errors);
        }

        return $requests;
    }

    /** @param array<mixed> $source */
    private function parseWith(array $source, bool $lenient): Template
    {
        $errors = [];

        $key = $source['key'] ?? null;
        if (!\is_string($key) || '' === $key) {
            $errors[] = self::topLevelError($source, 'key', 'must be a non-empty string');
            $key = '';
        }

        $version = $source['version'] ?? null;
        if (!\is_int($version)) {
            $errors[] = self::topLevelError($source, 'version', 'must be an integer');
            $version = 0;
        }

        $types = self::types($source, $errors);
        $defaultType = $source['defaultType'] ?? null;
        if (!\is_string($defaultType) || '' === $defaultType) {
            $errors[] = self::topLevelError($source, 'defaultType', 'must be a non-empty string');
            $defaultType = '';
        } elseif (null !== $types && !isset($types[$defaultType])) {
            $errors[] = \sprintf('defaultType: unknown type "%s"', $defaultType);
        } elseif (null !== $types && $types[$defaultType]->children) {
            // A widget note under a parent creates a card of the default type, and a parent-capable card cannot have a parent.
            $errors[] = \sprintf('defaultType: type "%s" may have children', $defaultType);
        }

        $workTimeoutMinutes = $source['workTimeoutMinutes'] ?? null;
        if (!\is_int($workTimeoutMinutes) || $workTimeoutMinutes < 1) {
            $errors[] = self::topLevelError($source, 'workTimeoutMinutes', 'must be a positive integer');
            $workTimeoutMinutes = 0;
        }

        $backoffMinutes = self::backoffMinutes($source['backoffMinutes'] ?? null);
        if (null === $backoffMinutes) {
            $errors[] = self::topLevelError($source, 'backoffMinutes', 'must be a list of positive integers');
            $backoffMinutes = [];
        }

        $onWorkFailed = self::onWorkFailed($source['onWorkFailed'] ?? null, $errors);
        $epicBranch = self::epicBranch($source['epicBranch'] ?? null, $lenient, $errors);
        $failingSeverities = self::agentReviewFailingSeverities($source['agentReviewFailingSeverities'] ?? null, $lenient, $errors);
        $approvalCoversConflictResolution = \array_key_exists('approvalCoversConflictResolution', $source)
            ? self::approvalCoversConflictResolution($source['approvalCoversConflictResolution'], $lenient, $errors)
            : true;

        $slots = $this->slots(self::topLevelList($source, 'slots', $errors), $errors);
        $slotKeys = array_map(static fn (Slot $slot): string => $slot->key, $slots);
        $facts = new NamedFacts(self::factSources($source['facts'] ?? null, $errors));
        foreach (array_keys($facts->sources) as $name) {
            $this->namedFact((string) $name, $slotKeys, $types, $facts, $errors, $lenient);
        }
        $childChoices = $this->childChoices($source['childChoices'] ?? null, $slotKeys, $types, $facts, $errors, $lenient);
        $manualMoves = $this->manualMoves(self::topLevelList($source, 'manualMoves', $errors), $slotKeys, $errors);
        $rules = $this->rules(self::topLevelList($source, 'rules', $errors), $slotKeys, $types, $facts, $errors, $lenient, app: false);
        $outcomes = $this->metricParser->outcomes($source['outcomes'] ?? null, $lenient, $errors);
        if (!$lenient) {
            array_push($errors, ...$this->unknownOutcomes($rules, $childChoices, $outcomes));
        }
        $metrics = $this->metricParser->metrics($source['metrics'] ?? null, $slotKeys, $outcomes, self::producedWork($rules, $childChoices, $onWorkFailed), $lenient, $errors);
        $markers = $this->markers($source['markers'] ?? [], $slotKeys, $types, $facts, $errors, $lenient);
        $inbox = self::inbox($source['inbox'] ?? [], $markers, $errors);
        $repositorySettings = \array_key_exists('repositorySettings', $source)
            ? self::repositorySettings($source['repositorySettings'], $lenient, $errors)
            : [];
        $values = \array_key_exists('values', $source)
            ? self::valueKeys($source['values'], $lenient, $errors)
            : [];
        if (!$lenient) {
            foreach ($rules as $rule) {
                foreach ($rule->then as $call) {
                    $name = $call->params[self::PROMPT] ?? null;
                    if (\is_string($name) && !$this->prompts->has($key, $name)) {
                        $errors[] = \sprintf('rules (%s): prompt "%s" has no file %s/prompts/%s.md', $rule->id, $name, $key, $name);
                    }
                }
            }
        }
        // The engine tells a repair request apart by its kind, so no rule may ask for that kind.
        foreach ($rules as $rule) {
            foreach ($rule->then as $call) {
                $kind = $this->actions->get($call->key)->workKind($call->params);
                if (null !== $onWorkFailed?->repairKind && $onWorkFailed->repairKind === $kind) {
                    $errors[] = \sprintf('onWorkFailed.repair.kind: the rule "%s" already asks for the kind "%s"', $rule->id, $onWorkFailed->repairKind);
                }
            }
        }

        foreach ($slots as $slot) {
            if (null !== $slot->stageWork && !array_any($rules, fn (Rule $rule): bool => $rule->slot === $slot->key && array_any($rule->then, fn (ActionCall $call): bool => $this->actions->get($call->key)->workKind($call->params) === $slot->stageWork))) {
                $errors[] = \sprintf('slots: stageWork "%s" of slot "%s" is the kind of no rule of that slot', $slot->stageWork, $slot->key);
            }
        }

        if ([] !== $errors) {
            throw new InvalidTemplate($errors);
        }

        return new Template($key, $version, $slots, $rules, $manualMoves, $backoffMinutes, $workTimeoutMinutes, array_values($types ?? []), $defaultType, $onWorkFailed, $childChoices, $epicBranch, $failingSeverities, $markers, $approvalCoversConflictResolution, $inbox, $outcomes, $metrics, $repositorySettings, $values);
    }

    /**
     * @param list<string> $errors
     *
     * @return list<TemplateValueKey>
     */
    private static function valueKeys(mixed $given, bool $lenient, array &$errors): array
    {
        if (!\is_array($given) || !array_is_list($given)) {
            if (!$lenient) {
                $errors[] = 'values: must be a list of value keys';
            }

            return [];
        }
        $fields = ['key', 'type', 'label', 'caption', 'help', 'history'];
        $values = [];
        $listed = [];
        foreach ($given as $index => $entry) {
            $where = \sprintf('values[%d]', $index);
            if (!\is_array($entry) || array_is_list($entry) && [] !== $entry) {
                $error = $where.': must be a map';
            } elseif ([] !== ($unknown = array_diff(array_keys($entry), $fields))) {
                $error = \sprintf('%s: unknown field "%s"', $where, reset($unknown));
            } elseif (!\is_string($entry['key'] ?? null) || 1 !== preg_match(TemplateValueKey::KEY_PATTERN, $entry['key'])) {
                $error = \sprintf('%s: key "%s" must be lowercase letters, digits and hyphens', $where, \is_scalar($entry['key'] ?? null) ? $entry['key'] : get_debug_type($entry['key'] ?? null));
            } elseif (isset($listed[$entry['key']])) {
                $error = \sprintf('%s: duplicate key "%s"', $where, $entry['key']);
            } elseif (!\is_string($entry['type'] ?? null) || null === ($type = CardValueType::tryFrom($entry['type']))) {
                $error = \sprintf('%s: unknown type "%s"', $where, \is_scalar($entry['type'] ?? null) ? $entry['type'] : get_debug_type($entry['type'] ?? null));
            } else {
                $error = null;
                foreach (['label', 'caption', 'help', 'history'] as $field) {
                    if (!\is_string($entry[$field] ?? null) || '' === $entry[$field]) {
                        $error = \sprintf('%s: %s must be a non-empty string', $where, $field);
                        break;
                    }
                }
                if (null === $error) {
                    $listed[$entry['key']] = true;
                    $values[] = new TemplateValueKey($entry['key'], $type, $entry['label'], $entry['caption'], $entry['help'], $entry['history']);

                    continue;
                }
            }
            if (!$lenient) {
                $errors[] = $error;
            }
        }

        return $values;
    }

    /**
     * @param list<Rule>                      $rules
     * @param array<string, list<ActionCall>> $childChoices
     * @param list<TemplateOutcome>           $outcomes
     *
     * @return list<string>
     */
    private function unknownOutcomes(array $rules, array $childChoices, array $outcomes): array
    {
        $known = array_map(static fn (TemplateOutcome $outcome): string => $outcome->key, $outcomes);
        $errors = [];
        foreach ($rules as $rule) {
            array_push($errors, ...$this->unknownOutcomesOf($rule->then, \sprintf('rules (%s)', $rule->id), $known));
        }
        foreach ($childChoices as $choice => $calls) {
            array_push($errors, ...$this->unknownOutcomesOf($calls, 'childChoices.'.$choice, $known));
        }

        return $errors;
    }

    /**
     * @param list<ActionCall> $calls
     * @param list<string>     $known
     *
     * @return list<string>
     */
    private function unknownOutcomesOf(array $calls, string $where, array $known): array
    {
        $errors = [];
        foreach (self::flatten($calls) as $call) {
            foreach ($this->actions->get($call->key)::parameters() as $parameter) {
                $value = $call->params[$parameter->name] ?? null;
                if (ParameterType::Outcome === $parameter->type && null !== $value && !\in_array($value, $known, true)) {
                    $errors[] = \sprintf('%s: unknown outcome "%s"%s', $where, $value, [] === $known ? '; the template declares no outcomes' : '; known outcomes: '.implode(', ', $known));
                }
            }
        }

        return $errors;
    }

    /**
     * @param list<ActionCall> $calls
     *
     * @return list<ActionCall> the calls and the calls inside their ask options
     */
    private static function flatten(array $calls): array
    {
        $all = [];
        foreach ($calls as $call) {
            $all[] = $call;
            foreach ($call->options as $option) {
                array_push($all, ...self::flatten($option->actions));
            }
        }

        return $all;
    }

    /**
     * What the request calls can write on a work request, and the outcomes the move calls can set. A call with no reason
     * adds the fix reasons of a pull request and `unknown`. The repair kind of `onWorkFailed` is a kind too.
     *
     * @param list<Rule>                      $rules
     * @param array<string, list<ActionCall>> $childChoices
     */
    private static function producedWork(array $rules, array $childChoices, ?WorkFailurePolicy $onWorkFailed): ProducedWork
    {
        $calls = [];
        foreach ($rules as $rule) {
            array_push($calls, ...self::flatten($rule->then));
        }
        foreach ($childChoices as $choiceCalls) {
            array_push($calls, ...self::flatten($choiceCalls));
        }
        $kinds = null === $onWorkFailed?->repairKind ? [] : [$onWorkFailed->repairKind];
        $requests = null === $onWorkFailed?->repairKind ? [] : [new ProducedRequest($onWorkFailed->repairKind, null, null)];
        $roles = [];
        $outcomes = [];
        foreach ($calls as $call) {
            if ('move' === $call->key && isset($call->params['outcome'])) {
                $outcomes[] = (string) $call->params['outcome'];
            }
            if ('request' !== $call->key) {
                continue;
            }
            $kinds[] = (string) $call->params['kind'];
            if (isset($call->params['role'])) {
                $roles[] = (string) $call->params['role'];
            }
            $requests[] = new ProducedRequest(
                (string) $call->params['kind'],
                isset($call->params['role']) ? (string) $call->params['role'] : null,
                isset($call->params['reason']) ? [(string) $call->params['reason']] : [...PullRequestFacts::FIX_REASONS, 'unknown'],
            );
        }

        $reasons = array_merge(...array_map(static fn (ProducedRequest $request): array => $request->reasons ?? [], $requests));

        return new ProducedWork(array_values(array_unique($kinds)), array_values(array_unique($roles)), array_values(array_unique($reasons)), array_values(array_unique($outcomes)), $requests);
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, mixed> the expression of each valid fact by its name
     */
    private static function factSources(mixed $given, array &$errors): array
    {
        if (null === $given) {
            return [];
        }
        if (!self::isMap($given)) {
            $errors[] = 'facts: must be a map from a name to an expression';

            return [];
        }
        $sources = [];
        foreach ($given as $name => $node) {
            if (1 !== preg_match(self::FACT_NAME, (string) $name)) {
                $errors[] = \sprintf('facts.%s: the name must match [a-z][a-z0-9-]', $name);
                continue;
            }
            $sources[(string) $name] = $node;
        }

        return $sources;
    }

    /**
     * @param array<mixed> $source
     * @param list<string> $errors
     *
     * @return ?array<string, TemplateCardType> each type by its key, or null when the block holds an error
     */
    private static function types(array $source, array &$errors): ?array
    {
        $value = $source['types'] ?? null;
        if (!\is_array($value) || [] === $value || !array_is_list($value)) {
            $errors[] = self::topLevelError($source, 'types', 'must be a non-empty list');

            return null;
        }
        $errorCount = \count($errors);
        $types = [];
        foreach ($value as $index => $entry) {
            $where = \sprintf('types[%d]', $index);
            $key = self::isMap($entry) ? ($entry['key'] ?? null) : null;
            $label = self::isMap($entry) ? ($entry['label'] ?? null) : null;
            if (!self::isMap($entry) || !\is_string($key) || '' === $key || !\is_string($label) || '' === $label) {
                $errors[] = $where.': must be a map with a string "key" and a string "label"';
                continue;
            }
            $where .= \sprintf(' (%s)', $key);
            $entryErrorCount = \count($errors);
            if (mb_strlen($key) > self::TYPE_KEY_MAX_LENGTH) {
                $errors[] = \sprintf('%s: "key" must have at most %d characters', $where, self::TYPE_KEY_MAX_LENGTH);
            }
            foreach (array_keys($entry) as $name) {
                if (!\in_array($name, self::TYPE_KEYS, true)) {
                    $errors[] = \sprintf('%s: unknown key "%s"', $where, $name);
                }
            }
            $tone = $entry['tone'] ?? null;
            $tone = \is_string($tone) ? LabelTone::tryFrom($tone) : null;
            if (null === $tone) {
                $errors[] = \sprintf('%s: "tone" must be one of %s', $where, implode(', ', array_column(LabelTone::cases(), 'value')));
            }
            $capabilities = $entry['capabilities'] ?? [];
            if (!\is_array($capabilities) || !array_is_list($capabilities)
                || array_any($capabilities, static fn (mixed $capability): bool => !\in_array($capability, self::TYPE_CAPABILITIES, true))) {
                $errors[] = \sprintf('%s: "capabilities" must be a list of %s', $where, implode(', ', self::TYPE_CAPABILITIES));
                $capabilities = [];
            }
            if (isset($types[$key])) {
                $errors[] = \sprintf('%s: duplicate type key "%s"', $where, $key);
            }
            if (null !== $tone && \count($errors) === $entryErrorCount) {
                $types[$key] = new TemplateCardType($key, $label, $tone, \in_array('children', $capabilities, true), \in_array('lane', $capabilities, true));
            }
        }

        return \count($errors) === $errorCount ? $types : null;
    }

    /**
     * @param array<string, TemplateCardType> $types
     *
     * @return list<string> the keys of the types without children that the rule reads the children of
     */
    private static function childlessTypesReadingChildren(Rule $rule, array $types): array
    {
        $evaluatesChildren = EvaluateChildren::KEY === $rule->lead->key && 'children' === ($rule->lead->params['cards'] ?? null);
        // The engine reads a pause `until`, a request `refill` and a request `cancelWhen` on a card that the `when` matched.
        $ways = self::typeReads($rule->when);
        foreach (array_filter([$rule->lead->until, $rule->lead->refill, $rule->lead->cancelWhen]) as $expression) {
            $ways += self::typeReads(new AllOf([$rule->when, $expression]));
        }
        $found = [];
        foreach ($ways as [$key, $readsChildren]) {
            $type = null === $key ? null : ($types[$key] ?? null);
            if (null !== $type && !$type->children && ($readsChildren || $evaluatesChildren)) {
                $found[$type->key] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * Each way the expression can hold, as the one type it names and whether it reads the children.
     * The set stays small, because a pair repeats and a way that names two types never holds.
     * A `not` names no type, because it turns the type leaves under it around.
     *
     * @return array<string, array{?string, bool}>
     */
    private static function typeReads(Expression $expression): array
    {
        if ($expression instanceof NamedFact) {
            return self::typeReads($expression->expression);
        }
        if ($expression instanceof AnyOf) {
            return array_merge(...array_map(self::typeReads(...), $expression->children));
        }
        if ($expression instanceof AllOf) {
            $ways = self::typeRead(null, false);
            foreach ($expression->children as $child) {
                $next = [];
                foreach ($ways as [$type, $reads]) {
                    foreach (self::typeReads($child) as [$childType, $childReads]) {
                        if (null === $type || null === $childType || $type === $childType) {
                            $next += self::typeRead($type ?? $childType, $reads || $childReads);
                        }
                    }
                }
                $ways = $next;
            }

            return $ways;
        }
        $readsChildren = array_any($expression->reads(), static fn (EngineFact|string $read): bool => \is_string($read) && is_a($read, ChildFacts::class, true));
        if (!$expression instanceof ConditionLeaf || !$expression->condition instanceof ReadsCardType) {
            return self::typeRead(null, $readsChildren);
        }

        return array_merge(...array_map(static fn (string $type): array => self::typeRead($type, $readsChildren), $expression->condition->cardType($expression->params)));
    }

    /** @return array<string, array{?string, bool}> */
    private static function typeRead(?string $type, bool $readsChildren): array
    {
        return [json_encode([$type, $readsChildren], \JSON_THROW_ON_ERROR) => [$type, $readsChildren]];
    }

    /**
     * @param list<mixed>  $source
     * @param list<string> $errors
     *
     * @return list<Slot>
     */
    private function slots(array $source, array &$errors): array
    {
        $slots = [];
        $seen = [];
        foreach ($source as $index => $entry) {
            $where = \sprintf('slots[%d]', $index);
            $key = \is_array($entry) ? ($entry['key'] ?? null) : null;
            $label = \is_array($entry) ? ($entry['label'] ?? null) : null;
            if (!\is_string($key) || '' === $key || !\is_string($label) || '' === $label) {
                $errors[] = $where.': must be a map with a string "key" and a string "label"';
                continue;
            }
            if (str_starts_with($key, '@')) {
                $errors[] = \sprintf('%s: slot key "%s" must not start with "@"', $where, $key);
                continue;
            }
            if (self::ANY_COLUMN === $key) {
                $errors[] = \sprintf('%s: slot key "%s" is reserved', $where, $key);
                continue;
            }
            if (isset($seen[$key])) {
                $errors[] = \sprintf('%s: duplicate slot key "%s"', $where, $key);
                continue;
            }
            $stageWork = $entry['stageWork'] ?? null;
            if (\array_key_exists('stageWork', $entry) && (!\is_string($stageWork) || 1 !== preg_match(WorkKind::PATTERN, $stageWork))) {
                $errors[] = $where.' stageWork: must be a work request kind';
                $stageWork = null;
            }
            $seen[$key] = true;
            $tone = null;
            if (\array_key_exists('tone', $entry)) {
                $tone = \is_string($entry['tone']) ? LabelTone::tryFrom($entry['tone']) : null;
                if (null === $tone) {
                    $errors[] = \sprintf('%s tone: must be one of %s', $where, implode(', ', array_map(static fn (LabelTone $case): string => $case->value, LabelTone::cases())));
                }
            }
            $slots[] = new Slot($key, $label, $stageWork, $tone);
        }

        return $slots;
    }

    /**
     * @param list<mixed>  $source
     * @param list<string> $slotKeys
     * @param list<string> $errors
     *
     * @return list<ManualMove>
     */
    private function manualMoves(array $source, array $slotKeys, array &$errors): array
    {
        $moves = [];
        foreach ($source as $index => $entry) {
            $where = \sprintf('manualMoves[%d]', $index);
            $from = \is_array($entry) ? ($entry['from'] ?? null) : null;
            $to = \is_array($entry) ? ($entry['to'] ?? null) : null;
            if (!\is_string($from) || !\is_string($to)) {
                $errors[] = $where.': must be a map with a string "from" and a string "to"';
                continue;
            }
            $valid = true;
            foreach (['from' => $from, 'to' => $to] as $end => $value) {
                if (!self::isColumn($value, $slotKeys, true)) {
                    $errors[] = \sprintf('%s.%s: unknown slot "%s"', $where, $end, $value);
                    $valid = false;
                }
            }
            $byValue = \is_array($entry) ? ($entry['by'] ?? null) : null;
            $by = null;
            if (null !== $byValue) {
                $by = \is_string($byValue) ? ManualMoveActor::tryFrom($byValue) : null;
                if (null === $by) {
                    $errors[] = \sprintf('%s.by: must be one of %s', $where, implode(', ', array_column(ManualMoveActor::cases(), 'value')));
                    $valid = false;
                }
            }
            if ($valid) {
                $moves[] = new ManualMove($from, $to, $by);
            }
        }

        return $moves;
    }

    /**
     * @param list<mixed>                      $source
     * @param list<string>                     $slotKeys
     * @param ?array<string, TemplateCardType> $types    null skips the type checks
     * @param list<string>                     $errors
     *
     * @return list<Rule>
     */
    private function rules(array $source, array $slotKeys, ?array $types, NamedFacts $facts, array &$errors, bool $lenient, bool $app): array
    {
        $rules = [];
        $seen = [];
        foreach ($source as $index => $entry) {
            $where = \sprintf('rules[%d]', $index);
            if (!self::isMap($entry)) {
                $errors[] = $where.': must be a map';
                continue;
            }
            $errorCount = \count($errors);

            $id = $entry['id'] ?? null;
            if (!\is_string($id) || '' === $id) {
                $errors[] = $where.' id: must be a non-empty string';
                $id = '';
            } else {
                $where .= \sprintf(' (%s)', $id);
                if (isset($seen[$id])) {
                    $errors[] = \sprintf('%s: duplicate rule id "%s"', $where, $id);
                }
                $seen[$id] = true;
            }

            foreach (array_keys($entry) as $name) {
                if (!\in_array($name, self::RULE_KEYS, true)) {
                    $errors[] = \sprintf('%s: unknown key "%s"', $where, $name);
                }
            }

            $slot = $entry['slot'] ?? null;
            if (\array_key_exists('slot', $entry)) {
                if (!\is_string($slot)) {
                    $errors[] = $where.' slot: must be a string';
                } elseif (!self::isColumn($slot, $slotKeys, false)) {
                    $errors[] = \sprintf('%s slot: unknown slot "%s"', $where, $slot);
                }
            }

            $when = null;
            if (\array_key_exists('when', $entry)) {
                $when = $this->expression($entry['when'], $where.' when', $slotKeys, $types, $facts, $errors, $lenient);
            } else {
                $errors[] = $where.' when: is missing';
            }

            $then = null;
            if (\array_key_exists('then', $entry)) {
                $then = $this->actionsOfRule($entry['then'], $where.' then', $slotKeys, $types, $facts, $errors, $lenient);
            } else {
                $errors[] = $where.' then: is missing';
            }

            $unknownAction = null === $then ? null : self::unknownAction($then);
            if (null !== $then && null !== $unknownAction && null !== $when) {
                $when = new AllOf([new MissingActionLeaf($unknownAction), $when]);
                $then = [new ActionCall(MissingAction::KEY, ['name' => $unknownAction], traits: MissingAction::traits(), from: $then[0]->from)];
            }

            if (\count($errors) === $errorCount && null !== $when && null !== $then && (null === $slot || \is_string($slot))) {
                $rule = new Rule($id, $slot, $when, $then, $app ? RuleOrigin::App : RuleOrigin::Template);
                $rules[] = $rule;
                foreach (null === $types ? [] : self::childlessTypesReadingChildren($rule, $types) as $type) {
                    $errors[] = \sprintf('%s: the type "%s" may not have children, but the rule reads them', $where, $type);
                }
            }
        }

        return $rules;
    }

    /**
     * A stored copy drops an entry it cannot read.
     *
     * @param list<string> $errors
     *
     * @return list<RepositorySuggestion>
     */
    private static function repositorySettings(mixed $given, bool $lenient, array &$errors): array
    {
        if (!\is_array($given) || !array_is_list($given)) {
            if (!$lenient) {
                $errors[] = 'repositorySettings: must be a list of suggestions';
            }

            return [];
        }
        $suggestions = [];
        $listed = [];
        foreach ($given as $index => $entry) {
            $where = \sprintf('repositorySettings[%d]', $index);
            $key = \is_array($entry) && \is_string($entry['key'] ?? null) ? RepositorySuggestionKey::tryFrom($entry['key']) : null;
            $mark = \is_array($entry) && \is_string($entry['mark'] ?? null) ? SuggestionMark::tryFrom($entry['mark']) : null;
            if (!\is_array($entry) || [] !== array_diff(array_keys($entry), ['key', 'mark'])) {
                $error = $where.': must hold a key and a mark only';
            } elseif (null === $key) {
                $error = \sprintf('%s: unknown key "%s"', $where, \is_scalar($entry['key'] ?? null) ? $entry['key'] : get_debug_type($entry['key'] ?? null));
            } elseif (null === $mark) {
                $error = \sprintf('%s: mark must be blocking or warning', $where);
            } elseif (isset($listed[$key->value])) {
                $error = \sprintf('%s: duplicate key "%s"', $where, $key->value);
            } else {
                $listed[$key->value] = true;
                $suggestions[] = new RepositorySuggestion($key, $mark);

                continue;
            }
            if (!$lenient) {
                $errors[] = $error;
            }
        }

        return $suggestions;
    }

    /**
     * @param list<TemplateMarker> $markers
     * @param list<string>         $errors
     *
     * @return list<string>
     */
    private static function inbox(mixed $given, array $markers, array &$errors): array
    {
        if (!\is_array($given) || !array_is_list($given)) {
            $errors[] = 'inbox: must be a list of marker keys';

            return [];
        }
        $byKey = [];
        foreach ($markers as $marker) {
            $byKey[$marker->key] = $marker;
        }
        $listed = [];
        foreach ($given as $key) {
            if (!\is_string($key) || !isset($byKey[$key])) {
                $errors[] = \sprintf('inbox: unknown marker "%s"', \is_string($key) ? $key : get_debug_type($key));
            } elseif (\in_array($key, $listed, true)) {
                $errors[] = \sprintf('inbox: duplicate marker "%s"', $key);
            } else {
                $listed[] = $key;
            }
        }

        return $listed;
    }

    /**
     * @param list<string>                     $slotKeys
     * @param ?array<string, TemplateCardType> $types
     * @param list<string>                     $errors
     *
     * @return list<TemplateMarker>
     */
    private function markers(mixed $given, array $slotKeys, ?array $types, NamedFacts $facts, array &$errors, bool $lenient): array
    {
        if (!\is_array($given) || !array_is_list($given)) {
            $errors[] = 'markers: must be a list';

            return [];
        }
        $markers = [];
        $seen = [];
        foreach ($given as $index => $entry) {
            $where = \sprintf('markers[%d]', $index);
            if (!self::isMap($entry)) {
                $errors[] = $where.': must be a map';
                continue;
            }
            $errorCount = \count($errors);

            $key = $entry['key'] ?? null;
            if (!\is_string($key) || '' === $key) {
                $errors[] = $where.' key: must be a non-empty string';
            } else {
                $where .= \sprintf(' (%s)', $key);
                if (isset($seen[$key])) {
                    $errors[] = \sprintf('%s: duplicate marker key "%s"', $where, $key);
                }
                $seen[$key] = true;
            }
            foreach (array_keys($entry) as $name) {
                if (!\in_array($name, self::MARKER_KEYS, true)) {
                    $errors[] = \sprintf('%s: unknown key "%s"', $where, $name);
                }
            }

            $kind = $entry['kind'] ?? null;
            $kind = \is_string($kind) ? MarkerKind::tryFrom($kind) : null;
            if (null === $kind) {
                $errors[] = \sprintf('%s kind: must be one of %s', $where, implode(', ', array_column(MarkerKind::cases(), 'value')));
            }
            $label = $entry['label'] ?? null;
            if (!\is_string($label) || '' === $label) {
                $errors[] = $where.' label: must be a non-empty string';
            }
            $remedy = $entry['remedy'] ?? null;
            if (null !== $remedy && (!\is_string($remedy) || '' === $remedy)) {
                $errors[] = $where.' remedy: must be a non-empty string';
                $remedy = null;
            }
            $link = null;
            if (\array_key_exists('link', $entry)) {
                $link = \is_string($entry['link']) ? MarkerLink::tryFrom($entry['link']) : null;
                if (null === $link) {
                    $errors[] = \sprintf('%s link: must be one of %s', $where, implode(', ', array_column(MarkerLink::cases(), 'value')));
                }
            }
            $fallback = $entry['fallback'] ?? false;
            if (!\is_bool($fallback)) {
                $errors[] = $where.' fallback: must be a boolean';
                $fallback = false;
            }
            $slots = $entry['slots'] ?? [];
            if (!\is_array($slots) || !array_is_list($slots)) {
                $errors[] = $where.' slots: must be a list of slot keys';
                $slots = [];
            }
            foreach ($slots as $slot) {
                if (!\is_string($slot) || !\in_array($slot, $slotKeys, true)) {
                    $errors[] = \sprintf('%s slots: unknown slot "%s"', $where, \is_string($slot) ? $slot : get_debug_type($slot));
                }
            }

            $when = null;
            if (\array_key_exists('when', $entry)) {
                $when = $this->expression($entry['when'], $where.' when', $slotKeys, $types, $facts, $errors, $lenient);
            } else {
                $errors[] = $where.' when: is missing';
            }

            if (\count($errors) === $errorCount && \is_string($key) && null !== $kind && \is_string($label) && null !== $when) {
                $markers[] = new TemplateMarker($key, $kind, $label, $remedy, array_values(array_unique($slots)), $when, $fallback, $link);
            }
        }

        return $markers;
    }

    /**
     * @param list<string>                     $slotKeys
     * @param ?array<string, TemplateCardType> $types
     * @param list<string>                     $errors
     */
    private function expression(mixed $node, string $where, array $slotKeys, ?array $types, NamedFacts $facts, array &$errors, bool $lenient): ?Expression
    {
        if (!\is_array($node) || 1 !== \count($node) || !\is_string(array_key_first($node))) {
            $errors[] = $where.': a node must have exactly one key';

            return null;
        }
        $key = array_key_first($node);
        $value = $node[$key];

        if ('not' === $key) {
            $inner = $this->expression($value, $where.'.not', $slotKeys, $types, $facts, $errors, $lenient);

            return null === $inner ? null : new Not($inner);
        }

        if ('all' === $key || 'any' === $key) {
            if (!\is_array($value) || !array_is_list($value) || ('any' === $key && [] === $value)) {
                $errors[] = \sprintf('%s.%s: must be a %slist', $where, $key, 'any' === $key ? 'non-empty ' : '');

                return null;
            }
            $children = [];
            foreach ($value as $index => $child) {
                $children[] = $this->expression($child, \sprintf('%s.%s[%d]', $where, $key, $index), $slotKeys, $types, $facts, $errors, $lenient);
            }
            $children = array_values(array_filter($children));
            if (\count($children) !== \count($value)) {
                return null;
            }

            if ('all' === $key || [] === $children) {
                return new AllOf($children);
            }

            return new AnyOf($children);
        }

        if (str_starts_with($key, self::FACT_PREFIX)) {
            return $this->factReference(substr($key, \strlen(self::FACT_PREFIX)), $value, $where, $slotKeys, $types, $facts, $errors, $lenient);
        }

        if ($lenient && !$this->conditions->has($key)) {
            return new MissingConditionLeaf($key, $value);
        }

        return $this->conditionLeaf($key, $value, $where, $slotKeys, $types, $errors);
    }

    /**
     * @param list<string>                     $slotKeys
     * @param ?array<string, TemplateCardType> $types
     * @param list<string>                     $errors
     */
    private function factReference(string $name, mixed $params, string $where, array $slotKeys, ?array $types, NamedFacts $facts, array &$errors, bool $lenient): ?NamedFact
    {
        if (!\array_key_exists($name, $facts->sources)) {
            $errors[] = \sprintf('%s: unknown fact "%s"', $where, $name);

            return null;
        }
        if ([] !== $params) {
            $errors[] = \sprintf('%s: the fact "%s" takes no parameters', $where, $name);

            return null;
        }

        return $this->namedFact($name, $slotKeys, $types, $facts, $errors, $lenient);
    }

    /**
     * Resolves a fact once. A fact that holds an error, or sits in a loop, resolves to null, and its error is reported once.
     *
     * @param list<string>                     $slotKeys
     * @param ?array<string, TemplateCardType> $types
     * @param list<string>                     $errors
     */
    private function namedFact(string $name, array $slotKeys, ?array $types, NamedFacts $facts, array &$errors, bool $lenient): ?NamedFact
    {
        if (\array_key_exists($name, $facts->resolved)) {
            return $facts->resolved[$name];
        }
        $position = array_search($name, $facts->stack, true);
        if (false !== $position) {
            $path = [...\array_slice($facts->stack, $position), $name];
            $errors[] = \sprintf('facts.%s: loop %s', $path[0], implode(' -> ', $path));

            return null;
        }

        $facts->stack[] = $name;
        $expression = $this->expression($facts->sources[$name], 'facts.'.$name, $slotKeys, $types, $facts, $errors, $lenient);
        array_pop($facts->stack);

        return $facts->resolved[$name] = null === $expression ? null : new NamedFact($name, $expression);
    }

    /**
     * @param list<string>                     $slotKeys
     * @param ?array<string, TemplateCardType> $types
     * @param list<string>                     $errors
     */
    private function conditionLeaf(string $key, mixed $value, string $where, array $slotKeys, ?array $types, array &$errors): ?ConditionLeaf
    {
        if (!$this->conditions->has($key)) {
            $errors[] = \sprintf('%s: unknown condition "%s"', $where, $key);

            return null;
        }
        $where = \sprintf('%s: %s', $where, $key);
        if (!self::isMap($value)) {
            $errors[] = $where.': parameters must be a map';

            return null;
        }

        $condition = $this->conditions->get($key);
        $errorCount = \count($errors);
        $declared = [];
        $params = [];
        foreach ($condition::parameters() as $parameter) {
            $declared[] = $parameter->name;
            if (!\array_key_exists($parameter->name, $value)) {
                if ($parameter->required && (null === $parameter->formerName || !\array_key_exists($parameter->formerName, $value))) {
                    $errors[] = \sprintf('%s: missing parameter "%s"', $where, $parameter->name);
                }
                continue;
            }
            $param = $value[$parameter->name];
            $error = match ($parameter->type) {
                ParameterType::Int => \is_int($param) && $param >= 1 ? null : \sprintf('parameter "%s" must be a positive integer', $parameter->name),
                ParameterType::Bool => \is_bool($param) ? null : \sprintf('parameter "%s" must be true or false', $parameter->name),
                ParameterType::String => match (true) {
                    !\is_string($param) || '' === $param => \sprintf('parameter "%s" must be a non-empty string', $parameter->name),
                    null !== $parameter->choices && !\in_array($param, $parameter->choices, true) => \sprintf('parameter "%s" must be one of: %s', $parameter->name, implode(', ', $parameter->choices)),
                    default => null,
                },
                ParameterType::Slot => match (true) {
                    !\is_string($param) => \sprintf('parameter "%s" must be a non-empty string', $parameter->name),
                    !self::isColumn($param, $slotKeys, false) => \sprintf('unknown slot "%s"', $param),
                    default => null,
                },
                ParameterType::List => \is_array($param) && [] !== $param && array_is_list($param) && array_all($param, static fn (mixed $entry): bool => \is_string($entry) && '' !== $entry)
                    ? null
                    : \sprintf('parameter "%s" must be a non-empty list of non-empty strings', $parameter->name),
                default => throw new \LogicException(\sprintf('The condition "%s" declares the parameter "%s" with a type that only an action takes.', $key, $parameter->name)),
            };
            if (null !== $error) {
                $errors[] = $where.': '.$error;
            }
            $params[$parameter->name] = $param;
        }
        foreach (array_keys($value) as $name) {
            if (!\in_array($name, $declared, true)) {
                $current = array_find($condition::parameters(), static fn (Parameter $parameter): bool => $parameter->formerName === $name);
                $errors[] = null === $current
                    ? \sprintf('%s: unknown parameter "%s"', $where, $name)
                    : \sprintf('%s: parameter "%s" is gone, use the "%s" list', $where, $name, $current->name);
            }
        }
        if (\count($errors) === $errorCount && null !== $types && $condition instanceof ReadsCardType) {
            foreach ($condition->cardType($params) as $type) {
                if (!isset($types[$type])) {
                    $errors[] = \sprintf('%s: unknown type "%s"', $where, $type);
                }
            }
        }

        return \count($errors) === $errorCount ? new ConditionLeaf($condition, $params) : null;
    }

    /**
     * @param list<string>                     $slotKeys
     * @param ?array<string, TemplateCardType> $types
     * @param list<string>                     $errors
     */
    private function action(mixed $node, string $where, array $slotKeys, ?array $types, NamedFacts $facts, array &$errors, bool $lenient): ?ActionCall
    {
        if (!\is_array($node) || 1 !== \count($node) || !\is_string(array_key_first($node))) {
            $errors[] = $where.': an action must have exactly one key';

            return null;
        }
        $name = array_key_first($node);
        $value = $node[$name];
        if (!$this->actions->has($name) || MissingAction::KEY === $name) {
            if ($lenient) {
                $from = \is_array($value) && \is_string($value['from'] ?? null) && self::isColumn($value['from'], $slotKeys, false) ? $value['from'] : null;

                return new ActionCall(MissingAction::KEY, ['name' => $name], traits: MissingAction::traits(), from: $from);
            }
            $errors[] = \sprintf('%s: unknown action "%s"', $where, $name);

            return null;
        }
        $action = $this->actions->get($name);
        $where .= '.'.$name;
        if (!self::isMap($value)) {
            $errors[] = $where.': parameters must be a map';

            return null;
        }

        $errorCount = \count($errors);
        $params = [];
        $expressions = [];
        $checks = [];
        $lists = [];
        $options = [];
        foreach ($action::parameters() as $parameter) {
            $param = $parameter->name;
            $kept = self::KEPT_PARAMETERS[$parameter->type->value] ?? null;
            if (null !== $kept && !\in_array($param, $kept, true)) {
                throw new \LogicException(\sprintf('The action "%s" declares the %s parameter "%s", and an action call keeps only %s.', $name, $parameter->type->value, $param, implode(', ', $kept)));
            }
            if (!\array_key_exists($param, $value)) {
                if ($parameter->required) {
                    $errors[] = ParameterType::Expression === $parameter->type
                        ? \sprintf('%s: a %s must carry an "%s" expression', $where, $name, $param)
                        : \sprintf('%s: missing parameter "%s"', $where, $param);
                }
                continue;
            }
            $given = $value[$param];
            switch ($parameter->type) {
                case ParameterType::Expression:
                    if (null !== $parameter->needs && !\array_key_exists($parameter->needs, $value)) {
                        $errors[] = \sprintf('%s: parameter "%s" needs a "%s"', $where, $param, $parameter->needs);
                    } else {
                        $expressions[$param] = $this->expression($given, $where.'.'.$param, $slotKeys, $types, $facts, $errors, $lenient);
                    }
                    continue 2;
                case ParameterType::Options:
                    $options = $this->askOptions($given, $where, $slotKeys, $types, $facts, $errors, $lenient);
                    continue 2;
                case ParameterType::Document:
                    $params += self::document($given, $where, $errors);
                    continue 2;
                case ParameterType::List:
                    $list = self::checks($given, $where, $param, $errors);
                    foreach ($list as $entry) {
                        if (null !== $parameter->choices && !\in_array($entry, $parameter->choices, true)) {
                            $errors[] = \sprintf('%s: parameter "%s" does not take "%s", only %s', $where, $param, $entry, implode(', ', $parameter->choices));
                        }
                    }
                    if ('checks' === $param) {
                        $checks = $list;
                    } else {
                        $lists[$param] = $list;
                    }
                    continue 2;
                case ParameterType::Bool:
                    throw new \LogicException(\sprintf('The action "%s" declares the parameter "%s" with a type that only a condition takes.', $name, $param));
                default:
                    break;
            }
            if (null !== $parameter->needs && !\array_key_exists($parameter->needs, $value)) {
                $errors[] = \sprintf('%s: parameter "%s" needs a "%s"', $where, $param, $parameter->needs);
                continue;
            }
            $error = self::parameterError($parameter, $given);
            if (null !== $error) {
                $errors[] = $where.': '.$error;
            } elseif (ParameterType::Slot === $parameter->type && \is_string($given) && !self::isColumn($given, $slotKeys, false)) {
                $errors[] = \sprintf('%s.%s: unknown slot "%s"', $where, $param, $given);
            } elseif (\is_int($given) || \is_string($given)) {
                $params[$param] = $given;
            }
        }
        if ($action instanceof ChecksParameters) {
            foreach ($action::check([...$params, ...$lists]) as $message) {
                $errors[] = $where.': '.$message;
            }
        }
        $names = array_map(static fn (Parameter $parameter): string => $parameter->name, $action::parameters());
        foreach (array_keys($value) as $param) {
            if (!\in_array($param, $names, true)) {
                $errors[] = \sprintf('%s: unknown parameter "%s"', $where, $param);
            }
        }

        return \count($errors) === $errorCount ? $this->actions->call($name, $params, $expressions['until'] ?? null, $checks, $expressions['refill'] ?? null, $options, $expressions['cancelWhen'] ?? null, $lists) : null;
    }

    /** @return ?string what is wrong with the value of a string, int or slot parameter, or null when nothing is */
    private static function parameterError(Parameter $parameter, mixed $given): ?string
    {
        $name = $parameter->name;

        return match (true) {
            ParameterType::Int === $parameter->type => \is_int($given) && $given >= ($parameter->min ?? \PHP_INT_MIN) ? null : (1 === $parameter->min
                ? \sprintf('parameter "%s" must be a positive integer', $name)
                : \sprintf('parameter "%s" must be an integer of at least %d', $name, $parameter->min ?? 0)),
            null !== $parameter->fixed => $given === $parameter->fixed ? null : \sprintf('parameter "%s" must be %s', $name, $parameter->fixed),
            null !== $parameter->choices => \in_array($given, $parameter->choices, true) ? null : \sprintf('parameter "%s" must be one of %s', $name, implode(', ', $parameter->choices)),
            null !== $parameter->pattern => \is_string($given) && 1 === preg_match($parameter->pattern, $given) ? null : \sprintf('parameter "%s" must match %s', $name, $parameter->patternHint ?? $parameter->pattern),
            default => \is_string($given) && '' !== $given ? null : \sprintf('parameter "%s" must be a non-empty string', $name),
        };
    }

    /**
     * @param list<mixed>                      $then
     * @param \Closure(ActionTraits): bool     $allowed
     * @param list<string>                     $slotKeys
     * @param ?array<string, TemplateCardType> $types
     * @param list<string>                     $errors
     *
     * @return list<ActionCall> the actions that parsed, which are fewer than $then when one has an error
     */
    private function actionList(array $then, string $where, \Closure $allowed, string $context, array $slotKeys, ?array $types, NamedFacts $facts, array &$errors, bool $lenient): array
    {
        $actions = [];
        foreach ($then as $position => $node) {
            $actionWhere = \sprintf('%s[%d]', $where, $position);
            $name = \is_array($node) && 1 === \count($node) ? array_key_first($node) : null;
            if (\is_string($name) && $this->actions->has($name) && !$allowed($this->actions->get($name)::traits())) {
                $errors[] = \sprintf('%s: the action "%s" is not allowed %s', $actionWhere, $name, $context);
                continue;
            }
            $action = $this->action($node, $actionWhere, $slotKeys, $types, $facts, $errors, $lenient);
            if (null !== $action) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    /**
     * @param list<string>                     $slotKeys
     * @param ?array<string, TemplateCardType> $types
     * @param list<string>                     $errors
     *
     * @return array<string, list<ActionCall>> the actions of each choice an agent may state, keyed by the value of the choice
     */
    private function childChoices(mixed $given, array $slotKeys, ?array $types, NamedFacts $facts, array &$errors, bool $lenient): array
    {
        if (null === $given) {
            return [];
        }
        if (!self::isMap($given)) {
            $errors[] = 'childChoices: must be a map from a choice to a list of actions';

            return [];
        }
        $choices = [];
        foreach ($given as $choice => $then) {
            $where = 'childChoices.'.$choice;
            if (!\in_array($choice, self::CHILD_CHOICES, true)) {
                $errors[] = \sprintf('childChoices: unknown choice "%s", expected one of %s', $choice, implode(', ', self::CHILD_CHOICES));
                continue;
            }
            if (!\is_array($then) || [] === $then || !array_is_list($then)) {
                $errors[] = $where.': must be a non-empty list of actions';
                continue;
            }
            $actions = $this->actionList($then, $where, static fn (ActionTraits $traits): bool => $traits->childChoice, 'inside a child choice', $slotKeys, $types, $facts, $errors, $lenient);
            if (\count($actions) === \count($then)) {
                $choices[$choice] = $actions;
            }
        }

        return $choices;
    }

    /**
     * @param list<string>                     $slotKeys
     * @param ?array<string, TemplateCardType> $types
     * @param list<string>                     $errors
     *
     * @return list<AskOption>
     */
    private function askOptions(mixed $given, string $where, array $slotKeys, ?array $types, NamedFacts $facts, array &$errors, bool $lenient): array
    {
        if (!\is_array($given) || [] === $given || !array_is_list($given)) {
            $errors[] = $where.': parameter "options" must be a non-empty list of maps with a "label" and a "then" list';

            return [];
        }
        $options = [];
        foreach ($given as $index => $entry) {
            $optionWhere = \sprintf('%s.options[%d]', $where, $index);
            $label = self::isMap($entry) ? ($entry['label'] ?? null) : null;
            $then = self::isMap($entry) ? ($entry['then'] ?? null) : null;
            if (!\is_string($label) || '' === $label || !\is_array($then) || [] === $then || !array_is_list($then)) {
                $errors[] = $optionWhere.': must be a map with a non-empty string "label" and a non-empty "then" list';
                continue;
            }
            $unknownKeys = array_diff(array_keys($entry), ['label', 'then']);
            foreach ($unknownKeys as $name) {
                $errors[] = \sprintf('%s: unknown key "%s"', $optionWhere, $name);
            }
            $actions = $this->actionList($then, $optionWhere.'.then', static fn (ActionTraits $traits): bool => $traits->option, 'inside an ask option', $slotKeys, $types, $facts, $errors, $lenient);
            if ([] !== $unknownKeys || \count($actions) !== \count($then)) {
                continue;
            }
            $options[] = new AskOption($label, $actions);
        }

        return $options;
    }

    /**
     * A single action is a list of one. A list of several holds only actions that need no bookkeeping of the engine, such as a request or a pause.
     *
     * @param list<string>                     $slotKeys
     * @param ?array<string, TemplateCardType> $types
     * @param list<string>                     $errors
     *
     * @return ?non-empty-list<ActionCall>
     */
    private function actionsOfRule(mixed $node, string $where, array $slotKeys, ?array $types, NamedFacts $facts, array &$errors, bool $lenient): ?array
    {
        if (!\is_array($node) || !array_is_list($node)) {
            $call = $this->action($node, $where, $slotKeys, $types, $facts, $errors, $lenient);

            return null === $call ? null : [$call];
        }
        if ([] === $node) {
            $errors[] = $where.': a list of actions must not be empty';

            return null;
        }
        $several = \count($node) > 1;
        $calls = $this->actionList($node, $where, static fn (ActionTraits $traits): bool => !$several || $traits->listable, 'in a rule with several actions', $slotKeys, $types, $facts, $errors, $lenient);

        if (\count($calls) !== \count($node)) {
            return null;
        }
        foreach ($calls as $position => $call) {
            if ($call->traits->endsPass && isset($calls[$position + 1])) {
                $errors[] = \sprintf('%s[%d]: the action "%s" ends the pass, so the action "%s" after it would never run', $where, $position, $call->key, $calls[$position + 1]->key);

                return null;
            }
        }

        return $calls;
    }

    /**
     * @param non-empty-list<ActionCall> $calls
     *
     * @return ?string the name of the first action that this version does not know, in an action or in an ask option
     */
    private static function unknownAction(array $calls): ?string
    {
        foreach ($calls as $action) {
            foreach ([$action, ...array_merge([], ...array_map(static fn (AskOption $option): array => $option->actions, $action->options))] as $call) {
                if (MissingAction::KEY === $call->key) {
                    return (string) $call->params['name'];
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, string> the tag, and the status when the map names one
     */
    private static function document(mixed $given, string $where, array &$errors): array
    {
        if (!self::isMap($given) || !\array_key_exists('tag', $given) || [] !== array_diff(array_keys($given), ['tag', 'status'])) {
            $errors[] = $where.': parameter "document" must be a map with the key tag, and optionally status';

            return [];
        }
        if (!\is_string($given['tag']) || '' === $given['tag']) {
            $errors[] = $where.'.document: parameter "tag" must be a non-empty string';

            return [];
        }
        if (!\array_key_exists('status', $given)) {
            return [self::DOCUMENT_TAG => $given['tag']];
        }
        $status = $given['status'];
        if (!\in_array($status, DocumentStatuses::ALL, true)) {
            $errors[] = \sprintf('%s.document: parameter "status" must be one of %s', $where, implode(', ', DocumentStatuses::ALL));

            return [];
        }

        return [self::DOCUMENT_TAG => $given['tag'], self::DOCUMENT_STATUS => $status];
    }

    /**
     * @param list<string> $errors
     *
     * @return list<string>
     */
    private static function checks(mixed $given, string $where, string $param, array &$errors): array
    {
        if (!\is_array($given) || [] === $given || !array_is_list($given)) {
            $errors[] = $where.': parameter "'.$param.'" must be a non-empty list of non-empty strings';

            return [];
        }
        $checks = [];
        foreach ($given as $check) {
            if (!\is_string($check) || '' === $check) {
                $errors[] = $where.': parameter "'.$param.'" must be a non-empty list of non-empty strings';

                return [];
            }
            $checks[] = $check;
        }

        return $checks;
    }

    /** @param list<string> $slotKeys */
    private static function isColumn(string $value, array $slotKeys, bool $acceptAnyColumn): bool
    {
        return \in_array($value, $slotKeys, true)
            || \in_array($value, self::COLUMN_FLAGS, true)
            || ($acceptAnyColumn && self::ANY_COLUMN === $value);
    }

    /** @phpstan-assert-if-true array<mixed> $value */
    private static function isMap(mixed $value): bool
    {
        return \is_array($value) && ([] === $value || !array_is_list($value));
    }

    /**
     * @param array<mixed> $source
     * @param list<string> $errors
     *
     * @return list<mixed>
     */
    private static function topLevelList(array $source, string $key, array &$errors): array
    {
        $value = $source[$key] ?? null;
        if (\is_array($value) && array_is_list($value)) {
            return $value;
        }
        $errors[] = self::topLevelError($source, $key, 'must be a list');

        return [];
    }

    /**
     * A template with no block retries nothing and pauses nothing for a settled refusal.
     *
     * @param list<string> $errors
     */
    private static function onWorkFailed(mixed $value, array &$errors): ?WorkFailurePolicy
    {
        if (null === $value) {
            return null;
        }
        $retryOn = \is_array($value) ? ($value['retryOn'] ?? null) : null;
        $retries = \is_array($value) ? ($value['retries'] ?? null) : null;
        if (!\is_array($value) || !\is_array($retryOn) || !array_is_list($retryOn)) {
            $errors[] = 'onWorkFailed: must be a map with a list "retryOn"';

            return null;
        }
        foreach ($retryOn as $code) {
            if (!\is_string($code) || 1 !== preg_match(ActionOutcome::CODE_PATTERN, $code)) {
                $errors[] = 'onWorkFailed.retryOn: each entry must be a refusal code';

                return null;
            }
        }
        if (!\is_int($retries) || $retries < 0) {
            $errors[] = 'onWorkFailed.retries: must be a non-negative integer';

            return null;
        }
        $backoffMinutes = self::backoffMinutes($value['backoffMinutes'] ?? null);
        if (null === $backoffMinutes) {
            $errors[] = 'onWorkFailed.backoffMinutes: must be a list of positive integers';

            return null;
        }
        $repair = $value['repair'] ?? null;
        if (null === $repair) {
            return new WorkFailurePolicy($retryOn, $retries, $backoffMinutes);
        }
        $repairKind = \is_array($repair) ? ($repair['kind'] ?? null) : null;
        if (!\is_string($repairKind)) {
            $errors[] = 'onWorkFailed.repair: must be a map with a string "kind"';

            return null;
        }
        if (1 !== preg_match(WorkKind::PATTERN, $repairKind)) {
            $errors[] = 'onWorkFailed.repair.kind: must be a work request kind';

            return null;
        }

        return new WorkFailurePolicy($retryOn, $retries, $backoffMinutes, $repairKind);
    }

    /**
     * A template with no value has no epic branches. A stored copy with a value that is not a branch name has none either.
     *
     * @param list<string> $errors
     */
    private static function epicBranch(mixed $value, bool $lenient, array &$errors): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value) || \strlen($value) > Template::EPIC_BRANCH_MAX_LENGTH || 1 !== preg_match(Template::EPIC_BRANCH_RULE, $value)) {
            if (!$lenient) {
                $errors[] = 'epicBranch: must be a Git branch name that holds the placeholder {number} exactly once';
            }

            return null;
        }

        return $value;
    }

    /**
     * A template with no value fails a review on an important finding. A stored copy with a value that names no known severity does too.
     *
     * @param list<string> $errors
     *
     * @return list<string> in the order of the severities
     */
    private static function agentReviewFailingSeverities(mixed $value, bool $lenient, array &$errors): array
    {
        if (null === $value) {
            return Template::DEFAULT_AGENT_REVIEW_FAILING_SEVERITIES;
        }
        $known = \is_array($value) && array_is_list($value) && [] !== $value && array_all($value, static fn (mixed $severity): bool => \in_array($severity, Template::AGENT_REVIEW_SEVERITIES, true));
        if (!$known) {
            if (!$lenient) {
                $errors[] = 'agentReviewFailingSeverities: must list one or more of important, nit and pre-existing';
            }

            return Template::DEFAULT_AGENT_REVIEW_FAILING_SEVERITIES;
        }

        return array_values(array_intersect(Template::AGENT_REVIEW_SEVERITIES, $value));
    }

    /**
     * A template with no value lets an approval cover a conflict resolution. A stored copy with a value that is not a boolean does too.
     *
     * @param list<string> $errors
     */
    private static function approvalCoversConflictResolution(mixed $value, bool $lenient, array &$errors): bool
    {
        if (!\is_bool($value)) {
            if (!$lenient) {
                $errors[] = 'approvalCoversConflictResolution: must be true or false';
            }

            return true;
        }

        return $value;
    }

    /** @return ?list<int> */
    private static function backoffMinutes(mixed $value): ?array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return null;
        }
        $minutes = [];
        foreach ($value as $entry) {
            if (!\is_int($entry) || $entry < 1) {
                return null;
            }
            $minutes[] = $entry;
        }

        return $minutes;
    }

    /** @param array<mixed> $source */
    private static function topLevelError(array $source, string $key, string $expectation): string
    {
        return $key.': '.(\array_key_exists($key, $source) ? $expectation : 'is missing');
    }
}
