<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Exception\DomainErrors;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Board\Command\DeleteCardCommand;
use App\Module\Board\Command\DeleteCardHandler;
use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Command\ReleaseCardPauseCommand;
use App\Module\Board\Command\ReleaseCardPauseHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\PublishDocumentCommand;
use App\Module\Review\Command\PublishDocumentHandler;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\PauseKind;
use App\Tests\Module\AgentReview\DocumentReviewScenario;
use App\Tests\Module\Workflow\WorkflowProjects;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

/**
 * Drives each change that alters what the band of a draft shows, and reads
 * what reaches the hub. The signal names the type alone.
 */
final class DraftStateSignalTest extends KernelTestCase
{
    use DocumentReviewScenario;
    use WorkflowProjects;

    private EntityManagerInterface $em;
    private Project $project;
    private ?Tag $designTag = null;

    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        self::bootKernel();

        // Before anything builds the hub: the container hands the replacement
        // only to what it builds afterwards.
        self::getContainer()->set('mercure.hub.default', new MockHub(
            'http://mercure/.well-known/mercure',
            new StaticTokenProvider('token'),
            function (Update $update): string {
                $this->published[] = $update;

                return 'id';
            },
        ));

        $this->em = $this->em();
        $this->project = $this->workflowProject('draft-state');
        $this->bindLifecycle($this->project);
    }

    public function test_a_pause_start_signals_the_draft_of_the_card(): void
    {
        $card = $this->card();
        $document = $this->designDraft($card);
        $this->drain($document);

        $this->pause($card);

        self::assertSame([['type' => 'review.draft_state_changed', 'origin' => null]], $this->drain($document));
    }

    public function test_a_pause_release_signals_the_draft_of_the_card(): void
    {
        $card = $this->card();
        $document = $this->designDraft($card);
        $this->pause($card);
        $this->drain($document);

        $pause = $this->service(CardPauseRepository::class)->findActiveForCard($card);
        self::assertNotNull($pause);
        $this->service(ReleaseCardPauseHandler::class)(new ReleaseCardPauseCommand($pause, 'released'));

        self::assertCount(1, $this->drain($document));
    }

    public function test_a_marker_change_of_the_card_signals_a_new_round(): void
    {
        $card = $this->card();
        $document = $this->designDraft($card);
        $this->documentReview($this->project, $card, $document, 1, 1);
        $this->drain($document);

        $this->service(EventDispatcherInterface::class)->dispatch(new CardChanged(
            $this->project->requireId(),
            $card->id ?? throw new \LogicException('The card is flushed.'),
            CardChanged::UPDATED,
            false,
            markersOnly: true,
        ));

        self::assertCount(1, $this->drain($document));
    }

    public function test_a_card_delete_signals_the_draft_of_the_card(): void
    {
        $card = $this->card();
        $document = $this->designDraft($card);
        $this->drain($document);

        $this->service(DeleteCardHandler::class)(new DeleteCardCommand($this->reloaded($card), Actor::Human));

        self::assertCount(1, $this->drain($document));
    }

    public function test_an_unlink_from_the_card_signals_the_draft(): void
    {
        $card = $this->card();
        $document = $this->designDraft($card);
        $this->drain($document);

        $this->service(UpdateCardHandler::class)(new UpdateCardCommand($this->reloaded($card), Actor::Agent, documentIds: []));

        self::assertCount(1, $this->drain($document));
    }

    public function test_an_unlink_signals_no_draft_without_a_design_tag(): void
    {
        $card = $this->card();
        $plain = $this->linkedDocument($card);
        $this->drain($plain);

        $this->service(UpdateCardHandler::class)(new UpdateCardCommand($this->reloaded($card), Actor::Agent, documentIds: []));

        self::assertSame([], $this->drain($plain));
    }

    public function test_a_card_change_signals_no_document_that_is_not_a_draft_of_a_design_tag(): void
    {
        $card = $this->card();
        $plain = $this->linkedDocument($card);
        $inReview = $this->designDraft($card, DocumentStatus::InReview);
        $other = $this->linkedDocument($card);
        $tag = new Tag($this->project, 'notes');
        $this->em->persist($tag);
        $other->tags->add($tag);
        $this->em->flush();
        $designDraft = $this->designDraft($card);
        $this->drain($designDraft);

        $this->pause($card);

        self::assertCount(1, $this->drain($designDraft));
        self::assertSame([], $this->drain($plain));
        self::assertSame([], $this->drain($inReview));
        self::assertSame([], $this->drain($other));
    }

    public function test_a_publish_signals_the_document(): void
    {
        $document = $this->designDraft($this->card());
        $this->drain($document);

        $this->service(PublishDocumentHandler::class)(new PublishDocumentCommand($document));

        self::assertCount(1, $this->drain($document));
    }

    public function test_a_verdict_signals_the_document(): void
    {
        $document = $this->designDraft($this->card());
        $this->drain($document);

        $this->verdict($document, 'approved', 1);

        self::assertCount(1, $this->drain($document));
    }

    public function test_a_refused_verdict_signals_nothing(): void
    {
        $document = $this->designDraft($this->card());
        $this->drain($document);

        try {
            $this->verdict($document, 'approved', 9);
            self::fail('The verdict must be refused.');
        } catch (DomainErrors) {
        }

        self::assertSame([], $this->drain($document));
    }

    private function verdict(Document $document, string $verdict, int $versionNumber): void
    {
        $this->service(SubmitReviewHandler::class)(new SubmitReviewCommand($this->project->owner, $document, $verdict, $versionNumber));
    }

    private function card(): Card
    {
        $card = new Card($this->project, $this->column($this->project, 'tech-design'), 'Ship it', '', 4);
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }

    private function designDraft(Card $card, DocumentStatus $status = DocumentStatus::Draft): Document
    {
        $document = $this->linkedDocument($card, $status);
        $tag = $this->designTag ??= new Tag($this->project, 'tech-design');
        $this->em->persist($tag);
        $document->tags->add($tag);
        $this->em->flush();

        return $document;
    }

    /** A card read from the database, so its document links are all loaded, as in a request. */
    private function reloaded(Card $card): Card
    {
        $id = $card->id ?? throw new \LogicException('The card is flushed.');
        $this->em->clear();

        return $this->em->find(Card::class, $id) ?? throw new \LogicException('The card is stored.');
    }

    private function pause(Card $card): void
    {
        $this->service(PauseCardHandler::class)(new PauseCardCommand($card, 'repair-failed', 'tech-design-review', PauseKind::Retries));
    }

    /**
     * Ends the request the way the kernel does, and answers the draft state
     * messages that the topic of the document received since the last call.
     *
     * @return list<array<string, mixed>>
     */
    private function drain(Document $document): array
    {
        $dispatcher = $this->service(EventDispatcherInterface::class);
        self::assertNotNull(self::$kernel);
        $dispatcher->dispatch(new TerminateEvent(self::$kernel, Request::create('/'), new Response()), KernelEvents::TERMINATE);

        $published = $this->published;
        $this->published = [];
        $topic = $this->service(ProjectTopicBuilder::class)->forDocument(
            $this->project->requireId(),
            $document->id ?? throw new \LogicException('The document has no id.'),
        );

        $messages = [];
        foreach ($published as $update) {
            $data = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);
            if (\is_array($data) && 'review.draft_state_changed' === ($data['type'] ?? null) && [$topic] === $update->getTopics()) {
                self::assertTrue($update->isPrivate());
                $messages[] = $data;
            }
        }

        return $messages;
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
