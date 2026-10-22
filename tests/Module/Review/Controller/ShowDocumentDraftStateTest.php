<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Contract\PauseKind;
use App\Tests\Module\AgentReview\DocumentReviewScenario;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ShowDocumentDraftStateTest extends WebTestCase
{
    use DocumentReviewScenario;
    use WorkflowProjects;

    private const string STREAM = 'text/vnd.turbo-stream.html';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Project $project;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = $this->em();
        $this->project = $this->workflowProject('draft-state-route');
        $this->bindLifecycle($this->project);
    }

    public function test_the_owner_gets_a_stream_that_replaces_the_band_and_the_warning(): void
    {
        $document = $this->designDraft();

        $this->fetch($document, $this->project->owner);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertStringStartsWith(self::STREAM, (string) $this->client->getResponse()->headers->get('Content-Type'));
        $streams = $this->streams();
        self::assertCount(2, $streams);
        self::assertSame(['replace', 'draft-band'], [$streams[0]['action'], $streams[0]['target']]);
        self::assertSame(['replace', 'draft-review-warning'], [$streams[1]['action'], $streams[1]['target']]);
        self::assertSame('true', $streams[1]['active']);
        self::assertStringContainsString('An agent reviews this draft, round 1 of 6.', $streams[0]['content']->filter('#draft-band')->text());
        self::assertStringContainsString('An agent still reviews this draft, round 1 of 6.', $streams[1]['content']->filter('#draft-review-warning')->text());
    }

    public function test_the_warning_holds_no_field_that_an_open_dialog_could_lose(): void
    {
        $this->fetch($this->designDraft(), $this->project->owner);

        $warning = $this->streams()[1]['content'];
        self::assertCount(0, $warning->filter('input, textarea, select, button, form'));
    }

    public function test_a_paused_card_gives_the_paused_form(): void
    {
        $card = $this->card();
        $handler = self::getContainer()->get(PauseCardHandler::class);
        self::assertInstanceOf(PauseCardHandler::class, $handler);
        $handler(new PauseCardCommand($card, 'repair-failed', 'tech-design-review', PauseKind::Retries));

        $this->fetch($this->designDraft($card), $this->project->owner);

        $streams = $this->streams();
        self::assertStringContainsString('lp-draft-band--paused', (string) $streams[0]['content']->filter('#draft-band')->attr('class'));
        self::assertStringContainsString('paused', $streams[1]['content']->filter('#draft-review-warning')->text());
    }

    public function test_a_draft_with_no_loop_gives_the_plain_form_and_no_second_confirmation(): void
    {
        $this->fetch($this->linkedDocument($this->card()), $this->project->owner);

        $streams = $this->streams();
        self::assertSame('false', $streams[1]['active']);
        self::assertSame('plain', $streams[0]['content']->filter('#draft-band')->attr('data-draft-band'));
    }

    public function test_a_page_with_no_publish_gets_a_band_with_no_publish(): void
    {
        $document = $this->designDraft();

        $this->fetch($document, $this->project->owner, '?publish=0');

        $band = $this->streams()[0]['content']->filter('#draft-band');
        self::assertSame('agent', $band->attr('data-draft-band'));
        self::assertCount(0, $band->filter('[data-document-publish]'));
    }

    public function test_a_document_that_is_no_longer_a_draft_answers_no_content(): void
    {
        $this->fetch($this->linkedDocument($this->card(), DocumentStatus::InReview), $this->project->owner);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
    }

    public function test_a_person_who_may_not_open_the_page_gets_a_403(): void
    {
        $document = $this->designDraft();
        $stranger = new User(fullName: 'Sam Stranger', email: 'stranger-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($stranger);
        $this->em->flush();

        $this->fetch($document, $stranger);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_an_unknown_document_gives_a_404(): void
    {
        $this->login($this->project->owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$this->project->id.'/documents/0199aaaa-0000-7000-8000-000000000001/review/draft-state');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function card(): Card
    {
        $card = new Card($this->project, $this->column($this->project, 'tech-design'), 'Ship it', '', 4);
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }

    private function designDraft(?Card $card = null): Document
    {
        $document = $this->linkedDocument($card ?? $this->card());
        $tag = new Tag($this->project, 'tech-design');
        $this->em->persist($tag);
        $document->tags->add($tag);
        $this->em->flush();

        return $document;
    }

    private function login(User $user): void
    {
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $this->em->flush();
        $this->client->loginUser($user);
    }

    private function fetch(Document $document, User $user, string $query = ''): void
    {
        $this->login($user);
        $this->client->request(Request::METHOD_GET, '/projects/'.$this->project->id.'/documents/'.$document->id.'/review/draft-state'.$query);
    }

    /** @return list<array{action: string, target: string, active: ?string, content: Crawler}> */
    private function streams(): array
    {
        $crawler = new Crawler((string) $this->client->getResponse()->getContent());
        $streams = [];
        foreach ($crawler->filter('turbo-stream') as $node) {
            $stream = new Crawler($node);
            $streams[] = [
                'action' => (string) $stream->attr('action'),
                'target' => (string) $stream->attr('target'),
                'active' => $stream->attr('data-stop-confirm-active'),
                'content' => new Crawler($stream->filter('template')->html()),
            ];
        }

        return $streams;
    }
}
