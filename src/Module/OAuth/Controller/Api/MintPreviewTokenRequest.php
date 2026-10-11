<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller\Api;

use App\Module\OAuth\Entity\PreviewToken;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/** A request for a preview token. The label names the preview, and a second mint under it replaces the first. */
final class MintPreviewTokenRequest
{
    public function __construct(
        #[Assert\Length(max: PreviewToken::MAX_LABEL_LENGTH, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $label = null,

        #[Assert\Uuid]
        public ?string $card = null,
    ) {
    }

    public function cardId(): ?Uuid
    {
        return null !== $this->card && '' !== $this->card ? Uuid::fromString($this->card) : null;
    }
}
