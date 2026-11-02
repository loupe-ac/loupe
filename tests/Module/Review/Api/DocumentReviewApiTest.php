<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Api;

use App\Module\Board\Mcp\AgentRunCause;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\ValueObject\Anchor;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** The review routes of the public document API: the review, comments, decisions and verdicts. */
final class DocumentReviewApiTest extends WebTestCase
{
    use DocumentApiFixtures;

    private const string MARKDOWN = "# Plan\n\n<!-- decision: target -->\n\n- ( ) Staging\n- ( ) Production\n\n<!-- /decision -->";

    private function reviewable(): Document
    {
        $handler = static::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $handler);

        return $handler(new CreateDocumentCommand($this->reference(\App\Module\Project\Entity\Project::class, $this->project->id), 'Plan', self::MARKDOWN));
    }

    private function comment(Document $document, ?Comment $parent = null): Comment
    {
        $em = $this->em();
        $versions = static::getContainer()->get(DocumentVersionRepository::class);
        self::assertInstanceOf(DocumentVersionRepository::class, $versions);
        $version = $versions->findLatest($this->reference(Document::class, $document->id));
        $comment = new Comment($version, $this->reference(\App\Module\Account\Entity\User::class, $this->owner->id), 'Please check.', new Anchor('Plan', '', '', 0), $parent);
        $em->persist($comment);
        $em->flush();

        return $comment;
    }

    private function url(Document $document, string $tail = ''): string
    {
        return $this->base().'/'.$document->id.$tail;
    }

    /** @param array<string, mixed> $body */
    private function sendJson(string $method, string $url, array $body = []): void
    {
        $this->send($method, $url, [] === $body ? '' : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function verdictId(Document $document): ?string
    {
        $this->send(Request::METHOD_GET, $this->url($document, '/review'));
        $id = $this->json()['verdictId'];
        self::assertTrue(null === $id || \is_string($id));

        return $id;
    }

    public function test_the_review_lists_comments_decisions_and_the_verdict_with_camel_case_keys(): void
    {
        $document = $this->reviewable();
        $root = $this->comment($document);
        $this->comment($document, $root);

        $this->send(Request::METHOD_GET, $this->url($document, '/review'));

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame(['status', 'verdict', 'verdictId', 'verdictThroughAgent', 'note', 'version', 'comments', 'decisions', 'agentReviews', 'verdictThroughApiKey'], array_keys($body));
        self::assertSame(1, $body['version']);
        self::assertNull($body['verdict']);
        self::assertFalse($body['verdictThroughApiKey']);
        self::assertCount(1, $body['comments']);
        self::assertSame((string) $root->id, $body['comments'][0]['id']);
        self::assertSame('pending', $body['comments'][0]['status']);
        self::assertCount(1, $body['comments'][0]['thread']);
        self::assertSame('target', $body['decisions'][0]['id']);
        self::assertSame(['Staging', 'Production'], $body['decisions'][0]['options']);
        self::assertNull($body['decisions'][0]['selectedIndex']);
        self::assertSame([], $body['decisions'][0]['selections']);
        self::assertFalse($body['decisions'][0]['throughApiKey']);
    }

    public function test_the_review_of_a_document_in_another_project_is_not_found(): void
    {
        $other = $this->makeProject($this->owner, 'Second project');
        $elsewhere = $this->document('Elsewhere', $other);

        $this->send(Request::METHOD_GET, $this->url($elsewhere, '/review'));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('document_not_found', $this->json()['code']);
    }

    public function test_a_thread_is_marked_addressed_and_a_repeat_changes_nothing(): void
    {
        $document = $this->reviewable();
        $root = $this->comment($document);
        $url = $this->url($document, '/comments/'.$root->id);

        $this->sendJson(Request::METHOD_PATCH, $url, ['status' => 'addressed']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['id' => (string) $root->id, 'status' => 'addressed'], $this->json());

        $this->sendJson(Request::METHOD_PATCH, $url, ['status' => 'addressed']);
        self::assertResponseStatusCodeSame(200);

        $this->send(Request::METHOD_GET, $this->url($document, '/review'));
        self::assertSame('addressed', $this->json()['comments'][0]['status']);
    }

    public function test_marking_a_thread_addressed_names_the_api_key_in_the_audit_record(): void
    {
        $audit = RecordingAuditor::installedIn(static::getContainer());
        $document = $this->reviewable();
        $root = $this->comment($document);

        $this->sendJson(Request::METHOD_PATCH, $this->url($document, '/comments/'.$root->id), ['status' => 'addressed']);

        self::assertResponseStatusCodeSame(200);
        $record = $audit->record('review.comment_addressed');
        self::assertArrayHasKey('apiKeyId', $record->context);
        self::assertSame('Test key', $record->context['apiKeyName']);
    }

    public function test_marking_addressed_refuses_a_reply_a_foreign_comment_and_another_status(): void
    {
        $document = $this->reviewable();
        $root = $this->comment($document);
        $reply = $this->comment($document, $root);
        $elsewhere = $this->reviewable();
        $foreign = $this->comment($elsewhere);

        $this->sendJson(Request::METHOD_PATCH, $this->url($document, '/comments/'.$reply->id), ['status' => 'addressed']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('comment_is_reply', $this->json()['errors'][0]['code']);

        $this->sendJson(Request::METHOD_PATCH, $this->url($document, '/comments/'.$foreign->id), ['status' => 'addressed']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('comment_not_found', $this->json()['code']);

        $this->sendJson(Request::METHOD_PATCH, $this->url($document, '/comments/not-a-uuid'), ['status' => 'addressed']);
        self::assertResponseStatusCodeSame(404);

        $this->sendJson(Request::METHOD_PATCH, $this->url($document, '/comments/'.$root->id), ['status' => 'resolved']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('status', $this->json()['errors'][0]['field']);
    }

    public function test_a_reply_answers_201_and_records_the_key(): void
    {
        $document = $this->reviewable();
        $root = $this->comment($document);

        $this->sendJson(Request::METHOD_POST, $this->url($document, '/comments/'.$root->id.'/replies'), ['body' => 'Done.']);

        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'];
        self::assertStringEndsWith('/documents/'.$document->id.'/review', (string) $this->client->getResponse()->headers->get('Location'));
        $reply = $this->em()->find(Comment::class, Uuid::fromString($id));
        self::assertNotNull($reply);
        self::assertSame('Done.', $reply->body);
        self::assertSame('Test key', $reply->apiKeyName);
        self::assertSame((string) $root->id, (string) $reply->parent?->id);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedReplies(): iterable
    {
        yield 'blank' => ['   ', 'reply_empty'];
        yield 'too long' => [str_repeat('a', 65_537), 'reply_too_long'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedReplies')]
    public function test_a_refused_reply_names_its_code(string $body, string $code): void
    {
        $document = $this->reviewable();
        $root = $this->comment($document);

        $this->sendJson(Request::METHOD_POST, $this->url($document, '/comments/'.$root->id.'/replies'), ['body' => $body]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($code, $this->json()['errors'][0]['code']);
    }

    public function test_a_reply_to_a_reply_is_refused(): void
    {
        $document = $this->reviewable();
        $reply = $this->comment($document, $this->comment($document));

        $this->sendJson(Request::METHOD_POST, $this->url($document, '/comments/'.$reply->id.'/replies'), ['body' => 'Again.']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('reply_to_reply', $this->json()['errors'][0]['code']);
    }

    public function test_a_reply_to_a_comment_of_an_older_version_answers_409(): void
    {
        $document = $this->reviewable();
        $root = $this->comment($document);
        $revise = static::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand(document: $this->reference(Document::class, $document->id), markdown: self::MARKDOWN."\n\nMore.", description: 'Added a line.'));

        $this->sendJson(Request::METHOD_POST, $this->url($document, '/comments/'.$root->id.'/replies'), ['body' => 'Late.']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('comment_version_stale', $this->json()['code']);
    }

    public function test_a_decision_is_answered_changed_and_cleared(): void
    {
        $document = $this->reviewable();
        $url = $this->url($document, '/decisions/target');

        $this->sendJson(Request::METHOD_PUT, $url, ['versionNumber' => 1, 'optionIndexes' => [1], 'note' => 'Go live.']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['decisionId' => 'target', 'changed' => true, 'cleared' => false], $this->json());

        $this->sendJson(Request::METHOD_PUT, $url, ['versionNumber' => 1, 'optionIndexes' => [1], 'note' => 'Go live.']);
        self::assertSame(false, $this->json()['changed']);

        $this->send(Request::METHOD_GET, $this->url($document, '/review'));
        $decision = $this->json()['decisions'][0];
        self::assertSame('Production', $decision['selected']);
        self::assertSame(1, $decision['selectedIndex']);
        self::assertSame('Go live.', $decision['note']);
        self::assertTrue($decision['throughApiKey']);
        self::assertFalse($decision['throughAgent']);

        $this->send(Request::METHOD_DELETE, $url.'?versionNumber=1');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['decisionId' => 'target', 'changed' => true, 'cleared' => true], $this->json());

        $this->send(Request::METHOD_GET, $this->url($document, '/review'));
        self::assertNull($this->json()['decisions'][0]['selected']);
        self::assertSame('Go live.', $this->json()['decisions'][0]['note']);
    }

    public function test_a_decision_refuses_an_unknown_id_option_and_version(): void
    {
        $document = $this->reviewable();

        $this->sendJson(Request::METHOD_PUT, $this->url($document, '/decisions/missing'), ['versionNumber' => 1, 'optionIndexes' => [0]]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('decision_not_found', $this->json()['code']);

        $this->sendJson(Request::METHOD_PUT, $this->url($document, '/decisions/target'), ['versionNumber' => 1, 'optionIndexes' => [7]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('unknown_option', $this->json()['errors'][0]['code']);

        $this->sendJson(Request::METHOD_PUT, $this->url($document, '/decisions/target'), ['versionNumber' => 9, 'optionIndexes' => [0]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('unknown_version', $this->json()['errors'][0]['code']);

        $this->sendJson(Request::METHOD_PUT, $this->url($document, '/decisions/target'), ['versionNumber' => 1, 'optionIndexes' => []]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('optionIndexes', $this->json()['errors'][0]['field']);

        $this->sendJson(Request::METHOD_PUT, $this->url($document, '/decisions/target'), ['versionNumber' => 1]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('optionIndexes', $this->json()['errors'][0]['field']);

        $this->send(Request::METHOD_DELETE, $this->url($document, '/decisions/target'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('version_number_invalid', $this->json()['errors'][0]['code']);
    }

    public function test_an_answer_for_an_older_version_answers_409(): void
    {
        $document = $this->reviewable();
        $revise = static::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand(document: $this->reference(Document::class, $document->id), markdown: self::MARKDOWN."\n\nMore.", description: 'Added a line.'));

        $this->sendJson(Request::METHOD_PUT, $this->url($document, '/decisions/target'), ['versionNumber' => 1, 'optionIndexes' => [0]]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('decision_stale', $this->json()['code']);
    }

    public function test_a_verdict_needs_every_decision_answered_and_names_the_key(): void
    {
        $document = $this->reviewable();
        $url = $this->url($document, '/verdicts');

        $this->sendJson(Request::METHOD_POST, $url, ['versionNumber' => 1, 'verdict' => 'approved', 'expectedVerdictId' => null]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('decisions_unanswered', $this->json()['code']);

        $this->sendJson(Request::METHOD_PUT, $this->url($document, '/decisions/target'), ['versionNumber' => 1, 'optionIndexes' => [0]]);
        $this->sendJson(Request::METHOD_POST, $url, ['versionNumber' => 1, 'verdict' => 'approved', 'expectedVerdictId' => null]);

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertSame('approved', $body['verdict']);
        self::assertSame('approved', $body['documentStatus']);
        self::assertSame($body['verdictId'], $this->verdictId($document));
        self::assertTrue($this->json()['verdictThroughApiKey']);
        self::assertFalse($this->json()['verdictThroughAgent']);
        self::assertSame('approved', $this->json()['verdict']);
    }

    public function test_a_verdict_refuses_a_stale_version_a_changed_review_and_a_missing_note(): void
    {
        $document = $this->reviewable();
        $url = $this->url($document, '/verdicts');

        $this->sendJson(Request::METHOD_POST, $url, ['versionNumber' => 4, 'verdict' => 'changes-requested', 'expectedVerdictId' => null, 'note' => 'No.']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('verdict_stale', $this->json()['code']);

        $this->sendJson(Request::METHOD_POST, $url, ['versionNumber' => 1, 'verdict' => 'changes-requested', 'expectedVerdictId' => '0198a000-0000-7000-8000-000000000000', 'note' => 'No.']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('verdict_changed', $this->json()['code']);

        $this->sendJson(Request::METHOD_POST, $url, ['versionNumber' => 1, 'verdict' => 'changes-requested', 'expectedVerdictId' => null]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('note_or_comment_required', $this->json()['errors'][0]['code']);

        $this->sendJson(Request::METHOD_POST, $url, ['versionNumber' => 1, 'verdict' => 'maybe', 'expectedVerdictId' => null]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('verdict', $this->json()['errors'][0]['field']);
    }

    public function test_a_verdict_is_withdrawn_once(): void
    {
        $document = $this->reviewable();
        $this->sendJson(Request::METHOD_POST, $this->url($document, '/verdicts'), ['versionNumber' => 1, 'verdict' => 'changes-requested', 'expectedVerdictId' => null, 'note' => 'Rework it.']);
        self::assertResponseStatusCodeSame(201);
        $verdictId = $this->json()['verdictId'];

        $this->send(Request::METHOD_DELETE, $this->url($document, '/verdicts/0198a000-0000-7000-8000-000000000000?versionNumber=1'));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('verdict_changed', $this->json()['code']);

        $this->send(Request::METHOD_DELETE, $this->url($document, '/verdicts/'.$verdictId.'?versionNumber=3'));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('verdict_stale', $this->json()['code']);

        $this->send(Request::METHOD_DELETE, $this->url($document, '/verdicts/'.$verdictId.'?versionNumber=1'));
        self::assertResponseStatusCodeSame(200);
        self::assertSame('in-review', $this->json()['documentStatus']);
        $withdrawal = $this->json()['verdictId'];

        $this->send(Request::METHOD_GET, $this->url($document, '/review'));
        self::assertNull($this->json()['verdict']);
        self::assertSame($withdrawal, $this->json()['verdictId']);

        $this->send(Request::METHOD_DELETE, $this->url($document, '/verdicts/'.$withdrawal.'?versionNumber=1'));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('verdict_already_withdrawn', $this->json()['code']);
    }

    public function test_a_bridge_worker_may_not_write_a_verdict_or_a_decision(): void
    {
        $document = $this->reviewable();
        $session = Uuid::v4();
        $subject = Uuid::v7();
        $em = $this->em();
        $project = $this->reference(\App\Module\Project\Entity\Project::class, $this->project->id);
        $request = new WorkRequest($project, WorkSubject::CARD, $subject, 1, 'tech-design-revise', null, 'rule', new \DateTimeImmutable());
        $request->state = WorkRequestState::Claimed;
        $em->persist($request);
        $em->flush();
        $em->persist(new WorkerRun(project: $project, bridgeId: Uuid::v4(), subjectType: WorkSubject::CARD, subjectId: $subject, cardNumber: 1, workKind: 'tech-design-revise', state: WorkerRunState::Running, sessionId: $session, workRequestId: $request->id));
        $em->flush();
        $header = ['HTTP_'.strtoupper(str_replace('-', '_', AgentRunCause::SESSION_HEADER)) => (string) $session];

        $calls = [
            [Request::METHOD_POST, $this->url($document, '/verdicts'), '{"versionNumber":1,"verdict":"approved","expectedVerdictId":null}'],
            [Request::METHOD_PUT, $this->url($document, '/decisions/target'), '{"versionNumber":1,"optionIndexes":[0]}'],
            [Request::METHOD_DELETE, $this->url($document, '/decisions/target?versionNumber=1'), ''],
            [Request::METHOD_DELETE, $this->url($document, '/verdicts/'.Uuid::v7().'?versionNumber=1'), ''],
        ];
        foreach ($calls as [$method, $url, $body]) {
            $this->send($method, $url, $body, $header);
            self::assertResponseStatusCodeSame(403, $method.' '.$url);
            self::assertSame('credential_not_allowed', $this->json()['code']);
        }
        $this->send(Request::METHOD_GET, $this->url($document, '/review'), '', $header);
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->json()['decisions'][0]['selected']);
    }
}
