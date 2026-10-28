<?php

declare(strict_types=1);

namespace App\Module\Forge\Settings;

/** One ruleset that applies to the default branch. */
final readonly class RepositoryRuleset implements \JsonSerializable
{
    public const string SOURCE_ORGANIZATION = 'Organization';

    /**
     * @param string|null  $name       null when the forge refused to show the ruleset
     * @param string       $sourceType `Repository` or `Organization`, as the forge names the owner of the ruleset
     * @param string       $source     the repository path, or the organization name
     * @param list<string> $rules      the types of the rules that the ruleset puts on the branch
     */
    public function __construct(
        public int $id,
        public ?string $name,
        public string $sourceType,
        public string $source,
        public array $rules,
    ) {
    }

    public function isOrganization(): bool
    {
        return self::SOURCE_ORGANIZATION === $this->sourceType;
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'sourceType' => $this->sourceType, 'source' => $this->source, 'rules' => $this->rules];
    }

    /**
     * @param array<mixed> $data
     *
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $rules = $data['rules'] ?? null;

        return new self(
            \is_int($data['id'] ?? null) ? $data['id'] : throw new \InvalidArgumentException('ruleset.id'),
            \is_string($data['name'] ?? null) || null === ($data['name'] ?? null) ? ($data['name'] ?? null) : throw new \InvalidArgumentException('ruleset.name'),
            \is_string($data['sourceType'] ?? null) ? $data['sourceType'] : throw new \InvalidArgumentException('ruleset.sourceType'),
            \is_string($data['source'] ?? null) ? $data['source'] : throw new \InvalidArgumentException('ruleset.source'),
            self::strings($rules),
        );
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (!\is_array($value) || !array_is_list($value) || array_any($value, static fn (mixed $item): bool => !\is_string($item))) {
            throw new \InvalidArgumentException('ruleset.rules');
        }

        return $value;
    }
}
