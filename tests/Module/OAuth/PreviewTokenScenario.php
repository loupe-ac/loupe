<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\OAuth\Command\MintPreviewTokenCommand;
use App\Module\OAuth\Command\MintPreviewTokenHandler;
use App\Module\Project\Entity\Project;
use App\Tests\Support\OAuthScenario;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** A signed-in owner with a project, and helpers that read the token tables as the database holds them. */
trait PreviewTokenScenario
{
    private OAuthScenario $scenario;
    private Connection $connection;
    private User $owner;
    private Project $project;

    /** @param non-empty-string $email */
    private function setUpScenario(string $email = 'preview-owner@example.com'): void
    {
        $this->scenario = new OAuthScenario(static::getContainer());
        $this->connection = static::getContainer()->get(Connection::class);
        $this->owner = $this->scenario->createUser($email);
        $this->project = $this->scenario->createProject($this->owner, 'Preview site');
    }

    /** A card of the project, in a column of its own. */
    private function cardOf(Project $project, int $number = 1, bool $finished = false): Uuid
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $column = new BoardColumn($project, 'Doing', 'doing-'.$number, $number, terminal: $finished);
        $card = new Card(project: $project, column: $column, title: 'Preview it', body: 'Body', number: $number);
        $em->persist($column);
        $em->persist($card);
        $em->flush();

        return $card->id ?? throw new \LogicException('A flushed card has an id.');
    }

    /** @return non-empty-string the access token id */
    private function mint(string $label, ?Uuid $cardId = null, ?User $owner = null, ?Project $project = null): string
    {
        $handler = static::getContainer()->get(MintPreviewTokenHandler::class);
        self::assertInstanceOf(MintPreviewTokenHandler::class, $handler);

        $view = $handler(new MintPreviewTokenCommand($owner ?? $this->owner, (string) ($project ?? $this->project)->id, $label, $cardId));

        return self::tokenIdOf($view->token);
    }

    /** @return non-empty-string */
    private static function tokenIdOf(string $jwt): string
    {
        $claims = OAuthScenario::claimsOf($jwt);
        $id = $claims['jti'] ?? null;

        return \is_string($id) && '' !== $id ? $id : throw new \LogicException('the token has no jti claim.');
    }

    private function isRevoked(string $accessTokenId): bool
    {
        return (bool) $this->connection->fetchOne('SELECT revoked FROM oauth2_access_token WHERE identifier = :id', ['id' => $accessTokenId]);
    }
}
