<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Workflow\OpenQuestionFactProvider;
use App\Module\Inbox\Workflow\OpenQuestionFacts;
use App\Tests\Module\Board\CardStateFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class OpenQuestionFactProviderTest extends KernelTestCase
{
    use CardStateFixtures;

    private Card $card;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->card = $this->stateCard($this->stateProject('inbox-question'));
    }

    public function test_the_oldest_open_question_that_names_the_card_is_the_fact(): void
    {
        $this->askOwner($this->card, at: '2026-10-02 09:30:00');
        $oldest = $this->askOwner($this->card, at: '2026-10-02 09:15:00');

        $question = $this->build()->question;

        self::assertNotNull($question);
        self::assertSame([$oldest->number, 'Which option?'], [$question->number, $question->title]);
        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:15:00'), $question->since);
        self::assertSame($oldest->number, $this->provider()->fingerprint($this->build()));
    }

    public function test_an_answered_question_a_wait_and_a_notice_are_not_open_questions(): void
    {
        $this->askOwner($this->card)->state = InboxItemState::Answered;
        $this->em()->flush();
        $this->askOwner($this->card, InboxItemKind::Wait);
        $this->askOwner($this->card, InboxItemKind::Notice);

        self::assertNull($this->build()->question);
        self::assertNull($this->provider()->fingerprint($this->build()));
    }

    public function test_the_provider_is_on_while_the_inbox_is_on(): void
    {
        $this->setInboxFlag(true);

        self::assertTrue($this->provider()->isOn());
    }

    public function test_the_provider_is_off_while_the_inbox_is_off(): void
    {
        $this->setInboxFlag(false);

        self::assertFalse($this->provider()->isOn());
    }

    private function setInboxFlag(bool $enabled): void
    {
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[InboxInstallFlags::FLAG_INBOX_ENABLED]->value = $enabled;
        $this->em()->flush();
    }

    private function build(): OpenQuestionFacts
    {
        $facts = $this->provider()->build($this->card->snapshot());
        self::assertInstanceOf(OpenQuestionFacts::class, $facts);

        return $facts;
    }

    private function provider(): OpenQuestionFactProvider
    {
        $provider = self::getContainer()->get(OpenQuestionFactProvider::class);
        self::assertInstanceOf(OpenQuestionFactProvider::class, $provider);

        return $provider;
    }
}
