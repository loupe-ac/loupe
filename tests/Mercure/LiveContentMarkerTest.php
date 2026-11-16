<?php

declare(strict_types=1);

namespace App\Tests\Mercure;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * A page that holds a live region must mark content that a lost-access answer hides.
 * Partials sit inside a page that carries the marker, and the projects list is
 * account-level, so a project deletion does not remove it.
 */
final class LiveContentMarkerTest extends TestCase
{
    private const array EXEMPT = ['Project/list_projects.html.twig'];

    public function test_every_page_with_a_live_region_marks_its_live_content(): void
    {
        $missing = [];
        $finder = new Finder()->files()->in(__DIR__.'/../../templates')->name('*.html.twig')->notName('_*');
        foreach ($finder as $file) {
            $source = $file->getContents();
            if (!str_contains($source, 'data-controller="live-region"') && !str_contains($source, 'data-controller="live-region ')) {
                continue;
            }
            if (str_contains($source, 'data-live-content')) {
                continue;
            }
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            if (in_array(preg_replace('#^Module/#', '', $relative), self::EXEMPT, true)) {
                continue;
            }
            $missing[] = $relative;
        }

        self::assertSame([], $missing, 'Add data-live-content to the page content of these templates.');
    }
}
