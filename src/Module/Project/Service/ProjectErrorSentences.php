<?php

declare(strict_types=1);

namespace App\Module\Project\Service;

use App\Module\Project\Entity\Project;

/** The sentence for each translation key a project handler refuses with, for a client that reads English text. */
final readonly class ProjectErrorSentences
{
    public const string UNMAPPED = 'The request was rejected. The error has been logged.';

    /** @param array<string, int|string> $parameters the placeholders of the key */
    public static function of(string $key, array $parameters = []): string
    {
        return match ($key) {
            'project.error.name_blank' => 'A project name must not be blank.',
            'project.error.name_too_long' => \sprintf('A project name must be at most %d characters.', Project::MAX_NAME_LENGTH),
            'project.error.domain_too_long' => \sprintf('A domain must be at most %d characters.', Project::MAX_DOMAIN_LENGTH),
            'project.error.description_too_long' => \sprintf('A description must be at most %d characters.', Project::MAX_DESCRIPTION_LENGTH),
            'project.error.search_language_unknown' => \sprintf('Unknown search language "%s". Use one of: %s.', $parameters['%language%'] ?? '', $parameters['%languages%'] ?? ''),
            'project.allowed_origins.error.not_a_string' => 'Each entry must be a string.',
            'project.allowed_origins.error.line_break' => 'An entry must not contain a line break. Pass each origin as its own entry.',
            'project.allowed_origins.error.too_long' => \sprintf('The origins must be at most %d characters together.', SiteOrigins::MAX_TEXT_LENGTH),
            'project.error.name_taken' => 'Another project of this owner already has this name. Choose another name.',
            'project.error.slug_empty' => 'A project name needs at least one letter or digit, because the slug comes from the name.',
            'project.error.slug_taken' => 'Another project of this owner has a name that gives the same slug. Choose another name.',
            'project.allowed_origins.error.invalid' => 'Each entry must be a site origin with no path, such as https://staging.example.com or https://*.example.com. A * stands for one label, and it cannot cover a whole registry such as *.com. Plain http works on localhost only.',
            'project.allowed_origins.error.too_many' => \sprintf('A project allows %d origins at most.', SiteOrigins::MAX),
            default => self::UNMAPPED,
        };
    }
}
