<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification;

use App\Module\Notification\PushContent;
use PHPUnit\Framework\TestCase;

final class PushContentTest extends TestCase
{
    public function test_it_serialises_to_the_declarative_shape(): void
    {
        $content = new PushContent(
            title: 'Acme storefront',
            body: 'Can the tech design skip its review? +2 more questions',
            url: 'https://app.example/projects/p1/inbox?ask=a1',
            tag: 'ask-a1',
            openTags: ['ask-a1', 'item-i1'],
        );

        self::assertSame([
            'web_push' => 8030,
            'mutable' => true,
            'notification' => [
                'title' => 'Acme storefront',
                'body' => 'Can the tech design skip its review? +2 more questions',
                'navigate' => 'https://app.example/projects/p1/inbox?ask=a1',
                'tag' => 'ask-a1',
                'icon' => 'https://app.example/icons/push-192.png',
                'data' => [
                    'url' => 'https://app.example/projects/p1/inbox?ask=a1',
                    'openTags' => ['ask-a1', 'item-i1'],
                ],
            ],
        ], json_decode($content->toJson(), true, flags: \JSON_THROW_ON_ERROR));
    }

    public function test_the_payload_has_no_member_outside_notification_but_two(): void
    {
        $decoded = json_decode($this->content()->toJson(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(['web_push', 'mutable', 'notification'], array_keys($decoded));
    }

    public function test_the_icon_keeps_the_port_of_the_instance_url(): void
    {
        $content = new PushContent('T', 'B', 'http://localhost:8080/projects/p1/inbox', 'ask-a1', ['ask-a1']);

        $decoded = json_decode($content->toJson(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('http://localhost:8080/icons/push-192.png', $decoded['notification']['icon']);
    }

    public function test_a_url_without_a_host_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PushContent('T', 'B', '/projects/p1/inbox', 'ask-a1', []);
    }

    public function test_open_tags_are_cut_to_fit_the_payload_limit_and_the_own_tag_stays(): void
    {
        $tags = ['ask-own'];
        for ($i = 0; $i < 400; ++$i) {
            $tags[] = 'ask-'.str_pad((string) $i, 36, '0', \STR_PAD_LEFT);
        }
        $content = new PushContent('Acme', 'Question', 'https://app.example/inbox', 'ask-own', $tags);

        $json = $content->toJson();
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        self::assertLessThanOrEqual(PushContent::MAX_BYTES, \strlen($json));
        self::assertSame('ask-own', $decoded['notification']['data']['openTags'][0]);
        self::assertGreaterThan(50, \count($decoded['notification']['data']['openTags']));
        self::assertLessThan(401, \count($decoded['notification']['data']['openTags']));
    }

    private function content(): PushContent
    {
        return new PushContent('T', 'B', 'https://app.example/inbox', 'ask-a1', ['ask-a1']);
    }
}
