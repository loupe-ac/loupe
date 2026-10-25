<?php

declare(strict_types=1);

namespace App\Module\Notification;

/**
 * What one push says, in the declarative shape that WebKit defined and a service worker also reads.
 * The tap URL is absolute, and the icon lives on the same origin.
 */
final readonly class PushContent
{
    /** The Declarative Web Push magic number. */
    private const int DECLARATIVE_MAGIC = 8030;

    private const string ICON_PATH = '/icons/push-192.png';

    /** A push service refuses a payload above 4078 bytes once encrypted. The margin covers the encoding. */
    public const int MAX_BYTES = 3800;

    public string $icon;

    /** @param list<string> $openTags the tags of every push that still stands, the own tag first */
    public function __construct(
        public string $title,
        public string $body,
        public string $url,
        public string $tag,
        public array $openTags,
    ) {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('The tap URL of a push must be absolute.');
        }

        $this->icon = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').self::ICON_PATH;
    }

    public function toJson(): string
    {
        $tags = $this->openTags;
        $json = $this->encode($tags);
        // A tag past the limit would fail the whole send. Losing the oldest tags only leaves their notifications in place.
        while (\strlen($json) > self::MAX_BYTES && \count($tags) > 1) {
            array_pop($tags);
            $json = $this->encode($tags);
        }

        return $json;
    }

    /** @param list<string> $tags */
    private function encode(array $tags): string
    {
        return json_encode([
            'web_push' => self::DECLARATIVE_MAGIC,
            'mutable' => true,
            'notification' => [
                'title' => $this->title,
                'body' => $this->body,
                'navigate' => $this->url,
                'tag' => $this->tag,
                'icon' => $this->icon,
                'data' => ['url' => $this->url, 'openTags' => array_values($tags)],
            ],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
