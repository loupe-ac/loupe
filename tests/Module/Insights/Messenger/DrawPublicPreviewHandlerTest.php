<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Messenger;

use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Messenger\DrawPublicPreview;
use App\Module\Insights\Messenger\DrawPublicPreviewHandler;
use App\Module\Insights\Preview\PreviewRenderer;
use App\Module\Insights\Preview\PublicPreviewDrawer;
use App\Module\Insights\Preview\PublicPreviewEntries;
use App\Module\Insights\Preview\PublicPreviewSubject;
use App\Module\Insights\Service\PublicGraphResults;
use App\Module\Insights\Service\PublicLinkVisibility;
use App\Tests\Module\Insights\Preview\ScriptedSubjects;
use App\Tests\Module\Insights\PublicPreviewScenario;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Twig\Environment;

final class DrawPublicPreviewHandlerTest extends KernelTestCase
{
    use PublicPreviewScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_draws_and_keeps_the_preview_of_a_visible_graph_link(): void
    {
        $link = $this->sharedCostGraph('message-draws');

        $this->handler()(new DrawPublicPreview($link->code));

        self::assertNotNull($this->entries()->entryFor($link->code));
    }

    public function test_a_hidden_link_and_an_unknown_code_draw_nothing(): void
    {
        $off = $this->sharedCostGraph('message-off');
        $off->enabled = false;
        $this->em()->flush();

        $this->handler()(new DrawPublicPreview($off->code));
        $this->handler()(new DrawPublicPreview('aaaaaaaaaaaa'));

        self::assertNull($this->entries()->entryFor($off->code));
    }

    public function test_an_experiment_link_draws_nothing(): void
    {
        $project = $this->scenarioProject('message-experiment');
        $this->seedExperiment($project, 'trial');
        $link = PublicLink::forExperiment($project, 'trial');
        $this->em()->persist($link);
        $this->em()->flush();

        $this->handler()(new DrawPublicPreview($link->code));

        self::assertNull($this->entries()->entryFor($link->code));
    }

    public function test_a_result_that_never_settles_keeps_nothing_and_does_not_fail_the_message(): void
    {
        $link = $this->sharedCostGraph('message-unsettled');
        $subject = static fn (string $version): PublicPreviewSubject => new PublicPreviewSubject($version);

        $this->handler(new ScriptedSubjects([$subject('1'), $subject('2'), $subject('3'), $subject('4'), $subject('5')]))(new DrawPublicPreview($link->code));

        self::assertNull($this->entries()->entryFor($link->code));
    }

    private function handler(?ScriptedSubjects $subjects = null): DrawPublicPreviewHandler
    {
        $twig = self::getContainer()->get(Environment::class);
        $results = self::getContainer()->get(PublicGraphResults::class);
        $visibility = self::getContainer()->get(PublicLinkVisibility::class);
        self::assertInstanceOf(Environment::class, $twig);
        self::assertInstanceOf(PublicGraphResults::class, $results);
        self::assertInstanceOf(PublicLinkVisibility::class, $visibility);
        $live = self::getContainer()->get(\App\Module\Insights\Preview\PublicPreviewSubjects::class);
        self::assertInstanceOf(\App\Module\Insights\Preview\PublicPreviewSubjects::class, $live);
        $drawer = new PublicPreviewDrawer(new LockFactory(new InMemoryStore()), $subjects ?? $live, $this->entries(), $results, new PreviewRenderer(__DIR__.'/../Preview/fake-rsvg-convert'), $twig);

        return new DrawPublicPreviewHandler($visibility, $drawer, new NullLogger());
    }

    private function entries(): PublicPreviewEntries
    {
        $entries = self::getContainer()->get(PublicPreviewEntries::class);
        self::assertInstanceOf(PublicPreviewEntries::class, $entries);

        return $entries;
    }
}
