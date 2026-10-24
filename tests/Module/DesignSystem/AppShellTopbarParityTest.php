<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Tests\Support\ComponentSourceReader;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AppShellTopbarParityTest extends KernelTestCase
{
    use ComponentSourceReader;

    private static function translate(string $key): string
    {
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return $translator->trans($key, [], null, 'en');
    }

    public function test_the_specimen_and_the_export_label_the_bell_and_new_work_as_the_app_does(): void
    {
        $jsx = self::componentJsx('AppShell');
        $specimen = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir').'/templates/Module/DesignSystem/specimen/appshell.html.twig');
        $bell = self::translate('nav.activity.open');
        $newWork = self::translate('nav.new_work');

        foreach (['export' => $jsx, 'specimen' => $specimen] as $name => $source) {
            self::assertStringContainsString('aria-label="'.$bell.'"', $source, $name);
            self::assertStringContainsString('aria-label="'.$newWork.'"', $source, $name);
        }
    }
}
