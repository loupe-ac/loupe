<?php

declare(strict_types=1);

namespace App\Module\Landing\Command;

use App\Module\Landing\Service\PublicPages;

final readonly class ShowRobotsHandler
{
    public function __construct(
        private PublicPages $publicPages,
    ) {
    }

    /** @return string the body of robots.txt */
    public function __invoke(): string
    {
        $lines = ['User-agent: *'];
        foreach ($this->publicPages->paths() as $path) {
            $lines[] = 'Allow: '.$path.'$';
        }
        $lines[] = 'Allow: /assets/';
        if ($this->publicPages->hasBlog()) {
            $lines[] = 'Allow: /blog$';
            $lines[] = 'Allow: /blog/';
        }
        $lines[] = 'Disallow: /';
        $lines[] = '';
        $lines[] = 'Sitemap: '.$this->publicPages->absoluteUrl('/sitemap.xml');
        if ($this->publicPages->hasBlog()) {
            $lines[] = 'Sitemap: '.$this->publicPages->absoluteUrl('/blog/sitemap.xml');
        }

        return implode("\n", $lines)."\n";
    }
}
