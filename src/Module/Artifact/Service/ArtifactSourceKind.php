<?php

declare(strict_types=1);

namespace App\Module\Artifact\Service;

/**
 * The tool an artifact came from, read from the shape of its source link. A Design
 * canvas lives at claude.ai/artifact/<id>, so it reads as ClaudeArtifact. Only an
 * older standalone canvas link, claude.ai/design/, reads as ClaudeDesign.
 */
enum ArtifactSourceKind: string
{
    case ClaudeDesign = 'claude-design';
    case ClaudeArtifact = 'claude-artifact';
    case Link = 'link';

    public static function fromLink(?string $link): ?self
    {
        if (null === $link || '' === trim($link)) {
            return null;
        }

        $host = strtolower((string) parse_url($link, \PHP_URL_HOST));
        $path = (string) parse_url($link, \PHP_URL_PATH);

        return match (true) {
            'claude.ai' === $host && str_starts_with($path, '/design/') => self::ClaudeDesign,
            'claude.ai' === $host && str_contains($path, 'artifact'), 'claude.site' === $host, str_ends_with($host, '.claude.site') => self::ClaudeArtifact,
            default => self::Link,
        };
    }

    public function translationKey(): string
    {
        return 'artifact.source.'.$this->value;
    }
}
