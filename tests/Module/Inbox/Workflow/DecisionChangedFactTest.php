<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Workflow\Condition\CardDecisionChanged;
use App\Module\Inbox\Workflow\DecisionChangedFactProvider;
use App\Module\Inbox\Workflow\DecisionChangedFacts;
use App\Tests\Module\Board\CardStateFixtures;
use App\Tests\Module\Inbox\DecisionFixtures;
use App\Tests\Module\Inbox\InboxFixtures;
use App\Tests\Module\Workflow\Fact\FactsMother;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class DecisionChangedFactTest extends KernelTestCase
{
    use CardStateFixtures;
    use DecisionFixtures;
    use InboxFixtures {
        CardStateFixtures::em insteadof InboxFixtures;
    }

    private Card $card;

    protected function setUp(): void
    {
        self::bootKernel();
        $project = $this->stateProject('inbox-decision-changed');
        $this->card = $this->stateCard($project);
        $this->setInboxFlag(true);
    }

    public function test_a_decision_the_owner_did_not_change_is_no_fact(): void
    {
        $this->decision($this->em(), $this->card->project, $this->card);
        $this->em()->flush();

        self::assertFalse($this->build()->open);
    }

    public function test_a_change_that_no_worker_addressed_is_the_fact(): void
    {
        $decision = $this->decision($this->em(), $this->card->project, $this->card);
        $decision->changedChoice = 'alpha';
        $decision->changedAt = new \DateTimeImmutable();
        $this->em()->flush();

        self::assertTrue($this->build()->open);
        self::assertTrue($this->provider()->fingerprint($this->build()));
    }

    public function test_an_addressed_change_is_no_fact_again(): void
    {
        $decision = $this->decision($this->em(), $this->card->project, $this->card);
        $decision->changedChoice = 'alpha';
        $decision->changedAt = new \DateTimeImmutable('-1 hour');
        $decision->addressedAt = new \DateTimeImmutable();
        $this->em()->flush();

        self::assertFalse($this->build()->open);
    }

    public function test_a_change_on_another_card_is_not_the_fact_of_this_card(): void
    {
        $other = $this->stateCard($this->card->project);
        $decision = $this->decision($this->em(), $this->card->project, $other);
        $decision->changedChoice = 'alpha';
        $decision->changedAt = new \DateTimeImmutable();
        $this->em()->flush();

        self::assertFalse($this->build()->open);
    }

    public function test_an_inbox_that_is_off_gives_no_fact(): void
    {
        $decision = $this->decision($this->em(), $this->card->project, $this->card);
        $decision->changedChoice = 'alpha';
        $decision->changedAt = new \DateTimeImmutable();
        $this->em()->flush();
        $this->setInboxFlag(false);

        self::assertTrue($this->provider()->isOn());
        self::assertFalse($this->build()->open);
    }

    public function test_the_condition_reads_the_fact_and_names_its_wait(): void
    {
        $condition = new CardDecisionChanged();

        self::assertSame('card.inbox.decision_changed', $condition::key());
        self::assertTrue($condition->evaluate(FactsMother::facts(provided: [DecisionChangedFacts::class => new DecisionChangedFacts(true)]), []));
        self::assertFalse($condition->evaluate(FactsMother::facts(provided: [DecisionChangedFacts::class => new DecisionChangedFacts(false)]), []));
        self::assertSame('workflow.waiting.card_inbox_decision_changed', $condition->waitingFor([])->getMessage());
        self::assertSame('workflow.waiting.not.card_inbox_decision_changed', $condition->waitingFor([], negated: true)->getMessage());
    }

    private function setInboxFlag(bool $enabled): void
    {
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[InboxInstallFlags::FLAG_INBOX_ENABLED]->value = $enabled;
        $this->em()->flush();
    }

    private function build(): DecisionChangedFacts
    {
        $facts = $this->provider()->build($this->card->snapshot());
        self::assertInstanceOf(DecisionChangedFacts::class, $facts);

        return $facts;
    }

    private function provider(): DecisionChangedFactProvider
    {
        $provider = self::getContainer()->get(DecisionChangedFactProvider::class);
        self::assertInstanceOf(DecisionChangedFactProvider::class, $provider);

        return $provider;
    }
}
