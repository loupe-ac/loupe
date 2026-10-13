<?php

declare(strict_types=1);

namespace App\Module\Landing\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class HomePageGate
{
    public function __construct(
        #[Autowire(param: 'app.url')]
        private string $appUrl,

        #[Autowire(param: 'app.home_page_host')]
        private ?string $hostedHost,
    ) {
    }

    /** Reads the configured address, never the request, so a forged host header changes nothing. */
    public function shows(): bool
    {
        if (null === $this->hostedHost) {
            return true;
        }

        $host = parse_url($this->appUrl, \PHP_URL_HOST);

        return \is_string($host) && 0 === strcasecmp($host, $this->hostedHost);
    }
}
