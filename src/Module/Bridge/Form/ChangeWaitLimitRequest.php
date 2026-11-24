<?php

declare(strict_types=1);

namespace App\Module\Bridge\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class ChangeWaitLimitRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'bridge.form.change_wait_limit_form.minutes.required')]
        #[Assert\Positive(message: 'bridge.form.change_wait_limit_form.minutes.positive')]
        public ?int $minutes = null,
    ) {
    }
}
