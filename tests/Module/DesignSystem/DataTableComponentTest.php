<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

final class DataTableComponentTest extends KernelTestCase
{
    private function render(string $template): Crawler
    {
        self::bootKernel();

        return new Crawler(static::getContainer()->get(Environment::class)->createTemplate($template)->render());
    }

    public function test_the_table_names_itself_and_marks_the_header_as_a_row_of_column_headers(): void
    {
        $table = $this->render('<twig:Ds:DataTable label="Runs" modifier="runs" :columns="[{label: \'Work\'}, {label: \'Duration\', align: \'end\'}]"></twig:Ds:DataTable>')->filter('.lp-data-table');

        self::assertCount(1, $table);
        self::assertSame('table', $table->attr('role'));
        self::assertSame('Runs', $table->attr('aria-label'));
        self::assertStringContainsString('lp-data-table--runs', (string) $table->attr('class'));
        $header = $table->filter('.lp-data-table__header');
        self::assertSame('row', $header->attr('role'));
        self::assertNull($header->attr('aria-hidden'));
        $cells = $header->filter('[role="columnheader"]');
        self::assertSame(['Work', 'Duration'], $cells->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertStringContainsString('lp-data-table__cell--end', (string) $cells->eq(1)->attr('class'));
    }

    public function test_a_table_with_no_label_has_no_empty_name(): void
    {
        $table = $this->render('<twig:Ds:DataTable :columns="[{label: \'Work\'}]"></twig:Ds:DataTable>')->filter('.lp-data-table');

        self::assertNull($table->attr('aria-label'));
    }

    public function test_a_screen_reader_only_column_keeps_its_label_out_of_sight(): void
    {
        $cells = $this->render('<twig:Ds:DataTable :columns="[{label: \'Work\'}, {label: \'Actions\', srOnly: true}]"></twig:Ds:DataTable>')->filter('[role="columnheader"]');

        self::assertStringNotContainsString('sr-only', (string) $cells->eq(0)->attr('class'));
        self::assertStringContainsString('sr-only', (string) $cells->eq(1)->attr('class'));
    }

    public function test_a_header_block_replaces_the_generated_cells(): void
    {
        $table = $this->render('<twig:Ds:DataTable :columns="[{label: \'Generated\'}]"><twig:block name="header"><input type="checkbox" data-all><span role="columnheader">Custom</span></twig:block><div class="lp-data-table__row" role="row" data-row></div></twig:Ds:DataTable>')->filter('.lp-data-table');

        self::assertCount(1, $table->filter('.lp-data-table__header [data-all]'));
        self::assertSame('Custom', trim($table->filter('.lp-data-table__header')->text()));
        self::assertCount(1, $table->filter('[data-row]'));
    }

    public function test_any_other_attribute_reaches_the_table(): void
    {
        $table = $this->render('<twig:Ds:DataTable :columns="[]" data-subject="runs"></twig:Ds:DataTable>')->filter('.lp-data-table');

        self::assertSame('runs', $table->attr('data-subject'));
    }
}
