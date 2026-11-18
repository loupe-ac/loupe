<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Form\MoveCardFormType;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\AcceptedTerms;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\UX\Turbo\TurboBundle;

final class MoveManagedCardFromCardPageTest extends WebTestCase
{
    use WorkflowProjects;

    public function test_a_managed_card_refuses_the_move_with_the_pause_message_under_the_select(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $project = $this->workflowProject('managed-card-page');
        $this->bindLifecycle($project);
        $owner = $project->owner;
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());

        $create = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);
        $card = $create(new CreateCardCommand(
            project: $project,
            title: 'Managed card',
            body: 'Body',
            type: 'feature',
            column: $this->column($project, 'next'),
            reporter: Actor::Human,
        ));
        $cardId = $card->id;
        $target = $this->column($project, 'in-progress');
        $nextId = (string) $this->column($project, 'next')->id;
        $this->em()->flush();

        $url = '/projects/'.$project->id.'/board/cards/'.$cardId.'/move';
        $name = MoveCardFormType::nameFor($card);
        $this->em()->clear();

        $client->loginUser($owner);
        $client->request(
            Request::METHOD_POST,
            $url,
            [$name => ['column' => (string) $target->id, 'position' => '', '_token' => 'csrf-token', 'origin' => 'card']],
            [],
            ['HTTP_REFERER' => 'http://localhost'.$url, 'HTTP_ACCEPT' => TurboBundle::STREAM_MEDIA_TYPE],
        );

        self::assertResponseStatusCodeSame(422);
        $response = $client->getResponse();
        self::assertStringStartsWith(TurboBundle::STREAM_MEDIA_TYPE, (string) $response->headers->get('Content-Type'));
        self::assertNull($response->headers->get('X-Card-Managed-Offer'));
        $body = (string) $response->getContent();
        self::assertStringContainsString('target="card-move-'.$cardId.'"', $body);
        self::assertStringContainsString('This card is managed. Select', $body);
        self::assertMatchesRegularExpression('/<option value="'.$nextId.'"[^>]*selected/', $body);

        $this->em()->clear();
        $stored = $this->em()->find(Card::class, $cardId);
        self::assertInstanceOf(Card::class, $stored);
        self::assertSame('next', $stored->column->slug);
    }
}
