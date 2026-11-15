<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Service\DocumentFingerprint;
use PHPUnit\Framework\TestCase;

final class DocumentFingerprintTest extends TestCase
{
    public function test_a_revision_with_the_same_title_changes_the_fingerprint(): void
    {
        $owner = new User(fullName: 'Riley Chen', email: 'riley@example.com', password: 'x');
        $document = new Document($owner, new Project($owner, 'fingerprint'), 'Same title');
        $document->addVersion('one', '<p>one</p>');
        $before = DocumentFingerprint::of($document);

        self::assertSame($before, DocumentFingerprint::of($document));

        $document->addVersion('two', '<p>two</p>');

        self::assertNotSame($before, DocumentFingerprint::of($document));
    }

    public function test_the_title_and_the_archive_state_change_the_fingerprint(): void
    {
        $owner = new User(fullName: 'Riley Chen', email: 'riley@example.com', password: 'x');
        $document = new Document($owner, new Project($owner, 'fingerprint'), 'Title');
        $document->addVersion('one', '<p>one</p>');
        $before = DocumentFingerprint::of($document);

        $document->title = 'Other';
        $renamed = DocumentFingerprint::of($document);
        $document->archivedAt = new \DateTimeImmutable();

        self::assertNotSame($before, $renamed);
        self::assertNotSame($renamed, DocumentFingerprint::of($document));
    }
}
