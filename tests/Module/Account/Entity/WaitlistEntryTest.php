<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Entity;

use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Entity\WaitlistPick;
use PHPUnit\Framework\TestCase;

final class WaitlistEntryTest extends TestCase
{
    public function test_email_is_lowercased(): void
    {
        self::assertSame('who@example.com', new WaitlistEntry('WHO@Example.COM')->email);
    }

    public function test_issue_invite_token_stores_hash_and_expiry(): void
    {
        $entry = new WaitlistEntry('a@example.com');
        $token = $entry->issueInviteToken();

        self::assertNotNull($entry->invitedAt);
        self::assertNotNull($entry->inviteExpiresAt);
        self::assertTrue($entry->isInviteTokenValid($token));
        self::assertFalse($entry->isInviteTokenValid('wrong-token'));
    }

    public function test_expired_invite_token_is_invalid(): void
    {
        $entry = new WaitlistEntry('a@example.com');
        $token = $entry->issueInviteToken(expiresAt: new \DateTimeImmutable('-1 minute'));

        self::assertFalse($entry->isInviteTokenValid($token));
    }

    public function test_converted_entry_rejects_its_token(): void
    {
        $entry = new WaitlistEntry('a@example.com');
        $token = $entry->issueInviteToken();
        $entry->markConverted();

        self::assertNotNull($entry->convertedAt);
        self::assertFalse($entry->isInviteTokenValid($token));
    }

    public function test_is_invited_reflects_state(): void
    {
        $entry = new WaitlistEntry('a@example.com');
        self::assertFalse($entry->isInvited());
        $entry->issueInviteToken();
        self::assertTrue($entry->isInvited());
    }

    public function test_needs_invite_is_true_for_a_never_invited_entry(): void
    {
        self::assertTrue(new WaitlistEntry('a@example.com')->needsInvite());
    }

    public function test_needs_invite_is_false_while_an_active_invite_is_outstanding(): void
    {
        $entry = new WaitlistEntry('a@example.com');
        $entry->issueInviteToken();

        self::assertFalse($entry->needsInvite());
    }

    public function test_needs_invite_is_true_once_the_invite_link_expires_unused(): void
    {
        $entry = new WaitlistEntry('a@example.com');
        $entry->issueInviteToken(expiresAt: new \DateTimeImmutable('-1 minute'));

        self::assertTrue($entry->needsInvite());
    }

    public function test_needs_invite_is_false_for_a_converted_entry_even_if_its_invite_expired(): void
    {
        $entry = new WaitlistEntry('a@example.com');
        $entry->issueInviteToken(expiresAt: new \DateTimeImmutable('-1 minute'));
        $entry->markConverted();

        self::assertFalse($entry->needsInvite());
    }

    public function test_merge_picks_unions_in_the_order_of_the_enum(): void
    {
        $entry = new WaitlistEntry('picks@example.com');

        self::assertTrue($entry->mergePicks([WaitlistPick::Pi, WaitlistPick::GitLab], null));
        self::assertTrue($entry->mergePicks([WaitlistPick::Cursor, WaitlistPick::Pi], null));

        self::assertSame(['gitlab', 'cursor', 'pi'], $entry->picks);
    }

    public function test_merge_picks_reports_no_change_for_known_picks_and_empty_text(): void
    {
        $entry = new WaitlistEntry('picks@example.com');
        $entry->mergePicks([WaitlistPick::Gitea], 'Jujutsu');

        self::assertFalse($entry->mergePicks([WaitlistPick::Gitea], null));
        self::assertFalse($entry->mergePicks([], ''));
        self::assertFalse($entry->mergePicks([], 'Jujutsu'));
        self::assertSame('Jujutsu', $entry->otherPick);
    }

    public function test_merge_picks_replaces_the_text_only_with_a_non_empty_text(): void
    {
        $entry = new WaitlistEntry('picks@example.com');
        $entry->mergePicks([], 'Jujutsu');

        $entry->mergePicks([], '');
        self::assertSame('Jujutsu', $entry->otherPick);

        $entry->mergePicks([], 'Sapling');
        self::assertSame('Sapling', $entry->otherPick);
    }

    public function test_claim_invite_stamps_the_claim_and_converts_the_entry(): void
    {
        $entry = new WaitlistEntry('claim@example.com');
        $entry->issueInviteToken();

        $entry->claimInvite();

        self::assertNotNull($entry->inviteClaimedAt);
        self::assertNotNull($entry->convertedAt);
    }

    public function test_claim_invite_keeps_the_first_claim_time(): void
    {
        $entry = new WaitlistEntry('claim@example.com');
        $first = new \DateTimeImmutable('-3 days');
        $entry->inviteClaimedAt = $first;
        $entry->convertedAt = $first;

        $entry->claimInvite();

        self::assertSame($first, $entry->inviteClaimedAt);
        self::assertSame($first, $entry->convertedAt);
    }

    public function test_a_plain_conversion_leaves_no_claim_stamp(): void
    {
        $entry = new WaitlistEntry('plain@example.com');

        $entry->markConverted();

        self::assertNull($entry->inviteClaimedAt);
    }

    public function test_reopen_clears_the_claim_stamp(): void
    {
        $entry = new WaitlistEntry('reopen@example.com');
        $entry->issueInviteToken();
        $entry->claimInvite();

        $entry->reopen();

        self::assertNull($entry->inviteClaimedAt);
        self::assertNull($entry->convertedAt);
    }
}
