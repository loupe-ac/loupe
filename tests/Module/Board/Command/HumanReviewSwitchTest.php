<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\HumanReviewCause;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\View\CardHistoryEntry;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class HumanReviewSwitchTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private CreateCardHandler $create;
    private UpdateCardHandler $update;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $create = $container->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);
        $this->create = $create;
        $update = $container->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $update);
        $this->update = $update;

        $owner = new User(fullName: 'Riley', email: 'human-review-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'human-review-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    public function test_a_new_card_does_not_require_human_review(): void
    {
        $card = $this->card();

        self::assertFalse($card->requiresHumanReview);
        self::assertSame([], $card->humanReviewSeenArtifacts);
        self::assertSame([], $this->rows($card));
    }

    public function test_a_card_created_with_the_switch_on_stores_the_artifacts_and_one_row(): void
    {
        $artifact = $this->artifact('Prototype');

        $card = $this->card(requiresHumanReview: true, artifactIds: [(string) $artifact->id]);

        self::assertTrue($card->requiresHumanReview);
        self::assertSame([(string) $artifact->id], $card->humanReviewSeenArtifacts);
        $rows = $this->rows($card);
        self::assertCount(1, $rows);
        self::assertSame(
            ['value' => true, 'cause' => 'agent', 'ruleId' => null, 'artifactIds' => [(string) $artifact->id]],
            $rows[0]->detail,
        );
    }

    public function test_a_card_created_with_the_switch_off_writes_no_row(): void
    {
        $card = $this->card(requiresHumanReview: false);

        self::assertFalse($card->requiresHumanReview);
        self::assertSame([], $this->rows($card));
    }

    public function test_setting_the_switch_stores_the_artifacts_of_the_card_and_its_parent(): void
    {
        $own = $this->artifact('Own');
        $inherited = $this->artifact('Inherited');
        $epic = $this->card(artifactIds: [(string) $inherited->id], type: 'epic');
        $card = $this->card(artifactIds: [(string) $own->id], parentCardId: (string) $epic->id);

        ($this->update)(new UpdateCardCommand($card, Actor::Human, requiresHumanReview: true));

        self::assertTrue($card->requiresHumanReview);
        $expected = [(string) $own->id, (string) $inherited->id];
        sort($expected);
        self::assertSame($expected, $card->humanReviewSeenArtifacts);
        $rows = $this->rows($card);
        self::assertCount(1, $rows);
        self::assertSame(CardEventKind::HumanReviewChanged, $rows[0]->kind);
        self::assertSame(Actor::Human, $rows[0]->actorKind);
        self::assertTrue($rows[0]->detail['value']);
        self::assertSame('person', $rows[0]->detail['cause']);
        self::assertSame($expected, $rows[0]->detail['artifactIds']);
    }

    public function test_setting_the_same_value_again_writes_no_row(): void
    {
        $card = $this->card();

        ($this->update)(new UpdateCardCommand($card, Actor::Human, requiresHumanReview: true));
        ($this->update)(new UpdateCardCommand($card, Actor::Human, requiresHumanReview: true));
        self::assertCount(1, $this->rows($card));

        $off = $this->card();
        ($this->update)(new UpdateCardCommand($off, Actor::Human, requiresHumanReview: false));
        self::assertCount(0, $this->rows($off));
    }

    public function test_a_null_value_leaves_the_switch_alone(): void
    {
        $card = $this->card();
        ($this->update)(new UpdateCardCommand($card, Actor::Human, requiresHumanReview: true));

        ($this->update)(new UpdateCardCommand($card, Actor::Human, title: 'Renamed'));

        self::assertTrue($card->requiresHumanReview);
        self::assertCount(1, $this->rows($card));
    }

    public function test_turning_the_switch_off_refreshes_the_seen_list_and_writes_a_row(): void
    {
        $card = $this->card(requiresHumanReview: true);
        $artifact = $this->artifact('Later');
        ($this->update)(new UpdateCardCommand($card, Actor::Agent, artifactIds: [(string) $artifact->id]));

        ($this->update)(new UpdateCardCommand($card, Actor::Human, requiresHumanReview: false));

        self::assertFalse($card->requiresHumanReview);
        self::assertSame([(string) $artifact->id], $card->humanReviewSeenArtifacts);
        $rows = $this->rows($card);
        self::assertCount(2, $rows);
        self::assertFalse($rows[0]->detail['value']);
        self::assertSame('person', $rows[0]->detail['cause']);
    }

    public function test_a_raise_only_update_refuses_to_lower_a_switch_that_a_person_turned_on_meanwhile(): void
    {
        $card = $this->card();
        ($this->update)(new UpdateCardCommand($card, Actor::Human, requiresHumanReview: true));
        // The caller read the card before the commit above, so its copy is stale.
        $card->requiresHumanReview = false;

        try {
            ($this->update)(new UpdateCardCommand($card, Actor::Agent, requiresHumanReview: false, onlyRaiseHumanReview: true));
            self::fail('The lowering was not refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['requiresHumanReview' => UpdateCardHandler::HUMAN_REVIEW_RAISE_ONLY], $e->errors);
        }

        $this->em->clear();
        $fresh = $this->em->find(Card::class, $card->id);
        self::assertInstanceOf(Card::class, $fresh);
        self::assertTrue($fresh->requiresHumanReview);
        self::assertCount(1, $this->rows($fresh));
    }

    public function test_a_raise_only_update_still_raises_and_leaves_other_fields_alone(): void
    {
        $card = $this->card();

        ($this->update)(new UpdateCardCommand($card, Actor::Agent, requiresHumanReview: true, onlyRaiseHumanReview: true));
        ($this->update)(new UpdateCardCommand($card, Actor::Agent, title: 'Renamed', onlyRaiseHumanReview: true));

        self::assertTrue($card->requiresHumanReview);
        self::assertSame('Renamed', $card->title);
    }

    public function test_setting_the_switch_reads_the_committed_parent_for_the_seen_list(): void
    {
        $inherited = $this->artifact('Inherited');
        $epic = $this->card(artifactIds: [(string) $inherited->id], type: 'epic');
        $card = $this->card();
        $this->em->getConnection()->executeStatement(
            'UPDATE board_cards SET parent_card_id = :parent WHERE id = :id',
            ['parent' => (string) $epic->id, 'id' => (string) $card->id],
        );

        ($this->update)(new UpdateCardCommand($card, Actor::Human, requiresHumanReview: true));

        self::assertSame([(string) $inherited->id], $card->humanReviewSeenArtifacts);
    }

    public function test_a_caller_names_the_cause_and_the_rule(): void
    {
        $card = $this->card();

        ($this->update)(new UpdateCardCommand(
            $card,
            Actor::System,
            requiresHumanReview: true,
            humanReviewCause: HumanReviewCause::Rule,
            humanReviewRuleId: 'human-review-on-artifact',
        ));

        $rows = $this->rows($card);
        self::assertSame('rule', $rows[0]->detail['cause']);
        self::assertSame('human-review-on-artifact', $rows[0]->detail['ruleId']);
    }

    public function test_the_history_renders_the_row_in_a_sentence(): void
    {
        $card = $this->card();
        ($this->update)(new UpdateCardCommand($card, Actor::Human, requiresHumanReview: true));
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $entry = CardHistoryEntry::of($this->rows($card)[0]);

        self::assertSame('board.card.history.human_review_on', $entry->sentence->getMessage());
        self::assertNull($entry->cause);
        self::assertNotSame('board.card.history.human_review_on', $translator->trans($entry->sentence->getMessage()));
    }

    public function test_the_history_names_the_rule_and_the_rollout(): void
    {
        $card = $this->card();
        ($this->update)(new UpdateCardCommand($card, Actor::System, requiresHumanReview: true, humanReviewCause: HumanReviewCause::Rule, humanReviewRuleId: 'human-review-on-artifact'));
        ($this->update)(new UpdateCardCommand($card, Actor::System, requiresHumanReview: false, humanReviewCause: HumanReviewCause::Rollout));

        [$off, $on] = $this->rows($card);

        self::assertSame('board.card.history.human_review_off', CardHistoryEntry::of($off)->sentence->getMessage());
        self::assertSame('board.card.history.cause.human_review_rollout', CardHistoryEntry::of($off)->cause?->getMessage());
        self::assertSame('board.card.history.cause.workflow_rule', CardHistoryEntry::of($on)->cause?->getMessage());
    }

    /**
     * @param list<string> $artifactIds
     */
    private function card(?bool $requiresHumanReview = null, array $artifactIds = [], string $type = 'feature', ?string $parentCardId = null): Card
    {
        return ($this->create)(new CreateCardCommand(
            project: $this->project,
            title: 'A card',
            body: '',
            type: $type,
            column: $this->column($this->project, 'backlog'),
            reporter: Actor::Agent,
            artifactIds: $artifactIds,
            parentCardId: $parentCardId,
            requiresHumanReview: $requiresHumanReview,
        ));
    }

    private function artifact(string $name): Artifact
    {
        $artifact = new Artifact($this->project, $name);
        $this->em->persist($artifact);
        $this->em->persist($artifact->addVersion());
        $this->em->flush();

        return $artifact;
    }

    /** @return list<CardEvent> newest first */
    private function rows(Card $card): array
    {
        $repository = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $repository);

        return array_values(array_filter(
            $repository->findForCard($card),
            static fn (CardEvent $event): bool => CardEventKind::HumanReviewChanged === $event->kind,
        ));
    }
}
