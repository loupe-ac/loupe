<?php

declare(strict_types=1);

namespace App\Module\Project\Service;

use App\Exception\DomainErrors;

/** Checks the origins a caller gives for a project and turns them into the stored list. */
final readonly class AllowedOriginsInput
{
    /**
     * @param string|array<mixed> $origins One origin per line, as the owner typed them, or a list with one origin per
     *                                     entry. An entry that is no string or holds a line break is refused.
     *
     * @return list<string>
     *
     * @throws DomainErrors under the `origins` key
     */
    public static function normalise(string|array $origins): array
    {
        $text = $origins;
        if (\is_array($text)) {
            foreach ($text as $entry) {
                if (!\is_string($entry)) {
                    throw new DomainErrors(['origins' => 'project.allowed_origins.error.not_a_string']);
                }
                if (1 === preg_match('/\R/', $entry)) {
                    throw new DomainErrors(['origins' => 'project.allowed_origins.error.line_break']);
                }
            }
            $text = implode("\n", $text);
        }
        if (mb_strlen($text) > SiteOrigins::MAX_TEXT_LENGTH) {
            throw new DomainErrors(['origins' => 'project.allowed_origins.error.too_long']);
        }

        $normalised = SiteOrigins::normaliseList(preg_split('/\R/', $text) ?: [])
            ?? throw new DomainErrors(['origins' => 'project.allowed_origins.error.invalid']);

        if (\count($normalised) > SiteOrigins::MAX) {
            throw new DomainErrors(['origins' => 'project.allowed_origins.error.too_many']);
        }

        return $normalised;
    }
}
