<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Review;
use App\Module\Review\Repository\ReviewRepository;
use App\Module\Review\Security\DocumentVoter;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class WorkflowVerdictDisplayTest extends WebTestCase
{
    private const string SENTENCE = 'Approved when it entered review: no unanswered decision, no open thread, and the agent review passed.';

    public function test_the_verdict_chip_names_the_workflow_the_rule_and_the_fixed_sentence(): void
    {
        $client = static::createClient();
        [$owner, $projectId, $documentId] = $this->seed('chip');
        $this->approveByRule($documentId, 'tech-design-auto-approve');

        $client->loginUser($owner);
        $page = $client->request(Request::METHOD_GET, "/projects/$projectId/documents/$documentId/review");

        self::assertResponseIsSuccessful();
        self::assertSame('Workflow', trim($page->filter('.lp-verdict-chip__reviewer')->text()));
        self::assertSame('tech-design-auto-approve', trim($page->filter('.lp-verdict-chip__rule .lp-tag')->text()));
        self::assertSame(self::SENTENCE, trim($page->filter('.lp-verdict-chip__note')->text()));
        self::assertCount(1, $page->filter('.lp-verdict-chip__undo button'));
    }

    public function test_the_verdict_chip_of_a_person_shows_no_rule(): void
    {
        $client = static::createClient();
        [$owner, $projectId, $documentId] = $this->seed('person');
        $this->approve($documentId, $owner);

        $client->loginUser($owner);
        $page = $client->request(Request::METHOD_GET, "/projects/$projectId/documents/$documentId/review");

        self::assertSame('Chip Owner', trim($page->filter('.lp-verdict-chip__reviewer')->text()));
        self::assertCount(0, $page->filter('.lp-verdict-chip__rule'));
        self::assertCount(0, $page->filter('.lp-verdict-chip__note'));
    }

    public function test_the_history_page_names_the_workflow_and_the_rule(): void
    {
        $client = static::createClient();
        [$owner, $projectId, $documentId] = $this->seed('history');
        $this->approveByRule($documentId, 'tech-design-auto-approve');

        $client->loginUser($owner);
        $page = $client->request(Request::METHOD_GET, "/projects/$projectId/documents/$documentId/review/history");

        self::assertResponseIsSuccessful();
        $row = $page->filter('.lp-history__review');
        self::assertCount(1, $row);
        self::assertStringContainsString('Workflow', $row->text());
        self::assertSame('tech-design-auto-approve', trim($row->filter('.lp-tag')->text()));
        self::assertStringContainsString(self::SENTENCE, $row->text());
    }

    public function test_only_the_owner_can_open_a_workflow_approval(): void
    {
        $client = static::createClient();
        [, $projectId, $documentId] = $this->seed('stranger-open');
        $this->approveByRule($documentId, 'tech-design-auto-approve');
        $stranger = $this->stranger('stranger-open');

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, "/projects/$projectId/documents/$documentId/review");

        self::assertResponseStatusCodeSame(403);
    }

    public function test_only_the_owner_can_undo_a_workflow_approval(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$owner, $projectId, $documentId] = $this->seed('undo');
        $approval = $this->approveByRule($documentId, 'tech-design-auto-approve');
        $stranger = $this->stranger('undo');

        $client->loginUser($stranger);
        $client->request(Request::METHOD_POST, "/projects/$projectId/documents/$documentId/review/undo", ['undo_verdict_form' => ['reviewId' => (string) $approval->id, '_token' => 'csrf-token']]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->reviewCount($documentId));

        $client->loginUser($owner);
        $page = $client->request(Request::METHOD_GET, "/projects/$projectId/documents/$documentId/review");
        $client->submit($page->filter('.lp-verdict-chip__undo button')->form());

        self::assertResponseRedirects("/projects/$projectId/documents/$documentId/review");
        $em = $this->em();
        $em->clear();
        $document = $em->find(Document::class, $documentId) ?? throw new \LogicException('The document exists.');
        self::assertSame(DocumentStatus::InReview, $document->status);
        self::assertSame(2, $this->reviewCount($documentId));
        $withdrawal = $this->service(ReviewRepository::class)->findOneBy(['sequence' => 2]);
        self::assertSame('Chip Owner', $withdrawal?->reviewer->fullName);
        self::assertNull($withdrawal->workflowRule);
    }

    public function test_the_workflow_user_holds_no_right_on_a_document(): void
    {
        static::createClient();
        [, , $documentId] = $this->seed('voter');
        $workflow = $this->service(UserRepository::class)->workflow();
        $document = $this->em()->find(Document::class, $documentId) ?? throw new \LogicException('The document exists.');
        $token = new UsernamePasswordToken($workflow, 'main', $workflow->getRoles());
        $voter = new DocumentVoter();

        foreach ([DocumentVoter::VIEW, DocumentVoter::CONTRIBUTE, DocumentVoter::MANAGE] as $attribute) {
            self::assertSame(-1, $voter->vote($token, $document, [$attribute]), $attribute);
        }
    }

    private function approveByRule(string $documentId, string $rule): Review
    {
        $document = $this->em()->find(Document::class, $documentId) ?? throw new \LogicException('The document exists.');

        return $this->service(SubmitReviewHandler::class)(new SubmitReviewCommand($this->service(UserRepository::class)->workflow(), $document, 'approved', 1, workflowRule: $rule));
    }

    private function approve(string $documentId, User $reviewer): Review
    {
        $document = $this->em()->find(Document::class, $documentId) ?? throw new \LogicException('The document exists.');
        $reviewer = $this->em()->find(User::class, $reviewer->id) ?? throw new \LogicException('The user exists.');

        return $this->service(SubmitReviewHandler::class)(new SubmitReviewCommand($reviewer, $document, 'approved', 1));
    }

    private function reviewCount(string $documentId): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM reviews r JOIN document_versions v ON v.id = r.version_id WHERE v.document_id = ?', [$documentId]);
    }

    private function stranger(string $suffix): User
    {
        $stranger = new User(fullName: 'Stranger', email: 'stranger-'.$suffix.'@example.com', password: 'hashed-password-placeholder');
        $stranger->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($stranger, static::getContainer());
        $this->em()->persist($stranger);
        $this->em()->flush();

        return $stranger;
    }

    /** @return array{User, string, string} the owner, the project id and the id of an in-review document */
    private function seed(string $suffix): array
    {
        $em = $this->em();
        $owner = new User(fullName: 'Chip Owner', email: 'chip-owner-'.$suffix.'@example.com', password: 'hashed-password-placeholder');
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $document = new Document(owner: $owner, project: $project, title: 'Workflow verdict doc');
        $document->addVersion('# Hello', '<h1>Hello</h1>');
        $document->status = DocumentStatus::InReview;
        $em->persist($document);
        $em->flush();

        return [$owner, (string) $project->id, (string) $document->id];
    }

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
    private function service(string $class): object
    {
        $service = static::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
