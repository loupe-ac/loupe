<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** One command entry of the rule file of a bridge, with its parameters. */
final class BridgeCommandEntryInput
{
    public const int MAX_NAME_LENGTH = 100;

    public const int MAX_DESCRIPTION_LENGTH = 500;

    public const int MAX_PARAMS = 20;

    public const int MAX_RUN_ELEMENTS = 50;

    public const int MAX_RUN_ELEMENT_LENGTH = 1000;

    public const int MAX_TIMEOUT_LENGTH = 20;

    /**
     * @param list<BridgeCommandParamInput>|null $params
     * @param list<string>|null                  $run    the argv, with its placeholders
     */
    public function __construct(
        #[Assert\Length(max: self::MAX_NAME_LENGTH)]
        #[Assert\NotBlank]
        public ?string $name = null,

        #[Assert\Length(max: self::MAX_DESCRIPTION_LENGTH)]
        public ?string $description = null,

        #[Assert\All([new Assert\Type(BridgeCommandParamInput::class)])]
        #[Assert\Count(max: self::MAX_PARAMS)]
        #[Assert\NotNull]
        #[Assert\Type('list')]
        #[Assert\Valid]
        public ?array $params = null,

        #[Assert\All([new Assert\Type('string'), new Assert\Length(max: self::MAX_RUN_ELEMENT_LENGTH)])]
        #[Assert\Count(min: 1, max: self::MAX_RUN_ELEMENTS)]
        #[Assert\NotNull]
        #[Assert\Type('list')]
        public ?array $run = null,

        #[Assert\Length(max: self::MAX_TIMEOUT_LENGTH)]
        #[Assert\NotBlank]
        public ?string $timeout = null,
    ) {
    }
}
