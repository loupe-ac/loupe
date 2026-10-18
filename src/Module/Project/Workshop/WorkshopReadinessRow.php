<?php

declare(strict_types=1);

namespace App\Module\Project\Workshop;

/** One check of the readiness guide. Every string is a translation key, except a detail with no key of its own and the status parameters. */
final readonly class WorkshopReadinessRow
{
    public const string GROUP_DISCOVERY = 'discovery';
    public const string GROUP_PULL_REQUESTS = 'pull_requests';
    public const string GROUP_OPTIONAL = 'optional';

    public function __construct(
        public string $key,
        public string $label,
        public bool $done,
        public string $status,
        public ?string $detail = null,
        public ?string $actionLabel = null,
        public ?string $actionUrl = null,
        /** @var array<string, string> */
        public array $statusParameters = [],
        /** A set token id makes the action a POST form that carries this CSRF token. */
        public ?string $actionCsrfTokenId = null,
        /** The state of the latest discovery run, or none, on the row that shows discovery. */
        public ?string $discoveryState = null,
        /** The section of the guide that holds the row. A row of the optional section does not count as a required step. */
        public string $group = self::GROUP_DISCOVERY,
        /** An optional choice inside a required section, which does not count as a required step either. */
        public bool $optional = false,
        /** The last report of the machine that the row names, shown beside the status. */
        public ?\DateTimeImmutable $seenAt = null,
    ) {
    }

    public function required(): bool
    {
        return !$this->optional && self::GROUP_OPTIONAL !== $this->group;
    }
}
