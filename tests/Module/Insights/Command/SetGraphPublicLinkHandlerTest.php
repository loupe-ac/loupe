<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Insights\Command\SetGraphPublicLinkCommand;
use App\Module\Insights\Command\SetGraphPublicLinkHandler;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Messenger\DrawPublicPreview;
use App\Module\Insights\Repository\PublicLinkRepository;
use App\Module\Insights\Service\InsightsRefreshRequester;
use App\Module\Insights\Service\PublicLinkCode;
use App\Module\Insights\Service\PublicPreviewRequester;
use App\Module\Insights\Service\PublicShareGuard;
use App\Module\Insights\Service\SavedGraphLocker;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class SetGraphPublicLinkHandlerTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_the_first_share_creates_the_link_with_a_code_and_turns_it_on(): void
    {
        $graph = $this->seedSavedGraph($this->scenarioProject('share-first'));

        $this->share($graph, true);

        $link = $this->linkOf($graph);
        self::assertNotNull($link);
        self::assertTrue($link->enabled);
        self::assertMatchesRegularExpression('/^'.PublicLinkCode::PATTERN.'$/', $link->code);
    }

    public function test_turning_a_link_on_asks_for_its_preview_image_and_turning_it_off_does_not(): void
    {
        $graph = $this->seedSavedGraph($this->scenarioProject('share-preview'));
        $transport = self::getContainer()->get('messenger.transport.insights');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $this->share($graph, true);

        $drawn = array_values(array_filter(
            array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof DrawPublicPreview,
        ));
        self::assertCount(1, $drawn);
        self::assertSame($this->linkOf($graph)?->code, $drawn[0]->code);

        $transport->reset();
        $this->share($graph, false);
        self::assertSame([], array_filter(
            array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof DrawPublicPreview,
        ));
    }

    public function test_off_and_on_change_the_switch_and_keep_the_code(): void
    {
        $graph = $this->seedSavedGraph($this->scenarioProject('share-switch'));
        $this->share($graph, true);
        $code = $this->linkOf($graph)?->code;

        $this->share($graph, false);
        self::assertFalse($this->linkOf($graph)?->enabled);
        self::assertSame($code, $this->linkOf($graph)->code);

        $this->share($graph, true);
        self::assertTrue($this->linkOf($graph)?->enabled);
        self::assertSame($code, $this->linkOf($graph)->code);
    }

    public function test_switching_off_a_graph_that_was_never_shared_creates_no_link(): void
    {
        $graph = $this->seedSavedGraph($this->scenarioProject('share-never'));

        $this->share($graph, false);

        self::assertNull($this->linkOf($graph));
    }

    public function test_a_blocked_share_fails_with_the_reason_and_changes_nothing(): void
    {
        $graph = $this->seedSavedGraph($this->scenarioProject('share-blocked'));
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[PublicLink::FLAG]->value = false;
        $this->em()->flush();

        try {
            $this->share($graph, true);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['enabled' => 'insights.public_link.block.flag_off'], $e->errors);
        }

        self::assertTrue($this->em()->isOpen());
        self::assertNull($this->linkOf($graph));
    }

    public function test_a_takedown_blocks_a_share_with_its_reason_and_keeps_the_owner_switch(): void
    {
        $project = $this->scenarioProject('share-takedown');
        $graph = $this->seedSavedGraph($project);
        $this->share($graph, true);
        $this->seedTakedown($project);

        foreach ([false, true] as $enabled) {
            try {
                $this->share($graph, $enabled);
                self::fail('Expected a refusal.');
            } catch (DomainErrors $e) {
                self::assertSame(['enabled' => 'insights.public_link.block.taken_down'], $e->errors);
            }
        }

        self::assertTrue($this->em()->isOpen());
        self::assertTrue($this->linkOf($graph)?->enabled);
    }

    public function test_a_removed_graph_cannot_be_shared(): void
    {
        $graph = $this->seedSavedGraph($this->scenarioProject('share-removed'), removedAt: new \DateTimeImmutable('-1 hour'));

        try {
            $this->share($graph, true);
            self::fail('Expected a not found.');
        } catch (NotFoundHttpException) {
        }

        self::assertTrue($this->em()->isOpen());
        self::assertNull($this->linkOf($graph));
    }

    private function share(InsightsSavedGraph $graph, bool $enabled): void
    {
        $handler = new SetGraphPublicLinkHandler(
            $this->em(),
            $this->service(PublicLinkRepository::class),
            $this->service(SavedGraphLocker::class),
            $this->service(PublicShareGuard::class),
            $this->service(Auditor::class),
            $this->service(InsightsRefreshRequester::class),
            $this->service(PublicPreviewRequester::class),
        );
        $handler(new SetGraphPublicLinkCommand($this->em()->find(InsightsSavedGraph::class, $graph->id) ?? throw new \LogicException(), $enabled));
    }

    private function linkOf(InsightsSavedGraph $graph): ?PublicLink
    {
        $this->em()->clear();
        $links = $this->service(PublicLinkRepository::class);
        $stored = $this->em()->find(InsightsSavedGraph::class, $graph->id);

        return null === $stored ? null : $links->findForGraph($stored);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
