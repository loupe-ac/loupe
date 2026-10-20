<?php

declare(strict_types=1);

namespace App\Site;

/** Facts about the site bar and footer that the docs stamp, the check and the tests share. */
final class SiteChrome
{
    /** Hosts that the bar and footer link to and that no setting configures. */
    public const array OUTSIDE_HOSTS = ['x.com'];

    /** The docs build leaves these markers in each page. website/src/chrome-markers.mjs holds the same strings. */
    public const string NAV_MARKER = '<!--LOUPE_SITE_NAV-->';

    public const string FOOTER_MARKER = '<!--LOUPE_SITE_FOOTER-->';

    /** The docs build writes this address, and the stamp replaces it with the hashed image of the running instance. */
    public const string OG_IMAGE_PLACEHOLDER = 'https://loupe.ac/assets/images/og/hero.png';
}
