<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\ValueObject\AccountReadingReport;
use Symfony\Component\Validator\Constraints as Assert;

/** The latest reading of one usage window of an account. */
final class BridgeAccountReadingInput
{
    public const string WINDOW_PATTERN = '/^[a-z0-9]{1,16}$/D';

    public const int MAX_SOURCE_LENGTH = 40;

    public const string MAX_RESET_LEAD = '+31 days';

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: self::WINDOW_PATTERN)]
        public ?string $window = null,

        #[Assert\Range(min: 0, max: 200)]
        public ?float $usedPercent = null,

        #[Assert\LessThanOrEqual(value: self::MAX_RESET_LEAD)]
        #[Assert\NotNull]
        public ?\DateTimeImmutable $resetsAt = null,

        #[Assert\NotNull]
        public ?bool $limited = null,

        #[Assert\Length(max: self::MAX_SOURCE_LENGTH, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $source = null,

        #[Assert\NotNull]
        public ?\DateTimeImmutable $readAt = null,
    ) {
    }

    public function report(string $account): AccountReadingReport
    {
        $utc = new \DateTimeZone('UTC');

        return new AccountReadingReport(
            account: $account,
            window: $this->window ?? throw new \LogicException('window is required after validation.'),
            usedPercent: $this->usedPercent,
            resetsAt: ($this->resetsAt ?? throw new \LogicException('resetsAt is required after validation.'))->setTimezone($utc),
            limited: $this->limited ?? throw new \LogicException('limited is required after validation.'),
            source: trim($this->source ?? ''),
            readAt: ($this->readAt ?? throw new \LogicException('readAt is required after validation.'))->setTimezone($utc),
        );
    }
}
