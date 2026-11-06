<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Module\Workflow\Template\TemplateVersion;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20261106170000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Make the template version of a workflow binding a short hash of the stored template content';
    }

    public function up(Schema $schema): void
    {
        // @contract-phase: the previous image reads the column as an integer, and Doctrine casts a hash string to 0, so a rollback shows version 0 and breaks nothing.
        $this->addSql('ALTER TABLE workflow_bindings ALTER template_version TYPE VARCHAR(12) USING template_version::text');
    }

    #[\Override]
    public function postUp(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, definition FROM workflow_bindings');
        foreach ($rows as $row) {
            $definition = json_decode((string) $row['definition'], true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($definition)) {
                throw new \LogicException(\sprintf('The stored template of binding %s is not a map.', (string) $row['id']));
            }
            $this->connection->executeStatement('UPDATE workflow_bindings SET template_version = ? WHERE id = ?', [TemplateVersion::of($definition), $row['id']]);
        }
    }

    /** Each binding gets the frozen number of its own template. A binding of any other template stops the rollback. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $numbers = [];
        foreach (['lifecycle', 'simple'] as $key) {
            $source = Yaml::parseFile(__DIR__.'/../config/workflows/'.$key.'.yaml');
            if (!\is_array($source) || !\is_int($source['version'] ?? null)) {
                throw new \LogicException(\sprintf('The shipped workflow template "%s" has no frozen version.', $key));
            }
            $numbers[$key] = $source['version'];
        }
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM workflow_bindings WHERE template_key NOT IN (?, ?)', array_keys($numbers)) > 0,
            'A workflow binding names a template other than lifecycle and simple, so no number fits it.',
        );
        $this->addSql('UPDATE workflow_bindings SET template_version = :lifecycle WHERE template_key = :lifecycleKey', ['lifecycle' => (string) $numbers['lifecycle'], 'lifecycleKey' => 'lifecycle']);
        $this->addSql('UPDATE workflow_bindings SET template_version = :simple WHERE template_key = :simpleKey', ['simple' => (string) $numbers['simple'], 'simpleKey' => 'simple']);
        $this->addSql('ALTER TABLE workflow_bindings ALTER template_version TYPE INT USING template_version::int');
    }
}
