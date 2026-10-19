<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019091512 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the role to work requests and worker runs, and fill it for every existing row from its work kind';
    }

    /** A worker run of an interactive launch carries a name the user chose, so only a worker kind of run takes a role. */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE work_requests ADD role VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD role VARCHAR(20) DEFAULT NULL');

        $role = <<<'SQL'
            CASE %s
                WHEN 'review' THEN 'code-review'
                WHEN 'design-review' THEN 'design-review'
                WHEN 'product-design-revise' THEN 'design-revise'
                WHEN 'tech-design-revise' THEN 'design-revise'
                WHEN 'fix' THEN 'fix'
            END
            SQL;
        $this->addSql(\sprintf("UPDATE work_requests SET role = %s WHERE kind IN ('review', 'design-review', 'product-design-revise', 'tech-design-revise', 'fix')", \sprintf($role, 'kind')));
        $this->addSql(\sprintf("UPDATE bridge_worker_runs SET role = %s WHERE kind = 'worker' AND work_kind IN ('review', 'design-review', 'product-design-revise', 'tech-design-revise', 'fix')", \sprintf($role, 'work_kind')));
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE work_requests DROP role');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP role');
    }
}
