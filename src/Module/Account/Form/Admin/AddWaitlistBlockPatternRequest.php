<?php

declare(strict_types=1);

namespace App\Module\Account\Form\Admin;

use App\Module\Account\Entity\WaitlistBlockPattern;
use Symfony\Component\Validator\Constraints as Assert;

final class AddWaitlistBlockPatternRequest
{
    public function __construct(
        #[Assert\Length(max: WaitlistBlockPattern::MAX_LENGTH, maxMessage: 'account.form.add_waitlist_block_pattern_form.pattern.too_long')]
        #[Assert\NotBlank(message: 'account.form.add_waitlist_block_pattern_form.pattern.blank')]
        #[Assert\Regex(pattern: '/[^*\s]/', message: 'account.form.add_waitlist_block_pattern_form.pattern.only_stars')]
        public ?string $pattern = null,
    ) {
    }
}
