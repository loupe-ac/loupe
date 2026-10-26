<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Landing\Service\ComparisonPages;
use App\Site\SiteHost;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/** Replaces the comparison pages of a test container with pages from a temporary folder. */
final class ComparisonFixtures
{
    public const string SLUG = 'fixture-rival';

    public const string DESCRIPTION = "Where Loupe and Fixture's Rival differ.";

    /** @return string the folder, for {@see self::remove()} */
    public static function install(ContainerInterface $container, SiteHost $siteHost, string $appUrl): string
    {
        $directory = sys_get_temp_dir().'/compare-fixture-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($directory);
        file_put_contents($directory.'/'.self::SLUG.'.md', <<<'MD'
            ---
            competitor: "Fixture's Rival"
            title: "Loupe vs Fixture's Rival"
            description: "Where Loupe and Fixture's Rival differ."
            comparedOn: 2026-11-02
            competitorVersion: "v1.0.0 (2026-10-01)"
            ---
            A paragraph with a [source](https://rival.example/readme).

            ## Cost

            | Tool | Cost |
            |---|---|
            | Loupe | Hosting |
            MD);
        $container->set(ComparisonPages::class, new ComparisonPages($directory, $siteHost, $appUrl));

        return $directory;
    }

    public static function remove(string $directory): void
    {
        new Filesystem()->remove($directory);
    }
}
