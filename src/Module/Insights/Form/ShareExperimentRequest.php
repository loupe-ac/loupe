<?php

declare(strict_types=1);

namespace App\Module\Insights\Form;

use Symfony\Component\Validator\Constraints as Assert;

class ShareExperimentRequest
{
    public const int NOTE_MAX_LENGTH = 2000;

    public function __construct(
        public bool $enabled = false,

        #[Assert\Length(max: self::NOTE_MAX_LENGTH, maxMessage: 'insights.form.share_experiment_form.note.too_long')]
        public ?string $note = null,
    ) {
    }

    public static function fromState(bool $enabled, ?string $note): self
    {
        return new self($enabled, $note);
    }
}
