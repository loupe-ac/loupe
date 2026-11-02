<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Repository\ReviewRepository;
use App\Module\Review\Service\ReviewExporter;
use App\Security\ApiKeyActor;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ReviewExporterTest extends TestCase
{
    public function test_exports_flat_review_rows(): void
    {
        $reviewer = new User('Alice A', 'alice@example.com', 'x');
        $project = new Project($reviewer, 'My project');
        $document = new Document($reviewer, $project, 'My doc');
        $version = $document->addVersion('# v1', '<h1>v1</h1>');
        $review = new Review($version, Verdict::Approved, $reviewer, note: 'Ready for implementation.');

        /** @var ReviewRepository&Stub $repo */
        $repo = $this->createStub(ReviewRepository::class);
        $repo->method('streamByReviewer')->willReturn([$review]);

        $rows = iterator_to_array(new ReviewExporter($repo)->export($reviewer));

        self::assertCount(1, $rows);
        self::assertSame('My doc', $rows[0]['document']);
        self::assertSame(1, $rows[0]['versionNumber']);
        self::assertSame('approved', $rows[0]['verdict']);
        self::assertSame('Ready for implementation.', $rows[0]['note']);
        self::assertArrayHasKey('submittedAt', $rows[0]);
        self::assertFalse($rows[0]['throughAgent']);
        self::assertSame('reviews.json', new ReviewExporter($repo)->filename());
    }

    public function test_exports_the_agent_mark_of_a_verdict(): void
    {
        $reviewer = new User('Alice A', 'alice@example.com', 'x');
        $document = new Document($reviewer, new Project($reviewer, 'My project'), 'My doc');
        $review = new Review($document->addVersion('# v1', '<h1>v1</h1>'), Verdict::Withdrawn, $reviewer, throughAgent: true);

        /** @var ReviewRepository&Stub $repo */
        $repo = $this->createStub(ReviewRepository::class);
        $repo->method('streamByReviewer')->willReturn([$review]);

        $rows = iterator_to_array(new ReviewExporter($repo)->export($reviewer));

        self::assertTrue($rows[0]['throughAgent']);
    }

    public function test_exports_the_key_a_verdict_was_recorded_through(): void
    {
        $reviewer = new User('Alice A', 'alice@example.com', 'x');
        $document = new Document($reviewer, new Project($reviewer, 'My project'), 'My doc');
        $version = $document->addVersion('# v1', '<h1>v1</h1>');
        $key = new ApiKeyActor(Uuid::v7(), 'CI');
        $keyed = new Review($version, Verdict::Approved, $reviewer, apiKeyId: $key->keyId, apiKeyName: $key->keyName);
        $plain = new Review($version, Verdict::Approved, $reviewer, 2);

        /** @var ReviewRepository&Stub $repo */
        $repo = $this->createStub(ReviewRepository::class);
        $repo->method('streamByReviewer')->willReturn([$keyed, $plain]);

        $rows = iterator_to_array(new ReviewExporter($repo)->export($reviewer));

        self::assertSame((string) $key->keyId, $rows[0]['apiKeyId']);
        self::assertSame('CI', $rows[0]['apiKeyName']);
        self::assertNull($rows[1]['apiKeyId']);
        self::assertNull($rows[1]['apiKeyName']);
    }
}
