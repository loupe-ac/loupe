<?php

declare(strict_types=1);

namespace App\Site;

/** Facts about the site bar and footer that the stamp check and the tests share. */
final class SiteChrome
{
    /** Hosts that the bar and footer link to and that no setting configures. */
    public const array OUTSIDE_HOSTS = ['x.com'];
}
