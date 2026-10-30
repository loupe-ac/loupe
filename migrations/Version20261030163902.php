<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030163902 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the card value keys of Lifecycle version 31 to every stored Lifecycle copy, and change no other key';
    }

    /** The values section is a literal, so a later change of the shipped file does not change what this migration writes. */
    #[\Override]
    public function up(Schema $schema): void
    {
        $values = [[
            'key' => 'tech-design-interactive',
            'type' => 'bool',
            'label' => 'workflow.value.tech_design_interactive.label',
            'caption' => 'workflow.value.tech_design_interactive.caption',
            'help' => 'workflow.value.tech_design_interactive.help',
            'history' => 'workflow.value.tech_design_interactive.history',
        ]];
        $this->addSql(
            "UPDATE workflow_bindings
            SET template_version = 31,
                definition = definition || jsonb_build_object('values', CAST(:values AS JSONB), 'version', 31)
            WHERE template_key = :key",
            ['values' => json_encode($values, \JSON_THROW_ON_ERROR), 'key' => 'lifecycle'],
        );
    }

    /** Version 31 differs from version 30 by the values section alone, so removing it restores a version 30 copy. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql(
            "UPDATE workflow_bindings
            SET template_version = 30,
                definition = (definition - 'values') || jsonb_build_object('version', 30)
            WHERE template_key = :key AND template_version = 31",
            ['key' => 'lifecycle'],
        );
    }
}
