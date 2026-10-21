<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\ReviewSurface;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\OAuthScenario;
use App\Tests\Support\TinyImage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AddFeedbackApiTest extends WebTestCase
{
    use BoardColumnFixtures;

    public function test_a_note_creates_its_card_and_the_answer_names_it(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'feedback-api-new@example.com');

        $data = $this->post($client, $raw, $this->note(['newCard' => new \stdClass()]));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $data['number']);
        self::assertSame('#1 Footer overlaps the launcher Seen at 1280px.', $data['label']);
        self::assertStringContainsString($data['cardId'], $data['url']);
        self::assertIsString($data['commentId']);

        $cards = $this->service(CardRepository::class)->findBy(['project' => $project]);
        self::assertCount(1, $cards);
        self::assertSame('feature', $cards[0]->type);
        self::assertSame("Footer overlaps the launcher\nSeen at 1280px.", $cards[0]->body);
        $links = $this->service(CardSiteReviewCommentRepository::class)->findForCard($cards[0]);
        self::assertCount(1, $links);
        self::assertTrue($links[0]->createdCard);
        self::assertSame($data['commentId'], (string) $links[0]->comment->id);
    }

    public function test_a_note_goes_under_an_epic(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'feedback-api-epic@example.com');
        $epic = $this->card($project, 'backlog', 1, 'epic');

        $data = $this->post($client, $raw, $this->note(['newCard' => ['parentCardId' => (string) $epic->id]]));

        self::assertResponseStatusCodeSame(201);
        $card = $this->service(CardRepository::class)->find($data['cardId']);
        self::assertInstanceOf(Card::class, $card);
        self::assertSame((string) $epic->id, (string) $card->parent?->id);
    }

    /**
     * The comment keeps the page's context as the page said it. The target
     * alone decides the card, so a context naming another card links nothing.
     */
    public function test_a_card_context_on_the_page_is_stored_and_adds_no_second_link(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'feedback-api-context@example.com');
        $target = $this->card($project, 'backlog', 1);
        $other = $this->card($project, 'backlog', 2);
        $links = $this->service(CardSiteReviewCommentRepository::class);

        $this->post($client, $raw, $this->note(['cardId' => (string) $target->id], ['context' => 'card:'.$other->id]));
        self::assertResponseStatusCodeSame(201);
        $this->post($client, $raw, $this->note(['cardId' => (string) $target->id], ['context' => 'card:'.$target->id, 'body' => 'Second note']));
        self::assertResponseStatusCodeSame(201);
        $this->post($client, $raw, $this->note(['cardId' => (string) $target->id], ['context' => '  ', 'body' => 'Third note']));
        self::assertResponseStatusCodeSame(201);

        $saved = $links->findForCard($target);
        self::assertCount(3, $saved);
        self::assertSame([], $links->findForCard($other));
        $contexts = [];
        foreach ($saved as $link) {
            self::assertFalse($link->createdCard);
            $contexts[$link->comment->body] = $link->comment->context;
        }
        self::assertSame([
            "Footer overlaps the launcher\nSeen at 1280px." => 'card:'.$other->id,
            'Second note' => 'card:'.$target->id,
            'Third note' => null,
        ], $contexts);
    }

    public function test_a_retried_delivery_answers_with_the_same_card(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'feedback-api-retry@example.com');
        $note = $this->note(['newCard' => new \stdClass()], ['deliveryId' => '0199c0de-0000-4000-8000-000000000001']);

        $first = $this->post($client, $raw, $note);
        $second = $this->post($client, $raw, $note);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($first, $second);
        self::assertCount(1, $this->service(CardRepository::class)->findBy(['project' => $project]));
    }

    public function test_a_closed_card_is_refused_by_name(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'feedback-api-closed@example.com');
        $done = $this->card($project, 'done', 1);

        $data = $this->post($client, $raw, $this->note(['cardId' => (string) $done->id]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'target_closed'], $data);
    }

    /** @return iterable<string, array{mixed}> */
    public static function badTargets(): iterable
    {
        yield 'absent' => [null];
        yield 'empty' => [new \stdClass()];
        yield 'both' => [['cardId' => '0199c0de-0000-7000-8000-0000000000ff', 'newCard' => new \stdClass()]];
        yield 'not a uuid' => [['cardId' => 'nope']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badTargets')]
    public function test_a_malformed_target_fails_validation(mixed $target): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'feedback-api-bad@example.com');

        $note = $this->note([]);
        if (null === $target) {
            unset($note['target']);
        } else {
            $note['target'] = $target;
        }
        $this->post($client, $raw, $note);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->service(CardRepository::class)->findBy(['project' => $project]));
    }

    public function test_the_widget_can_call_it_from_another_origin(): void
    {
        $client = static::createClient();
        [$raw] = $this->projectWithToken($client, 'feedback-api-cors@example.com');

        $client->request(Request::METHOD_OPTIONS, '/api/board/feedback', server: ['HTTP_ORIGIN' => 'https://app.localhost']);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('https://app.localhost', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));

        $this->post($client, $raw, $this->note(['newCard' => new \stdClass()]));
        self::assertSame('https://app.localhost', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_an_account_token_is_refused(): void
    {
        $client = static::createClient();
        $scenario = new OAuthScenario(static::getContainer());
        $scenario->createClient();
        $user = $scenario->createUser('feedback-api-mcp@example.com');
        $project = $scenario->createProject($user, 'feedback-api-mcp');
        $raw = $scenario->accessTokenFor($client, $user, 'mcp', $project);

        $this->post($client, $raw, $this->note(['newCard' => new \stdClass()]));

        self::assertResponseStatusCodeSame(403);
    }

    public function test_a_native_note_with_a_screenshot_is_stored_with_its_facts(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'feedback-api-native@example.com');
        $png = TinyImage::png(5, 4);

        $data = $this->post($client, $raw, $this->nativeNote(['screenshot' => ['contentType' => 'image/png', 'data' => base64_encode($png)]]));

        self::assertResponseStatusCodeSame(201);
        $link = $this->service(CardSiteReviewCommentRepository::class)->findForCard($this->service(CardRepository::class)->findBy(['project' => $project])[0])[0];
        self::assertSame($data['commentId'], (string) $link->comment->id);
        self::assertSame(ReviewSurface::IosSimulator, $link->comment->surface);
        self::assertNull($link->comment->url);
        self::assertSame([], $link->comment->anchors->toArray());
        self::assertSame('com.example.treadmatic', $link->comment->native['app']['bundleId'] ?? null);
        self::assertSame(['x' => 10.0, 'y' => 20.0, 'width' => 80.0, 'height' => 44.0], $link->comment->native['element']['frame'] ?? null);
        $row = $this->em()->getConnection()->fetchAssociative('SELECT content_type, width, height, size FROM site_review_screenshots WHERE comment_id = ?', [$data['commentId']]);
        self::assertSame(['content_type' => 'image/png', 'width' => 5, 'height' => 4, 'size' => \strlen($png)], $row);
    }

    public function test_a_web_note_can_carry_a_screenshot_too(): void
    {
        $client = static::createClient();
        [$raw] = $this->projectWithToken($client, 'feedback-api-web-shot@example.com');

        $data = $this->post($client, $raw, $this->note(['newCard' => new \stdClass()], ['screenshot' => ['contentType' => 'image/jpeg', 'data' => base64_encode(TinyImage::jpeg())]]));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM site_review_screenshots WHERE comment_id = ?', [$data['commentId']]));
    }

    public function test_a_retried_native_note_answers_with_the_same_card_and_a_changed_image_conflicts(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'feedback-api-native-retry@example.com');
        $shot = static fn (int $size): array => ['screenshot' => ['contentType' => 'image/png', 'data' => base64_encode(TinyImage::png($size, $size))]];
        $extra = ['deliveryId' => '0199c0de-0000-4000-8000-000000000002'];

        $first = $this->post($client, $raw, $this->nativeNote($shot(2) + $extra));
        $second = $this->post($client, $raw, $this->nativeNote($shot(2) + $extra));
        self::assertResponseStatusCodeSame(201);
        self::assertSame($first, $second);

        $this->post($client, $raw, $this->nativeNote($shot(3) + $extra));
        self::assertResponseStatusCodeSame(409);
        $changedFact = $this->nativeNote($shot(2) + $extra);
        $changedFact['native']['screenTitle'] = 'Profile';
        $this->post($client, $raw, $changedFact);
        self::assertResponseStatusCodeSame(409);
        self::assertCount(1, $this->service(CardRepository::class)->findBy(['project' => $project]));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badScreenshots(): iterable
    {
        yield 'not base64' => [['contentType' => 'image/png', 'data' => '***'], 'screenshot_invalid'];
        yield 'an svg' => [['contentType' => 'image/png', 'data' => base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>')], 'screenshot_invalid'];
        yield 'a png declared as a jpeg' => [['contentType' => 'image/jpeg', 'data' => base64_encode(TinyImage::png())], 'screenshot_invalid'];
        yield 'an unsupported content type' => [['contentType' => 'image/svg+xml', 'data' => base64_encode(TinyImage::png())], 'screenshot_invalid'];
        yield 'no content type' => [['data' => base64_encode(TinyImage::png())], 'screenshot_invalid'];
        yield 'blank data' => [['contentType' => 'image/png', 'data' => ''], 'screenshot_invalid'];
        yield 'no data' => [['contentType' => 'image/png'], 'screenshot_invalid'];
        yield 'over two mebibytes' => [['contentType' => 'image/png', 'data' => base64_encode(TinyImage::png().str_repeat("\x00", 2 * 1024 * 1024))], 'screenshot_too_large'];
    }

    /** @param array<string, mixed> $screenshot */
    #[\PHPUnit\Framework\Attributes\DataProvider('badScreenshots')]
    public function test_a_screenshot_that_is_not_an_acceptable_image_is_refused_before_any_write(array $screenshot, string $error): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'feedback-api-bad-shot-'.md5($error.json_encode($screenshot)).'@example.com');

        $data = $this->post($client, $raw, $this->nativeNote(['screenshot' => $screenshot]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => $error], $data);
        self::assertCount(0, $this->service(CardRepository::class)->findBy(['project' => $project]));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM site_review_screenshots'));
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>}> */
    public static function inconsistentNotes(): iterable
    {
        yield 'a native note with no facts' => [static function (array $note): array {
            unset($note['native']);

            return $note;
        }];
        yield 'a native note with a page' => [static fn (array $note): array => $note + ['url' => 'https://app.localhost/x']];
        yield 'a web note with native facts' => [static fn (array $note): array => ['surface' => 'web', 'url' => 'https://app.localhost/x'] + $note];
        yield 'a web note with no page' => [static fn (array $note): array => ['surface' => 'web', 'native' => null] + $note];
        yield 'an unknown surface' => [static fn (array $note): array => ['surface' => 'android'] + $note];
        yield 'native facts without a bundle id' => [static function (array $note): array {
            unset($note['native']['bundleId']);

            return $note;
        }];
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $break */
    #[\PHPUnit\Framework\Attributes\DataProvider('inconsistentNotes')]
    public function test_a_note_whose_surface_and_facts_disagree_fails_validation(callable $break): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'feedback-api-shape-'.uniqid().'@example.com');

        $this->post($client, $raw, $break($this->nativeNote()));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->service(CardRepository::class)->findBy(['project' => $project]));
    }

    public function test_the_review_list_serves_web_notes_unless_asked_for_native_ones(): void
    {
        $client = static::createClient();
        [$raw] = $this->projectWithToken($client, 'feedback-api-list@example.com');
        $web = $this->post($client, $raw, $this->note(['newCard' => new \stdClass()]));
        $native = $this->post($client, $raw, $this->nativeNote());

        $ids = fn (string $query): array => array_column($this->get($client, $raw, '/api/live-review/review'.$query)['comments'] ?? [], 'id');

        self::assertSame([$web['commentId']], $ids(''));
        self::assertSame([$web['commentId']], $ids('?surface=web'));
        self::assertSame([$native['commentId']], $ids('?surface=ios-simulator'));
        $listed = $this->get($client, $raw, '/api/live-review/review?surface=ios-simulator')['comments'][0];
        self::assertNull($listed['url']);
        self::assertSame('ios-simulator', $listed['surface']);
        self::assertSame('com.example.treadmatic', $listed['native']['app']['bundleId']);
        self::assertSame(['selector' => '', 'text' => ''], ['selector' => $listed['selector'], 'text' => $listed['text']]);

        $this->get($client, $raw, '/api/live-review/review?surface=android');
        self::assertResponseStatusCodeSame(422);
    }

    public function test_the_review_response_keeps_the_selector_and_text_of_anchor_zero(): void
    {
        $client = static::createClient();
        [$raw] = $this->projectWithToken($client, 'feedback-api-list-legacy@example.com');
        $this->post($client, $raw, $this->note(['newCard' => new \stdClass()]));

        $listed = $this->get($client, $raw, '/api/live-review/review')['comments'][0];

        self::assertSame('.footer', $listed['selector']);
        self::assertSame('Footer', $listed['text']);
        self::assertSame('web', $listed['surface']);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function nativeNote(array $extra = []): array
    {
        return $extra + [
            'surface' => 'ios-simulator',
            'body' => 'The Save button is clipped',
            'native' => [
                'appName' => 'Treadmatic',
                'bundleId' => 'com.example.treadmatic',
                'deviceName' => 'iPhone 17 Pro',
                'udid' => 'A1B2C3D4',
                'osVersion' => 'iOS-26-5',
                'screenTitle' => 'Settings',
                'element' => ['role' => 'AXButton', 'label' => 'Save', 'identifier' => null, 'frame' => ['x' => 10, 'y' => 20, 'width' => 80, 'height' => 44]],
            ],
            'target' => ['newCard' => new \stdClass()],
        ];
    }

    /** @return array<string, mixed> */
    private function get(KernelBrowser $client, string $raw, string $path): array
    {
        $client->request(Request::METHOD_GET, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'HTTP_ORIGIN' => 'https://app.localhost']);
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function note(array $target, array $extra = []): array
    {
        return $extra + [
            'body' => "Footer overlaps the launcher\nSeen at 1280px.",
            'url' => 'https://app.localhost/checkout',
            'anchors' => [['selector' => '.footer', 'text' => 'Footer']],
            'target' => $target,
        ];
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    private function post(KernelBrowser $client, string $raw, array $json): array
    {
        $client->request(Request::METHOD_POST, '/api/board/feedback',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://app.localhost'],
            content: json_encode($json, \JSON_THROW_ON_ERROR));

        $data = json_decode((string) $client->getResponse()->getContent(), true);

        return \is_array($data) ? $data : [];
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{0: string, 1: Project}
     */
    private function projectWithToken(KernelBrowser $client, string $email): array
    {
        $scenario = new OAuthScenario(static::getContainer());
        $scenario->createClient();
        $user = $scenario->createUser($email);
        $project = $scenario->createProject($user, 'feedback-api');
        $this->seedColumns($project);
        $this->em()->flush();
        $raw = $scenario->accessTokenFor($client, $user, 'site-review', $project);

        return [$raw, AgentCredential::managed($this->em(), $project, $project->id)];
    }

    private function card(Project $project, string $slug, int $number, string $type = 'feature'): Card
    {
        $card = new Card($project, $this->column($project, $slug), 'Existing card', '', $number, $type);
        $this->em()->persist($card);
        $this->em()->flush();

        return $card;
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

    private function em(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class);
    }
}
