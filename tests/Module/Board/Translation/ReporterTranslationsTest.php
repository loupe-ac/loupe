<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Translation;

use App\Module\Workflow\Contract\Actor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;

final class ReporterTranslationsTest extends KernelTestCase
{
    /** The card page builds the key from the reporter value, so a new actor kind needs its own key. */
    public function test_every_actor_kind_has_a_reporter_label(): void
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue('en');

        foreach (Actor::cases() as $actor) {
            if (Actor::System === $actor) {
                continue;
            }
            self::assertTrue($catalogue->has('board.card.reporter.'.$actor->value), \sprintf('No reporter label for "%s".', $actor->value));
        }
    }
}
