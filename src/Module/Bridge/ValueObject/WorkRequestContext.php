<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

use Symfony\Component\Uid\Uuid;

/**
 * What a work request knows about its card when it opens: the pull request it
 * acts on and why, the document it revises, and the inbox action that asked
 * for it. A snapshot, so a later push leaves the head sha behind. A bridge
 * fills prompts and commands with these values, so each one has a strict shape.
 */
final readonly class WorkRequestContext
{
    public const int MAX_PULL_REQUEST_NUMBER = 2147483647;

    public const int MAX_URL_LENGTH = 2000;

    private const string URL_PATTERN = '#^https://[A-Za-z0-9._~:/?\#\[\]@!$&()*+,;=%-]+$#D';

    private const string SHA_PATTERN = '#^[0-9a-f]{7,64}$#D';

    /** The longest epic branch pattern plus the digits of the largest card number. A test ties it to the template rule. */
    public const int MAX_EPIC_BRANCH_LENGTH = 265;

    private const string EPIC_BRANCH_PATTERN = '#^(?!.*\.\.)(?!.*\.lock(?:/|$))[A-Za-z0-9_](?:[A-Za-z0-9._-]|/(?![/.-]))*(?<![./])$#D';

    private const string REASON_PATTERN = '/^[a-z][a-z0-9-]{0,63}$/D';

    private const array KEYS = ['pullRequestNumber', 'pullRequestUrl', 'headSha', 'reason', 'documentId'];

    /** A row stored before these keys existed lacks them, and they read as null. */
    private const array OPTIONAL_KEYS = ['inboxActionId', 'inboxItemId', 'inboxItemNumber', 'epicBranch'];

    public function __construct(
        public ?int $pullRequestNumber = null,
        public ?string $pullRequestUrl = null,
        public ?string $headSha = null,
        public ?string $reason = null,
        public ?string $documentId = null,
        public ?string $inboxActionId = null,
        public ?int $inboxItemNumber = null,
        public ?string $inboxItemId = null,
        public ?string $epicBranch = null,
    ) {
        if (null !== $pullRequestNumber && ($pullRequestNumber < 1 || $pullRequestNumber > self::MAX_PULL_REQUEST_NUMBER)) {
            throw new \UnexpectedValueException('A work request names a pull request by a positive 32-bit number.');
        }
        if (null !== $pullRequestUrl && !self::acceptsUrl($pullRequestUrl)) {
            throw new \UnexpectedValueException('A work request names a pull request by an https URL.');
        }
        if (null !== $headSha && !self::acceptsHeadSha($headSha)) {
            throw new \UnexpectedValueException('A work request names a head by a lower-case hex sha.');
        }
        if (null !== $reason && 1 !== preg_match(self::REASON_PATTERN, $reason)) {
            throw new \UnexpectedValueException('A work request reason is a code.');
        }
        if (null !== $documentId && (!Uuid::isValid($documentId) || $documentId !== Uuid::fromString($documentId)->toRfc4122())) {
            throw new \UnexpectedValueException('A work request names a document by its lower-case uuid.');
        }
        if (null !== $inboxActionId && (!Uuid::isValid($inboxActionId) || $inboxActionId !== Uuid::fromString($inboxActionId)->toRfc4122())) {
            throw new \UnexpectedValueException('A work request names an inbox action by its lower-case uuid.');
        }
        if (null !== $inboxItemId && (!Uuid::isValid($inboxItemId) || $inboxItemId !== Uuid::fromString($inboxItemId)->toRfc4122())) {
            throw new \UnexpectedValueException('A work request names an inbox item by its lower-case uuid.');
        }
        if (null !== $inboxItemNumber && ($inboxItemNumber < 1 || $inboxItemNumber > self::MAX_PULL_REQUEST_NUMBER)) {
            throw new \UnexpectedValueException('A work request names an inbox item by a positive 32-bit number.');
        }
        if (null !== $epicBranch && (\strlen($epicBranch) > self::MAX_EPIC_BRANCH_LENGTH || 1 !== preg_match(self::EPIC_BRANCH_PATTERN, $epicBranch))) {
            throw new \UnexpectedValueException('A work request names an epic branch by a safe git branch name.');
        }
    }

    public static function acceptsUrl(string $url): bool
    {
        $host = parse_url($url, \PHP_URL_HOST);

        return \strlen($url) <= self::MAX_URL_LENGTH && 1 === preg_match(self::URL_PATTERN, $url) && \is_string($host) && '' !== $host;
    }

    public static function acceptsHeadSha(string $sha): bool
    {
        return 1 === preg_match(self::SHA_PATTERN, $sha);
    }

    /**
     * Reads the stored column. A row from before the column has null.
     *
     * @param array<mixed>|null $data
     */
    public static function fromArray(?array $data): self
    {
        if (null === $data) {
            return new self();
        }
        $keys = array_values(array_diff(array_keys($data), self::OPTIONAL_KEYS));
        sort($keys);
        $expected = self::KEYS;
        sort($expected);
        if ($keys !== $expected) {
            throw new \UnexpectedValueException('A work request context holds the keys '.implode(', ', [...self::KEYS, ...self::OPTIONAL_KEYS]).'.');
        }

        return new self(
            pullRequestNumber: self::nullableInt($data['pullRequestNumber']),
            pullRequestUrl: self::nullableString($data['pullRequestUrl']),
            headSha: self::nullableString($data['headSha']),
            reason: self::nullableString($data['reason']),
            documentId: self::nullableString($data['documentId']),
            inboxActionId: self::nullableString($data['inboxActionId'] ?? null),
            inboxItemNumber: self::nullableInt($data['inboxItemNumber'] ?? null),
            inboxItemId: self::nullableString($data['inboxItemId'] ?? null),
            epicBranch: self::nullableString($data['epicBranch'] ?? null),
        );
    }

    /** @return array{pullRequestNumber: ?int, pullRequestUrl: ?string, headSha: ?string, reason: ?string, documentId: ?string, inboxActionId: ?string, inboxItemNumber: ?int, inboxItemId: ?string, epicBranch: ?string} */
    public function toArray(): array
    {
        return [
            'pullRequestNumber' => $this->pullRequestNumber,
            'pullRequestUrl' => $this->pullRequestUrl,
            'headSha' => $this->headSha,
            'reason' => $this->reason,
            'documentId' => $this->documentId,
            'inboxActionId' => $this->inboxActionId,
            'inboxItemNumber' => $this->inboxItemNumber,
            'inboxItemId' => $this->inboxItemId,
            'epicBranch' => $this->epicBranch,
        ];
    }

    private static function nullableInt(mixed $value): ?int
    {
        return null === $value || \is_int($value) ? $value : throw new \UnexpectedValueException('A work request context holds a number or null.');
    }

    private static function nullableString(mixed $value): ?string
    {
        return null === $value || \is_string($value) ? $value : throw new \UnexpectedValueException('A work request context holds a string or null.');
    }
}
