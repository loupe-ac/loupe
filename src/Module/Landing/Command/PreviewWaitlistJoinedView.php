<?php

declare(strict_types=1);

namespace App\Module\Landing\Command;

use Symfony\Component\Form\FormView;

final readonly class PreviewWaitlistJoinedView
{
    /** @param 'notify'|'modal' $frame */
    public function __construct(
        public string $frame,
        public FormView $notifyForm,
        public FormView $modalForm,
    ) {
    }
}
