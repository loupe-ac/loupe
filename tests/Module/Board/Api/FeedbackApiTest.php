<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Api;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Entity\SiteReviewScreenshot;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\ApiKeyCredential;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Entity\AuditLog;
use Ubermuda\AuditBundle\Repository\AuditLogRepository;

/** The feedback routes of the public API under /api/v1/projects/{project}/feedback. */
final class FeedbackApiTest extends WebTestCase
{
    use BoardColumnFixtures;

    private KernelBrowser $client;
    private OAuthScenario $scenario;
    private EntityManagerInterface $em;
    private User $owner;
    private Project $project;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->scenario = new OAuthScenario(static::getContainer());
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->owner = $this->scenario->createUser('feedback-api-'.uniqid().'@example.com');
        $this->project = $this->makeProject($this->owner, 'feedback-shop');
        $this->token = ApiKeyCredential::mint(static::getContainer(), $this->owner);
    }

    public function test_the_list_holds_the_pending_notes_oldest_first_with_the_card_of_each(): void
    {
        $card = $this->card($this->project, 'Hero copy');
        $first = $this->note($card, 'Make it bigger', createdAt: '2026-01-01 10:00:00');
        $second = $this->note(null, 'No card here', createdAt: '2026-01-02 10:00:00');
        $this->note($card, 'Done already', SiteReviewCommentStatus::Addressed, createdAt: '2026-01-03 10:00:00');
        $this->em->flush();

        $this->send(Request::METHOD_GET, $this->base());

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame([(string) $first->id, (string) $second->id], array_column($body['items'], 'id'));
        self::assertNull($body['next']);
        self::assertSame(
            ['id', 'url', 'surface', 'native', 'screenshot', 'anchors', 'body', 'hasDrawing', 'status', 'context', 'createdAt', 'cardId', 'number', 'title'],
            array_keys($body['items'][0]),
        );
        self::assertSame((string) $card->id, $body['items'][0]['cardId']);
        self::assertSame('Hero copy', $body['items'][0]['title']);
        self::assertSame(1, $body['items'][0]['number']);
        self::assertSame('pending', $body['items'][0]['status']);
        self::assertSame('web', $body['items'][0]['surface']);
        self::assertSame(['selector' => '.hero', 'text' => 'Hero', 'quote' => null, 'quotePrefix' => null, 'quoteSuffix' => null], $body['items'][0]['anchors'][0]);
        self::assertNull($body['items'][1]['cardId']);
        self::assertNull($body['items'][1]['title']);
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
    }

    public function test_the_status_filter_and_the_all_value(): void
    {
        $card = $this->card($this->project, 'Hero copy');
        $this->note($card, 'Pending one');
        $addressed = $this->note($card, 'Addressed one', SiteReviewCommentStatus::Addressed);
        $this->note($card, 'Resolved one', SiteReviewCommentStatus::Resolved);
        $this->em->flush();

        $this->send(Request::METHOD_GET, $this->base().'?status=addressed');
        self::assertSame([(string) $addressed->id], array_column($this->json()['items'], 'id'));

        $this->send(Request::METHOD_GET, $this->base().'?status=all');
        self::assertCount(3, $this->json()['items']);

        $this->send(Request::METHOD_GET, $this->base().'?status=bogus');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('status_invalid', $this->json()['errors'][0]['code']);
    }

    public function test_the_card_filter_keeps_the_notes_of_one_card(): void
    {
        $one = $this->card($this->project, 'One');
        $two = $this->card($this->project, 'Two');
        $mine = $this->note($one, 'On one');
        $this->note($two, 'On two');
        $this->em->flush();

        $this->send(Request::METHOD_GET, $this->base().'?cardId='.$one->id);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([(string) $mine->id], array_column($this->json()['items'], 'id'));
    }

    public function test_a_card_of_another_project_or_no_card_is_card_not_found_and_a_bad_id_is_refused(): void
    {
        $other = $this->makeProject($this->owner, 'feedback-other');
        $foreign = $this->card($other, 'Foreign');
        $this->em->flush();

        $this->send(Request::METHOD_GET, $this->base().'?cardId='.$foreign->id);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('card_not_found', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->base().'?cardId='.Uuid::v7());
        self::assertResponseStatusCodeSame(404);
        self::assertSame('card_not_found', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->base().'?cardId=nonsense');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('card_id_invalid', $this->json()['errors'][0]['code']);
    }

    public function test_a_page_walks_the_list_with_a_cursor_in_both_directions(): void
    {
        $ids = [];
        foreach ([1, 2, 3] as $day) {
            $ids[] = (string) $this->note(null, 'Note '.$day, createdAt: '2026-01-0'.$day.' 10:00:00')->id;
        }
        $this->em->flush();

        $this->send(Request::METHOD_GET, $this->base().'?limit=2');
        $first = $this->json();
        self::assertSame([$ids[0], $ids[1]], array_column($first['items'], 'id'));
        self::assertIsString($first['next']);
        self::assertStringContainsString('rel="next"', (string) $this->client->getResponse()->headers->get('Link'));

        $this->send(Request::METHOD_GET, $first['next']);
        $second = $this->json();
        self::assertSame([$ids[2]], array_column($second['items'], 'id'));
        self::assertNull($second['next']);

        $this->send(Request::METHOD_GET, $this->base().'?limit=2&sort=-createdAt');
        $newest = $this->json();
        self::assertSame([$ids[2], $ids[1]], array_column($newest['items'], 'id'));
        $this->send(Request::METHOD_GET, $newest['next']);
        self::assertSame([$ids[0]], array_column($this->json()['items'], 'id'));
    }

    public function test_a_cursor_made_for_other_filters_and_a_broken_cursor_are_refused(): void
    {
        foreach ([1, 2] as $day) {
            $this->note(null, 'Note '.$day, createdAt: '2026-01-0'.$day.' 10:00:00');
        }
        $this->em->flush();
        $this->send(Request::METHOD_GET, $this->base().'?limit=1');
        $next = $this->json()['next'];
        self::assertIsString($next);

        $this->send(Request::METHOD_GET, $next.'&status=all');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_cursor', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->base().'?cursor=nonsense');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_cursor', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->base().'?limit=0');
        self::assertResponseStatusCodeSame(422);
    }

    public function test_the_screenshot_is_a_size_and_never_the_image(): void
    {
        $note = $this->note(null, 'With a picture');
        $this->em->persist(new SiteReviewScreenshot($note, $this->project, 'image/png', 'PNGBYTES', 8, 640, 480));
        $this->em->flush();

        $this->send(Request::METHOD_GET, $this->base().'/'.$note->id);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['width' => 640, 'height' => 480], $this->json()['screenshot']);
        self::assertStringNotContainsString('PNGBYTES', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString(base64_encode('PNGBYTES'), (string) $this->client->getResponse()->getContent());
    }

    public function test_a_native_note_carries_its_facts_and_no_page(): void
    {
        $native = ['app' => ['name' => 'Notes', 'bundleId' => 'com.example.notes'], 'device' => ['name' => 'iPhone', 'udid' => 'U-1', 'osVersion' => '19.0'], 'screen' => ['title' => null], 'element' => ['role' => 'button', 'label' => 'Save', 'identifier' => null, 'frame' => null]];
        $note = new SiteReviewComment($this->project, 0, 'Too small', null, surface: \App\Module\SiteReview\Entity\ReviewSurface::IosSimulator, native: $native);
        $this->em->persist($note);
        $this->em->flush();

        $this->send(Request::METHOD_GET, $this->base().'/'.$note->id);

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertNull($body['url']);
        self::assertSame('ios-simulator', $body['surface']);
        self::assertSame($native, $body['native']);
    }

    public function test_one_item_is_read_and_a_missing_or_foreign_or_malformed_id_is_feedback_not_found(): void
    {
        $note = $this->note(null, 'Mine');
        $other = $this->makeProject($this->owner, 'feedback-other');
        $foreign = new SiteReviewComment($other, 0, 'Not mine', 'https://x.example');
        $this->em->persist($foreign);
        $this->em->flush();

        $this->send(Request::METHOD_GET, $this->base().'/'.$note->id);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Mine', $this->json()['body']);

        foreach ([(string) $foreign->id, (string) Uuid::v7(), 'nonsense'] as $id) {
            $this->send(Request::METHOD_GET, $this->base().'/'.$id);
            self::assertResponseStatusCodeSame(404, $id);
            self::assertSame('feedback_not_found', $this->json()['code'], $id);
        }
    }

    public function test_marking_addressed_writes_the_status_and_records_the_api_channel(): void
    {
        $note = $this->note($this->card($this->project, 'Hero'), 'Fix me');
        $this->em->flush();
        $this->client->disableReboot();

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$note->id, '{"status":"addressed"}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('addressed', $this->json()['status']);
        self::assertSame('Hero', $this->json()['title']);
        self::assertSame(SiteReviewCommentStatus::Addressed, $this->stored($note)->status);
        $record = static::getContainer()->get(AuditLogRepository::class)->findOneBy(['operation' => 'site_review.comment_addressed']);
        self::assertInstanceOf(AuditLog::class, $record);
        self::assertSame('api', $record->channel);
        self::assertSame($this->owner->auditIdentifier(), $record->actor?->auditIdentifier());
    }

    public function test_marking_an_addressed_item_again_answers_200_and_changes_nothing(): void
    {
        $note = $this->note(null, 'Done', SiteReviewCommentStatus::Addressed);
        $this->em->flush();

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$note->id, '{"status":"addressed"}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('addressed', $this->json()['status']);
        self::assertSame(SiteReviewCommentStatus::Addressed, $this->stored($note)->status);
    }

    /** The note is pending in the identity map and addressed in the row, as when another request writes between the read and the update. */
    public function test_a_concurrent_address_answers_the_stored_status(): void
    {
        $note = $this->note(null, 'Two agents');
        $this->em->flush();
        $this->setStatusBehindTheRequest($note, SiteReviewCommentStatus::Addressed);
        $this->client->disableReboot();

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$note->id, '{"status":"addressed"}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('addressed', $this->json()['status']);
        self::assertSame(SiteReviewCommentStatus::Addressed, $this->stored($note)->status);
    }

    public function test_a_concurrent_resolve_is_kept_and_answers_feedback_already_resolved(): void
    {
        $note = $this->note(null, 'A person got there first');
        $this->em->flush();
        $this->setStatusBehindTheRequest($note, SiteReviewCommentStatus::Resolved);
        $this->client->disableReboot();

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$note->id, '{"status":"addressed"}');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('feedback_already_resolved', $this->json()['code']);
        self::assertSame(SiteReviewCommentStatus::Resolved, $this->stored($note)->status);
    }

    public function test_a_resolved_item_is_never_replaced_and_answers_feedback_already_resolved(): void
    {
        $note = $this->note(null, 'A person closed this', SiteReviewCommentStatus::Resolved);
        $this->em->flush();

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$note->id, '{"status":"addressed"}');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('feedback_already_resolved', $this->json()['code']);
        self::assertSame(SiteReviewCommentStatus::Resolved, $this->stored($note)->status);
    }

    public function test_a_status_other_than_addressed_and_a_null_or_missing_status_are_refused(): void
    {
        $note = $this->note(null, 'Mine');
        $this->em->flush();

        foreach (['{"status":"resolved"}', '{"status":"pending"}', '{"status":null}', '{}', '{"status":5}'] as $body) {
            $this->send(Request::METHOD_PATCH, $this->base().'/'.$note->id, $body);
            self::assertResponseStatusCodeSame(422, $body);
            self::assertSame('validation_failed', $this->json()['code'], $body);
        }
        self::assertSame(SiteReviewCommentStatus::Pending, $this->stored($note)->status);

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$note->id, '{not json');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_json', $this->json()['code']);
    }

    public function test_marking_an_unknown_item_is_feedback_not_found(): void
    {
        $this->send(Request::METHOD_PATCH, $this->base().'/'.Uuid::v7(), '{"status":"addressed"}');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('feedback_not_found', $this->json()['code']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function routes(): iterable
    {
        yield 'list' => [Request::METHOD_GET, '', ''];
        yield 'show' => [Request::METHOD_GET, '/{id}', ''];
        yield 'mark addressed' => [Request::METHOD_PATCH, '/{id}', '{"status":"addressed"}'];
    }

    #[DataProvider('routes')]
    public function test_a_project_of_another_owner_reads_as_a_missing_project_and_stays_untouched(string $method, string $suffix, string $body): void
    {
        $other = $this->scenario->createUser('feedback-other-'.uniqid().'@example.com');
        $otherProject = $this->makeProject($other, 'their-feedback');
        $note = new SiteReviewComment($otherProject, 0, 'Theirs', 'https://x.example');
        $this->em->persist($note);
        $this->em->flush();

        $this->send($method, '/api/v1/projects/'.$otherProject->id.'/feedback'.str_replace('{id}', (string) $note->id, $suffix), $body);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);
        self::assertSame(SiteReviewCommentStatus::Pending, $this->stored($note)->status);
    }

    #[DataProvider('routes')]
    public function test_a_request_with_no_credential_is_refused(string $method, string $suffix, string $body): void
    {
        $this->send($method, $this->base().str_replace('{id}', (string) Uuid::v7(), $suffix), $body, token: null);

        self::assertResponseStatusCodeSame(401);
        self::assertSame('invalid_credentials', $this->json()['code']);
    }

    #[DataProvider('routes')]
    public function test_a_credential_without_the_public_scope_is_refused(string $method, string $suffix, string $body): void
    {
        $this->send($method, $this->base().str_replace('{id}', (string) Uuid::v7(), $suffix), $body, token: AgentCredential::tokenFor(static::getContainer(), $this->owner, 'agent mcp'));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('credential_not_allowed', $this->json()['code']);
    }

    #[DataProvider('routes')]
    public function test_a_credential_bound_to_another_project_reaches_nothing_here(string $method, string $suffix, string $body): void
    {
        $bound = $this->makeProject($this->owner, 'bound-feedback');
        $note = $this->note(null, 'Mine');
        $this->em->flush();

        $this->send($method, $this->base().str_replace('{id}', (string) $note->id, $suffix), $body, token: AgentCredential::tokenFor(static::getContainer(), $this->owner, 'mcp public', $bound));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);
        self::assertSame(SiteReviewCommentStatus::Pending, $this->stored($note)->status);
    }

    public function test_a_project_is_found_by_its_slug_as_well(): void
    {
        $this->note(null, 'Mine');
        $this->em->flush();

        $this->send(Request::METHOD_GET, '/api/v1/projects/'.$this->project->slug.'/feedback');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->json()['items']);
    }

    private function base(): string
    {
        return '/api/v1/projects/'.$this->project->id.'/feedback';
    }

    private function makeProject(User $owner, string $name): Project
    {
        $project = new Project($owner, $name);
        $this->em->persist($project);
        $this->seedColumns($project);
        $this->em->flush();

        return $project;
    }

    private function card(Project $project, string $title): Card
    {
        $number = 1 + (int) $this->em->createQuery('SELECT COUNT(c.id) FROM '.Card::class.' c WHERE c.project = :project')->setParameter('project', $project)->getSingleScalarResult();
        $card = new Card($project, $this->column($project, 'next'), $title, '', $number);
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }

    private function note(?Card $card, string $body, SiteReviewCommentStatus $status = SiteReviewCommentStatus::Pending, string $createdAt = '2026-01-01 09:00:00'): SiteReviewComment
    {
        $comment = new SiteReviewComment($this->project, 0, $body, 'https://app.example/page', createdAt: new \DateTimeImmutable($createdAt));
        $comment->addAnchor('.hero', 'Hero');
        $comment->status = $status;
        $this->em->persist($comment);
        if (null !== $card) {
            $this->em->persist(new CardSiteReviewComment($card, $comment));
        }

        return $comment;
    }

    private function setStatusBehindTheRequest(SiteReviewComment $comment, SiteReviewCommentStatus $status): void
    {
        $this->em->createQuery('UPDATE '.SiteReviewComment::class.' c SET c.status = :status WHERE c.id = :id')
            ->setParameter('status', $status)
            ->setParameter('id', $comment->id, 'uuid')
            ->execute();
    }

    private function stored(SiteReviewComment $comment): SiteReviewComment
    {
        $this->em->clear();

        return static::getContainer()->get(SiteReviewCommentRepository::class)->find($comment->id) ?? throw new \LogicException('The note is gone.');
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
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
