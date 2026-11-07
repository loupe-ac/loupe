<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Twig;

use App\Module\Board\Service\CardMarkerText;
use App\Module\Board\Twig\MarkerLinkExtension;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\MarkerLink;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final class MarkerLinkExtensionTest extends KernelTestCase
{
    /** @return iterable<string, array{MarkerLink, bool, MarkerLink, string}> */
    public static function links(): iterable
    {
        yield 'inbox while the inbox is on' => [MarkerLink::Inbox, true, MarkerLink::Inbox, 'app_project_inbox'];
        yield 'inbox while the inbox is off' => [MarkerLink::Inbox, false, MarkerLink::Repositories, 'app_project_repositories_settings'];
        yield 'repositories while the inbox is off' => [MarkerLink::Repositories, false, MarkerLink::Repositories, 'app_project_repositories_settings'];
        yield 'agents while the inbox is off' => [MarkerLink::Agents, false, MarkerLink::Agents, 'app_project_agents'];
    }

    #[DataProvider('links')]
    public function test_a_marker_never_links_to_a_page_that_is_switched_off(MarkerLink $link, bool $inboxOn, MarkerLink $target, string $route): void
    {
        $flags = self::createStub(FeatureFlagService::class);
        $flags->method('isEnabled')->willReturnCallback(static fn (string $name): bool => 'inbox.enabled' === $name && $inboxOn);
        $urls = self::createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $name): string => $name);
        $markerText = self::getContainer()->get(CardMarkerText::class);
        self::assertInstanceOf(CardMarkerText::class, $markerText);
        $project = self::createStub(Project::class);
        $project->method('requireId')->willReturn(Uuid::v7());

        $extension = new MarkerLinkExtension($urls, $markerText, $flags);

        self::assertSame($target, $extension->target($link));
        self::assertSame($route, $extension->href($link, $project));
    }
}
