<?php

declare(strict_types=1);

namespace App\Module\Forge\Settings;

/**
 * What one read of a repository found on its default branch. The check stores
 * it as JSON, and the guide and the import file read it back.
 */
final readonly class RepositorySettings implements \JsonSerializable
{
    /** The order in which the merge methods are listed. */
    public const array MERGE_METHODS = ['squash', 'merge', 'rebase'];

    /** The workflow setting that lists the checks Loupe waits for on a repository whose forge refuses rulesets. */
    public const string REQUIRED_CHECKS_SETTING = 'required-checks';

    /** A read keeps this many names of recent checks, so the list can be cut off at this length. */
    public const int MAX_RECENT_CHECKS = 30;

    /**
     * @param list<string>            $mergeMethods      the methods that the repository switches turn on
     * @param int|null                $loupeAppId        the id of the Loupe App on the forge, null when the forge has none
     * @param list<RepositoryRuleset> $rulesets
     * @param list<string>            $recentChecks      the names of checks that recent pull requests ran, without the Loupe checks
     * @param bool                    $classicProtection true when the branch has classic protection
     * @param string|null             $rulesUnavailable  the forge message when the plan has no rulesets, so the forge enforces none; null when the rules were read
     */
    public function __construct(
        public string $defaultBranch,
        public array $mergeMethods,
        public ?int $loupeAppId,
        public RepositoryRules $rules,
        public array $rulesets,
        public array $recentChecks,
        public bool $classicProtection,
        public ?string $rulesUnavailable = null,
    ) {
    }

    /** @return list<string> the methods that the repository and every rule of the branch allow */
    public function usableMergeMethods(): array
    {
        return array_values(array_filter(
            self::MERGE_METHODS,
            fn (string $method): bool => \in_array($method, $this->mergeMethods, true)
                && (null === $this->rules->allowedMergeMethods || \in_array($method, $this->rules->allowedMergeMethods, true)),
        ));
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'defaultBranch' => $this->defaultBranch,
            'mergeMethods' => $this->mergeMethods,
            'loupeAppId' => $this->loupeAppId,
            'rules' => $this->rules,
            'rulesets' => $this->rulesets,
            'recentChecks' => $this->recentChecks,
            'classicProtection' => $this->classicProtection,
            'rulesUnavailable' => $this->rulesUnavailable,
        ];
    }

    /**
     * @param array<mixed> $data
     *
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $rules = $data['rules'] ?? null;
        $rulesets = $data['rulesets'] ?? null;
        $appId = $data['loupeAppId'] ?? null;
        if (!\is_array($rules) || !\is_array($rulesets) || !array_is_list($rulesets) || (null !== $appId && !\is_int($appId))) {
            throw new \InvalidArgumentException('settings');
        }

        return new self(
            \is_string($data['defaultBranch'] ?? null) ? $data['defaultBranch'] : throw new \InvalidArgumentException('settings.defaultBranch'),
            self::strings($data['mergeMethods'] ?? null, 'settings.mergeMethods'),
            $appId,
            RepositoryRules::fromArray($rules),
            array_map(static fn (mixed $ruleset): RepositoryRuleset => \is_array($ruleset) ? RepositoryRuleset::fromArray($ruleset) : throw new \InvalidArgumentException('settings.rulesets'), $rulesets),
            self::strings($data['recentChecks'] ?? null, 'settings.recentChecks'),
            \is_bool($data['classicProtection'] ?? null) ? $data['classicProtection'] : throw new \InvalidArgumentException('settings.classicProtection'),
            \is_string($data['rulesUnavailable'] ?? null) ? $data['rulesUnavailable'] : null,
        );
    }

    /** @return list<string> */
    private static function strings(mixed $value, string $field): array
    {
        if (!\is_array($value) || !array_is_list($value) || array_any($value, static fn (mixed $item): bool => !\is_string($item))) {
            throw new \InvalidArgumentException($field);
        }

        return $value;
    }
}
