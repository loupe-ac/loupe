<?php

declare(strict_types=1);

namespace App\Module\Project\Form;

use App\Doctrine\SearchLanguage;
use App\Module\Project\Entity\Project;
use Symfony\Component\Validator\Constraints as Assert;

class CreateProjectRequest
{
    public function __construct(
        #[Assert\Length(max: Project::MAX_NAME_LENGTH, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $name = null,

        #[Assert\Length(max: Project::MAX_DOMAIN_LENGTH, normalizer: 'trim')]
        public ?string $domain = null,

        /**
         * Nullable so a submit that omits the select fails validation rather
         * than throwing out of the property mapper.
         */
        #[Assert\NotNull]
        public ?SearchLanguage $searchLanguage = SearchLanguage::DEFAULT,

        #[Assert\Length(max: Project::MAX_DESCRIPTION_LENGTH, normalizer: 'trim')]
        public ?string $description = null,
    ) {
    }
}
