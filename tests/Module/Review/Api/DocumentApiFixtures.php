<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Api;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\SetDocumentSeriesCommand;
use App\Module\Review\Command\SetDocumentSeriesHandler;
use App\Module\Review\Command\SetDocumentTagsCommand;
use App\Module\Review\Command\SetDocumentTagsHandler;
use App\Module\Review\Entity\Document;
use App\Tests\Support\ApiKeyCredential;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

/** The rows and the calls that the document API tests share. */
trait DocumentApiFixtures
{
    private KernelBrowser $client;
    private OAuthScenario $scenario;
    private User $owner;
    private Project $project;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->scenario = new OAuthScenario(static::getContainer());

        $this->owner = $this->scenario->createUser('documents-api-'.uniqid().'@example.com');
        $this->project = $this->makeProject($this->owner, 'Documents shop');
        $this->token = ApiKeyCredential::mint(static::getContainer(), $this->owner);
    }

    /** The manager of the current kernel. A request reboots the kernel, so a test asks again after one. */
    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function reference(string $class, ?Uuid $id): object
    {
        return $this->em()->getReference($class, $id) ?? throw new \LogicException('No row to refer to.');
    }

    private function makeProject(User $owner, string $name): Project
    {
        $em = $this->em();
        $project = new Project($this->reference(User::class, $owner->id), $name);
        $em->persist($project);
        $em->flush();

        return $project;
    }

    private function document(string $title, ?Project $project = null, string $createdAt = 'now', string $markdown = '# Hello', ?string $description = null): Document
    {
        $em = $this->em();
        $project = $this->reference(Project::class, ($project ?? $this->project)->id);
        $document = new Document(owner: $this->reference(User::class, $project->owner->id), project: $project, title: $title, createdAt: new \DateTimeImmutable($createdAt));
        $document->addVersion($markdown, '<h1>Hello</h1>', $description);
        $em->persist($document);
        $em->flush();

        return $document;
    }

    /** @param list<string> $names */
    private function tag(Document $document, array $names): void
    {
        (static::getContainer()->get(SetDocumentTagsHandler::class))(new SetDocumentTagsCommand($document, $names));
    }

    private function place(Document $document, string $series, int $ordinal): void
    {
        (static::getContainer()->get(SetDocumentSeriesHandler::class))(new SetDocumentSeriesCommand($document, $series, $ordinal));
    }

    private function base(?Project $project = null): string
    {
        return '/api/v1/projects/'.($project ?? $this->project)->id.'/documents';
    }

    /** @param array<string, string> $server */
    private function send(string $method, string $url, string $body = '', array $server = [], ?string $token = ''): void
    {
        $bearer = '' === $token ? $this->token : $token;
        $this->client->request($method, $url, server: $server + ['CONTENT_TYPE' => 'application/json'] + (null === $bearer ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer]), content: $body);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return list<Document> */
    private function stored(?Project $project = null): array
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(Document::class)->findBy(['project' => ($project ?? $this->project)->id], ['createdAt' => 'ASC']);
    }
}
