<?php

declare(strict_types=1);

namespace App\Module\Forge\Settings;

/** What the active rules of the default branch say, as facts. */
final readonly class RepositoryRules implements \JsonSerializable
{
    /**
     * @param list<string>   $allowedMergeMethods the methods that every pull request rule listing methods allows, null when no rule lists any
     * @param list<string>   $requiredChecks
     * @param list<int|null> $approvalSources     the App id behind each entry that requires the approval check, null for an entry with no App
     */
    public function __construct(
        public bool $pullRequestRule,
        public bool $requiresReview,
        public ?array $allowedMergeMethods,
        public array $requiredChecks,
        public array $approvalSources,
        public bool $forbidsForcePush,
        public bool $forbidsDeletion,
    ) {
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'pullRequestRule' => $this->pullRequestRule,
            'requiresReview' => $this->requiresReview,
            'allowedMergeMethods' => $this->allowedMergeMethods,
            'requiredChecks' => $this->requiredChecks,
            'approvalSources' => $this->approvalSources,
            'forbidsForcePush' => $this->forbidsForcePush,
            'forbidsDeletion' => $this->forbidsDeletion,
        ];
    }

    /**
     * @param array<mixed> $data
     *
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $methods = $data['allowedMergeMethods'] ?? null;
        $sources = $data['approvalSources'] ?? null;
        if (!\is_array($sources) || !array_is_list($sources) || array_any($sources, static fn (mixed $source): bool => null !== $source && !\is_int($source))) {
            throw new \InvalidArgumentException('rules.approvalSources');
        }

        return new self(
            \is_bool($data['pullRequestRule'] ?? null) ? $data['pullRequestRule'] : throw new \InvalidArgumentException('rules.pullRequestRule'),
            \is_bool($data['requiresReview'] ?? null) ? $data['requiresReview'] : throw new \InvalidArgumentException('rules.requiresReview'),
            null === $methods ? null : self::strings($methods, 'rules.allowedMergeMethods'),
            self::strings($data['requiredChecks'] ?? null, 'rules.requiredChecks'),
            $sources,
            \is_bool($data['forbidsForcePush'] ?? null) ? $data['forbidsForcePush'] : throw new \InvalidArgumentException('rules.forbidsForcePush'),
            \is_bool($data['forbidsDeletion'] ?? null) ? $data['forbidsDeletion'] : throw new \InvalidArgumentException('rules.forbidsDeletion'),
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
