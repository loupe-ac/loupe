<?php

declare(strict_types=1);

namespace App\Module\Account\Form;

use App\Module\Account\Entity\WaitlistPick;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class WaitlistJoinRequest
{
    /** @param list<WaitlistPick> $picks */
    public function __construct(
        #[Email]
        #[NotBlank]
        public ?string $email = null,
        public array $picks = [],

        #[Length(max: 200)]
        public ?string $other = null,
    ) {
    }
}
