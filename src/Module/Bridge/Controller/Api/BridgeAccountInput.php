<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Entity\Bridge;
use Symfony\Component\Validator\Constraints as Assert;

/** One account a bridge runs its workers under, and whether its last check passed. A missing `used` reads as true. */
final class BridgeAccountInput
{
    public const string NAME_PATTERN = '/^[a-z][a-z0-9-]{0,39}$/D';

    public const string HARNESS_PATTERN = '/^[a-z][a-z0-9-]{0,39}$/D';

    public const string STATE_READY = 'ready';

    public const string STATE_FAILING = 'failing';

    public const int MAX_REASON_LENGTH = 200;

    /** Far above the usage windows a tool reports for one account. */
    public const int MAX_READINGS = 8;

    public const int MIN_STOP_AT = 1;

    public const int MAX_STOP_AT = 100;

    /**
     * @param list<string>|null                    $kinds    the work kinds that can run on the account, null from a bridge that predates them
     * @param int|null                             $stopAt   the percentage of use at which the account takes no new work, null from a bridge that predates it
     * @param list<BridgeAccountReadingInput>|null $readings the latest reading of each usage window, null from a bridge that predates them
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: self::NAME_PATTERN)]
        public ?string $name = null,

        #[Assert\NotBlank]
        #[Assert\Regex(pattern: self::HARNESS_PATTERN)]
        public ?string $harness = null,

        #[Assert\Choice(choices: [self::STATE_READY, self::STATE_FAILING])]
        #[Assert\NotBlank]
        public ?string $state = null,

        #[Assert\Length(max: self::MAX_REASON_LENGTH, normalizer: 'trim')]
        public ?string $reason = null,
        public ?bool $used = null,

        #[Assert\All([new Assert\Type('string'), new Assert\Regex(pattern: Bridge::WORK_KIND_PATTERN)])]
        #[Assert\Count(max: RecordBridgeHeartbeatRequest::MAX_WORK_KINDS)]
        #[Assert\Type('list')]
        public ?array $kinds = null,

        #[Assert\Range(min: self::MIN_STOP_AT, max: self::MAX_STOP_AT)]
        public ?int $stopAt = null,

        #[Assert\All([new Assert\Type(BridgeAccountReadingInput::class)])]
        #[Assert\Count(max: self::MAX_READINGS)]
        #[Assert\Type('list')]
        #[Assert\Valid]
        public ?array $readings = null,
    ) {
    }
}
