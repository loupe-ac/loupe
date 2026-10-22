<?php

declare(strict_types=1);

namespace App\Site;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * The outside links of the site bar, the footer and the app: the community
 * chat, the X account and the source. An admin edits each one on the flags page.
 *
 * A value that is not an http or https address with a host counts as unset, so
 * a typo or a `javascript:` value never reaches an href. A flag read that fails,
 * such as a database that is down while the docs stamp runs, counts as unset too.
 */
final readonly class SiteLinks
{
    public const string DISCORD_FLAG = 'site.link.discord';

    public const string X_FLAG = 'site.link.x';

    public const string SOURCE_FLAG = 'site.link.source';

    public function __construct(
        private FeatureFlagService $featureFlags,
        private LoggerInterface $logger,

        #[Autowire(param: 'app.source_url')]
        private string $defaultSourceUrl,
    ) {
    }

    /** Empty when the link is off. */
    public function discordUrl(): string
    {
        return $this->read(self::DISCORD_FLAG);
    }

    /** Empty when the link is off. */
    public function xUrl(): string
    {
        return $this->read(self::X_FLAG);
    }

    /** The flag when it holds a link, otherwise APP_SOURCE_URL, so a fork keeps its source offer. */
    public function sourceUrl(): string
    {
        $url = $this->read(self::SOURCE_FLAG);

        return '' !== $url ? $url : $this->defaultSourceUrl;
    }

    private function read(string $flag): string
    {
        try {
            $value = trim($this->featureFlags->getStringValue($flag));
        } catch (\Throwable $e) {
            $this->logger->warning('site.link_flag_unreadable', ['flag' => $flag, 'error' => $e->getMessage()]);

            return '';
        }

        $scheme = parse_url($value, \PHP_URL_SCHEME);
        $host = parse_url($value, \PHP_URL_HOST);
        if (!\is_string($scheme) || !\in_array(strtolower($scheme), ['http', 'https'], true) || !\is_string($host) || '' === $host) {
            return '';
        }

        return $value;
    }
}
