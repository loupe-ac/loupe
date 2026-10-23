<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use App\Module\Workflow\Contract\MarkerSubject;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One marker entry a card waits on. The marker key, the label and its values come from the stored marker.
 * A document wait names one version, a run wait names one run, a pull request wait names one head commit,
 * and a card wait names one spell of the marker on the card.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inbox_card_waits')]
class InboxCardWait
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(length: 30, nullable: true, enumType: InboxCardWaitEndReason::class)]
    public ?InboxCardWaitEndReason $endReason = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: InboxCardWatch::class, inversedBy: 'waits')]
        public readonly InboxCardWatch $watch,

        #[ORM\Column(name: 'marker_key', length: 100, options: ['default' => ''])]
        public readonly string $markerKey,

        #[ORM\Column(length: 20, enumType: InboxCardWaitType::class)]
        public readonly InboxCardWaitType $type,

        /** A translation key. The reconcile refreshes it with the params. */
        #[ORM\Column(length: 200, options: ['default' => ''])]
        public string $label,

        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        public readonly ?Uuid $documentId = null,

        #[ORM\Column(nullable: true)]
        public readonly ?int $versionNumber = null,

        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        public readonly ?Uuid $runId = null,

        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        public readonly ?Uuid $pullRequestId = null,

        #[ORM\Column(length: 64, nullable: true)]
        public readonly ?string $headSha = null,

        #[ORM\Column]
        public readonly \DateTimeImmutable $startedAt = new \DateTimeImmutable(),

        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        public readonly ?Uuid $pauseId = null,

        /** The start of the spell of a card wait. */
        #[ORM\Column(name: 'spell_started_at', nullable: true)]
        public readonly ?\DateTimeImmutable $spellStartedAt = null,

        /** @var array<string, string|int> */
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
        public array $params = [],
    ) {
        self::computeKey($markerKey, $type, $this->subjectIds());
    }

    /**
     * The wait of a marker entry, from the subject the entry names.
     *
     * @param array<string, string|int> $params
     */
    public static function forSubject(InboxCardWatch $watch, string $markerKey, string $label, array $params, MarkerSubject $subject, \DateTimeImmutable $startedAt): self
    {
        $ids = $subject->ids;
        if (InboxCardWaitType::Card === InboxCardWaitType::forSubject($subject->type) && ($ids['card'] ?? null) !== $watch->cardId->toRfc4122()) {
            throw new \InvalidArgumentException('A card wait names the card of its watch.');
        }

        return new self(
            watch: $watch,
            markerKey: $markerKey,
            type: InboxCardWaitType::forSubject($subject->type),
            label: $label,
            documentId: isset($ids['document']) ? Uuid::fromString($ids['document']) : null,
            versionNumber: isset($ids['version']) ? (int) $ids['version'] : null,
            runId: isset($ids['run']) ? Uuid::fromString($ids['run']) : null,
            pullRequestId: isset($ids['pullRequest']) ? Uuid::fromString($ids['pullRequest']) : null,
            headSha: $ids['head'] ?? null,
            startedAt: $startedAt,
            pauseId: isset($ids['pause']) ? Uuid::fromString($ids['pause']) : null,
            spellStartedAt: isset($ids['spell']) ? new \DateTimeImmutable($ids['spell'])->setTimezone(new \DateTimeZone(date_default_timezone_get())) : null,
            params: $params,
        );
    }

    /** Two waits with the same key are the same wait. */
    public function key(): string
    {
        return self::computeKey($this->markerKey, $this->type, $this->subjectIds());
    }

    /**
     * The ids that this wait names, as a marker subject names them.
     *
     * @return array<string, string>
     */
    public function subjectIds(): array
    {
        return array_filter([
            'document' => $this->documentId?->toRfc4122(),
            'version' => null === $this->versionNumber ? null : (string) $this->versionNumber,
            'run' => $this->runId?->toRfc4122(),
            'pullRequest' => $this->pullRequestId?->toRfc4122(),
            'head' => $this->headSha,
            'pause' => $this->pauseId?->toRfc4122(),
            'card' => InboxCardWaitType::Card === $this->type ? $this->watch->cardId->toRfc4122() : null,
            'spell' => $this->spellStartedAt?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
        ], static fn (?string $id): bool => null !== $id);
    }

    /**
     * The key of a wait: the marker key and the ids of its thing. A wait of an older row and an entry of the new
     * engine for the same thing make the same key, so a dismissal holds.
     *
     * @param array<string, string> $ids
     */
    public static function computeKey(string $markerKey, InboxCardWaitType $type, array $ids): string
    {
        $names = $type->idNames();
        $given = array_keys($ids);
        sort($given);
        $expected = $names;
        sort($expected);
        if ($given !== $expected) {
            throw new \InvalidArgumentException(\sprintf('A %s wait names %s, and nothing else.', $type->value, implode(' and ', $names)));
        }

        $parts = [$markerKey];
        foreach ($names as $name) {
            if ('' === $ids[$name]) {
                throw new \InvalidArgumentException(\sprintf('The id "%s" of a %s wait is empty.', $name, $type->value));
            }
            $parts[] = $ids[$name];
        }

        return implode(':', $parts);
    }
}
