<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use Symfony\Component\Form\FormView;

/** The forms of one Dashboard tile, ready to render. */
final readonly class DashboardGraphFormViews
{
    public function __construct(
        public FormView $rename,
        public FormView $width,
        public FormView $legend,
        public FormView $drag,
    ) {
    }
}
