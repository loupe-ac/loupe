<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Workflow\Contract\PauseReasonText;
use App\Module\Workflow\Service\TranslatedPauseReasonText;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TranslatedPauseReasonTextTest extends KernelTestCase
{
    public function test_the_contract_resolves_to_the_translating_service(): void
    {
        self::assertInstanceOf(TranslatedPauseReasonText::class, $this->text());
    }

    public function test_a_pause_code_reads_the_pause_text(): void
    {
        self::assertSame('The repair worker could not fix the failure.', $this->text()->forPause('repair-failed'));
    }

    public function test_a_pause_that_keeps_a_refusal_code_reads_the_refusal_text(): void
    {
        self::assertSame('The board refused the move.', $this->text()->forPause('move-refused'));
    }

    public function test_a_code_with_no_text_shows_as_it_is(): void
    {
        self::assertSame('forge-said-no', $this->text()->forPause('forge-said-no'));
        self::assertSame('forge-said-no', $this->text()->forRefusal('forge-said-no'));
    }

    public function test_a_refusal_code_reads_only_the_refusal_text(): void
    {
        self::assertSame('The board refused the move.', $this->text()->forRefusal('move-refused'));
        self::assertSame('repair-failed', $this->text()->forRefusal('repair-failed'));
    }

    private function text(): PauseReasonText
    {
        self::bootKernel();
        $text = self::getContainer()->get(PauseReasonText::class);
        self::assertInstanceOf(PauseReasonText::class, $text);

        return $text;
    }
}
