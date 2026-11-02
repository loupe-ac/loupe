<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Api;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\ApiKeyCredential;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** The refusals of the card write routes that need a bound workflow: a managed card, an open epic and the child design choice. */
final class CardApiRefusalTest extends WebTestCase
{
    use WorkflowProjects;

    private KernelBrowser $client;
    private Project $project;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->project = $this->workflowProject('card-api-refusal');
        $this->project->owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($this->project->owner, static::getContainer());
        $this->em()->flush();
        $this->bindLifecycle($this->project);
        $this->token = ApiKeyCredential::mint(static::getContainer(), $this->project->owner);
    }

    public function test_a_move_of_a_managed_card_is_refused_until_the_card_is_held(): void
    {
        $card = $this->card('Managed', 'feature', 'next');

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$card->number, '{"status":"in-progress"}');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('card_managed', $this->json()['code']);

        $this->send(Request::METHOD_PUT, $this->base().'/'.$card->number.'/hold');
        self::assertResponseStatusCodeSame(200);

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$card->number, '{"status":"in-progress"}');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('in-progress', $this->json()['status']);
    }

    public function test_an_epic_with_open_children_is_not_closed_and_the_problem_names_them(): void
    {
        $epic = $this->card('Epic', 'epic', 'in-progress');
        $child = $this->card('Child', 'feature', 'backlog', $epic);
        $this->send(Request::METHOD_PUT, $this->base().'/'.$epic->number.'/hold');
        self::assertResponseStatusCodeSame(200);

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$epic->number, '{"status":"done"}');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('epic_children_open', $this->json()['code']);
        self::assertSame([$child->number], $this->json()['cards']);
    }

    public function test_the_child_design_choice_answers_a_field_code_for_each_request_fault(): void
    {
        $epic = $this->card('Epic', 'epic', 'next');
        $this->approvedDesignOf($epic);
        $base = ['title' => 'Child', 'body' => '', 'type' => 'feature'];

        foreach ([
            'child_design_required' => $base + ['parentCardId' => (string) $epic->id],
            'child_design_no_parent' => $base + ['childDesign' => 'own'],
            'child_design_with_status' => $base + ['parentCardId' => (string) $epic->id, 'childDesign' => 'own', 'status' => 'next'],
        ] as $code => $payload) {
            $this->send(Request::METHOD_POST, $this->base(), json_encode($payload, \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, $code);
            self::assertSame('validation_failed', $this->json()['code'], $code);
            self::assertSame(['field' => 'childDesign', 'code' => $code], array_intersect_key($this->json()['errors'][0], ['field' => 1, 'code' => 1]), $code);
            self::assertStringNotContainsString('childDesign:', $this->json()['errors'][0]['message']);
        }
    }

    public function test_inheriting_from_a_parent_with_no_design_is_a_field_code_on_a_change(): void
    {
        $epic = $this->card('Epic', 'epic', 'next');
        $loose = $this->card('Loose', 'feature', 'next');

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$loose->number, json_encode(['parentCardId' => (string) $epic->id, 'childDesign' => 'inherit'], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('child_design_no_inherit', $this->json()['errors'][0]['code']);
        self::assertSame('childDesign', $this->json()['errors'][0]['field']);
    }

    private function base(): string
    {
        return '/api/v1/projects/'.$this->project->id.'/cards';
    }

    private function card(string $title, string $type, string $slug, ?Card $parent = null): Card
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        return $handler(new CreateCardCommand(
            project: $this->project,
            title: $title,
            body: 'Body',
            type: $type,
            column: $this->column($this->project, $slug),
            reporter: Actor::Human,
            parentCardId: null === $parent ? null : (string) $parent->id,
        ));
    }

    private function approvedDesignOf(Card $card): void
    {
        $em = $this->em();
        $document = new Document($this->project->owner, $this->project, 'Design');
        $document->status = DocumentStatus::Approved;
        $tag = new Tag($this->project, 'tech-design');
        $em->persist($tag);
        $document->tags->add($tag);
        $em->persist($document);
        $em->persist(new CardDocument($card, $document));
        $em->flush();
    }

    private function send(string $method, string $url, string $body = ''): void
    {
        $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: $body);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
