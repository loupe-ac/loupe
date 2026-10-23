<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\Card;
use App\Module\Board\Workflow\CardActivityFactProvider;
use App\Module\Board\Workflow\CardActivityFacts;
use App\Module\Board\Workflow\DocumentsFactProvider;
use App\Module\Board\Workflow\DocumentsFacts;
use App\Module\Board\Workflow\PauseFactProvider;
use App\Module\Board\Workflow\PauseFacts;
use App\Module\Board\Workflow\StuckDelayFactProvider;
use App\Module\Board\Workflow\StuckDelayFacts;
use App\Module\Workflow\Contract\FactProvider;
use App\Tests\Module\Board\CardStateFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MarkerFactProvidersTest extends KernelTestCase
{
    use CardStateFixtures;

    private Card $card;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->card = $this->stateCard($this->stateProject('board-markers'));
    }

    public function test_the_active_pause_of_the_card_is_the_fact(): void
    {
        self::assertNull($this->build(PauseFactProvider::class, PauseFacts::class)->active);
        self::assertNull($this->provider(PauseFactProvider::class)->fingerprint($this->build(PauseFactProvider::class, PauseFacts::class)));

        $pause = $this->pauseCard($this->card, '2026-10-02 09:00:00');
        $facts = $this->build(PauseFactProvider::class, PauseFacts::class);

        self::assertSame('review-failed', $facts->active?->reason);
        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:00:00'), $facts->active->createdAt);
        self::assertSame($pause->id?->toRfc4122(), $this->provider(PauseFactProvider::class)->fingerprint($facts));

        $pause->releasedAt = new \DateTimeImmutable('2026-10-02 10:00:00');
        $this->em()->flush();

        self::assertNull($this->build(PauseFactProvider::class, PauseFacts::class)->active);
    }

    public function test_the_newest_event_of_the_card_is_its_activity(): void
    {
        self::assertNull($this->build(CardActivityFactProvider::class, CardActivityFacts::class)->newestEventAt);

        $this->recordHistory($this->card, '2026-10-02 10:00:00');
        $this->recordHistory($this->card, '2026-10-02 09:00:00');
        $facts = $this->build(CardActivityFactProvider::class, CardActivityFacts::class);

        self::assertEquals(new \DateTimeImmutable('2026-10-02 10:00:00'), $facts->newestEventAt);
        self::assertNotNull($this->provider(CardActivityFactProvider::class)->fingerprint($facts));
    }

    public function test_the_stuck_delay_of_the_project_is_the_fact(): void
    {
        $this->em()->persist(new BoardAutomationSettings($this->card->project, stuckDelayMinutes: 25));
        $this->em()->flush();

        $facts = $this->build(StuckDelayFactProvider::class, StuckDelayFacts::class);

        self::assertSame(25, $facts->minutes);
        self::assertSame(25, $this->provider(StuckDelayFactProvider::class)->fingerprint($facts));
    }

    public function test_a_linked_document_gives_its_title_and_the_time_and_number_of_its_newest_version(): void
    {
        $document = $this->reviewDocument($this->card, 'tech-design', '2026-10-02 09:20:00');

        $facts = $this->build(DocumentsFactProvider::class, DocumentsFacts::class);

        self::assertCount(1, $facts->documents);
        self::assertSame([$document->title, 'in-review', ['tech-design']], [$facts->documents[0]->title, $facts->documents[0]->status, $facts->documents[0]->tags]);
        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:20:00'), $facts->documents[0]->versionAt);
        self::assertSame(1, $facts->documents[0]->version);
    }

    /**
     * @template T of object
     *
     * @param class-string<FactProvider> $provider
     * @param class-string<T>            $class
     *
     * @return T
     */
    private function build(string $provider, string $class): object
    {
        $facts = $this->provider($provider)->build($this->card->snapshot());
        self::assertInstanceOf($class, $facts);

        return $facts;
    }

    /** @param class-string<FactProvider> $class */
    private function provider(string $class): FactProvider
    {
        $provider = self::getContainer()->get($class);
        self::assertInstanceOf(FactProvider::class, $provider);

        return $provider;
    }
}
