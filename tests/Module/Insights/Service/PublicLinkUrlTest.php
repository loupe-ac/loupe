<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Insights\Service\PublicLinkUrl;
use App\Site\SiteHost;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PublicLinkUrlTest extends TestCase
{
    public function test_a_split_site_host_gives_the_address_of_the_site_host(): void
    {
        $url = new PublicLinkUrl($this->generator(), new SiteHost('loupe.ac', 'https://app.loupe.test'));

        self::assertSame('https://loupe.ac/s/abcdefghijkl', $url->forCode('abcdefghijkl'));
    }

    public function test_an_instance_with_no_site_host_gives_the_address_on_the_app_host(): void
    {
        $url = new PublicLinkUrl($this->generator(), new SiteHost(null, 'https://app.loupe.test'));

        self::assertSame('https://app.loupe.test/s/abcdefghijkl', $url->forCode('abcdefghijkl'));
    }

    public function test_a_site_host_equal_to_the_app_host_gives_the_address_on_that_host(): void
    {
        $url = new PublicLinkUrl($this->generator(), new SiteHost('app.loupe.test', 'https://app.loupe.test'));

        self::assertSame('https://app.loupe.test/s/abcdefghijkl', $url->forCode('abcdefghijkl'));
    }

    private function generator(): UrlGeneratorInterface
    {
        $generator = $this->createStub(UrlGeneratorInterface::class);
        $generator->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters, int $type): string => (UrlGeneratorInterface::ABSOLUTE_URL === $type ? 'https://app.loupe.test' : '').'/s/'.$parameters['code'],
        );

        return $generator;
    }
}
