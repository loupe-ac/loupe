<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardArtifactCardLinks;
use App\Module\Board\Workflow\ArtifactReachFactProvider;
use App\Module\Board\Workflow\ArtifactReachFacts;
use App\Module\Board\Workflow\RequireHumanReview;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Tests\Module\Board\CardStateFixtures;
use App\Tests\Module\Workflow\Action\ActionScenario;
use App\Tests\Module\Workflow\Fact\FactsMother;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ArtifactReachTest extends KernelTestCase
{
    use ActionScenario;
    use CardStateFixtures;

    public function test_the_fact_lists_the_artifacts_of_the_card_and_its_parent_that_the_seen_list_lacks(): void
    {
        self::bootKernel();
        [$epic, $child] = $this->epicWithChild('reach-fact');
        $own = $this->artifact($child, 'Own');
        $lent = $this->artifact($epic, 'Lent');
        $child->humanReviewSeenArtifacts = [(string) $lent->id];
        $this->em()->flush();

        $facts = $this->provider()->build($child->snapshot());

        self::assertInstanceOf(ArtifactReachFacts::class, $facts);
        self::assertSame([(string) $own->id], $facts->unseenArtifactIds);
        self::assertSame([(string) $own->id], $this->provider()->fingerprint($facts));
    }

    public function test_a_card_with_no_artifact_has_nothing_unseen(): void
    {
        self::bootKernel();
        [, $child] = $this->epicWithChild('reach-none');

        $facts = $this->provider()->build($child->snapshot());

        self::assertInstanceOf(ArtifactReachFacts::class, $facts);
        self::assertSame([], $facts->unseenArtifactIds);
    }

    public function test_the_action_turns_the_switch_on_once_and_names_the_rule_and_the_artifacts(): void
    {
        self::bootKernel();
        [, $child] = $this->epicWithChild('reach-action');
        $artifact = $this->artifact($child, 'Prototype');

        self::assertEquals(ActionOutcome::done(), $this->require($child));
        self::assertEquals(ActionOutcome::done(), $this->require($child));

        $this->em()->clear();
        $stored = $this->em()->find(Card::class, $child->id) ?? throw new \LogicException('The card exists.');
        self::assertTrue($stored->requiresHumanReview);
        self::assertSame([(string) $artifact->id], $stored->humanReviewSeenArtifacts);
        $rows = array_values(array_filter(
            $this->service(CardEventRepository::class)->findForCard($stored),
            static fn (CardEvent $event): bool => CardEventKind::HumanReviewChanged === $event->kind,
        ));
        self::assertCount(1, $rows);
        self::assertSame(Actor::System, $rows[0]->actorKind);
        self::assertEquals(
            ['value' => true, 'cause' => 'rule', 'ruleId' => 'human-review-on-artifact', 'artifactIds' => [(string) $artifact->id]],
            $rows[0]->detail,
        );
        $facts = $this->provider()->build($stored->snapshot());
        self::assertInstanceOf(ArtifactReachFacts::class, $facts);
        self::assertSame([], $facts->unseenArtifactIds, 'The artifact is seen, so the rule does not fire again for it.');
    }

    public function test_linking_a_stored_artifact_to_an_epic_asks_for_a_check_of_the_epic_and_each_child(): void
    {
        self::bootKernel();
        [$epic, $child] = $this->epicWithChild('reach-store');
        $other = $this->stateCard($epic->project);
        $artifact = new Artifact($epic->project, 'Prototype');
        $this->em()->persist($artifact);
        $this->em()->flush();
        $this->transport()->reset();

        $this->service(BoardArtifactCardLinks::class)->link($epic->project, (string) $epic->id, $artifact);

        self::assertEqualsCanonicalizing([$this->id($epic), $this->id($child)], $this->sent());
        self::assertNotContains($this->id($other), $this->sent());
    }

    public function test_a_card_update_that_sends_artifact_ids_asks_for_a_check_of_each_child(): void
    {
        self::bootKernel();
        [$epic, $child] = $this->epicWithChild('reach-update');
        $artifact = new Artifact($epic->project, 'Prototype');
        $this->em()->persist($artifact);
        $this->em()->flush();
        $this->transport()->reset();

        ($this->service(UpdateCardHandler::class))(new UpdateCardCommand(card: $epic, actor: Actor::Agent, artifactIds: [(string) $artifact->id]));

        self::assertContains($this->id($child), $this->sent());
        self::assertContains($this->id($epic), $this->sent());
    }

    /** @return array{Card, Card} */
    private function epicWithChild(string $name): array
    {
        $project = $this->stateProject($name);
        $epic = $this->stateCard($project);
        $child = $this->stateCard($project);
        $child->parent = $epic;
        $this->em()->flush();

        return [$epic, $child];
    }

    private function artifact(Card $card, string $name): Artifact
    {
        $artifact = new Artifact($card->project, $name);
        $this->em()->persist($artifact);
        $card->linkArtifact($artifact);
        $this->em()->flush();

        return $artifact;
    }

    private function provider(): ArtifactReachFactProvider
    {
        return new ArtifactReachFactProvider($this->service(CardRepository::class));
    }

    private function require(Card $card): ActionOutcome
    {
        $this->em()->clear();
        $card = $this->em()->find(Card::class, $card->id) ?? throw new \LogicException('The card exists.');
        $rule = $this->rule('require-human-review', [], 'human-review-on-artifact');
        $action = new RequireHumanReview($this->service(CardRepository::class), $this->service(UpdateCardHandler::class));

        return $this->runAction($action, $rule, $card->snapshot(), FactsMother::facts(), $this->state($card, $rule->id));
    }

    private function id(Card $card): string
    {
        return ($card->id ?? throw new \LogicException('A flushed card has an id.'))->toRfc4122();
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.card_eval');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** @return list<string> */
    private function sent(): array
    {
        $ids = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof EvaluateCard) {
                $ids[] = $message->cardId;
            }
        }

        return $ids;
    }
}
