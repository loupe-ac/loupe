<?php

declare(strict_types=1);

namespace App\Module\Artifact\Service\Dev;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Artifact\Repository\ArtifactBlobRepository;
use App\Module\Artifact\Repository\ArtifactRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Stores one prototype with two versions, whose page runs a script, so the
 * library and the viewer have something to show. It also switches the artifacts
 * flag on, because a dev database has no flag seeded and the pages answer 404
 * while it is off.
 */
#[When('dev')]
#[When('test')]
final readonly class DevArtifactSeeder
{
    public const string NAME = 'Pricing page prototype';

    public const string SOURCE_LINK = 'https://claude.ai/design/p/00000000-0000-0000-0000-000000000000?file=Example.dc.html';

    private const string FIRST = <<<'HTML'
        <!doctype html>
        <html lang="en"><head><meta charset="utf-8"><title>Pricing</title><link rel="stylesheet" href="style.css"></head>
        <body><h1>Pricing</h1><p>First draft.</p></body></html>
        HTML;

    private const string SECOND = <<<'HTML'
        <!doctype html>
        <html lang="en"><head><meta charset="utf-8"><title>Pricing</title><link rel="stylesheet" href="style.css"></head>
        <body><h1>Pricing</h1><p>Second draft. <button id="count">Clicked 0 times</button></p>
        <script src="app.js"></script></body></html>
        HTML;

    private const string PLANS = <<<'HTML'
        <!doctype html>
        <html lang="en"><head><meta charset="utf-8"><title>Plans</title><link rel="stylesheet" href="style.css"></head>
        <body><h1>Plans</h1><p>The plans page of the prototype.</p></body></html>
        HTML;

    private const string STYLE = 'body{font-family:system-ui,sans-serif;margin:2rem}button{padding:.5rem 1rem}';

    private const string SCRIPT = "let n=0;document.getElementById('count').addEventListener('click',e=>{n++;e.target.textContent='Clicked '+n+' times'});";

    public function __construct(
        private EntityManagerInterface $em,
        private ArtifactRepository $artifacts,
        private ArtifactBlobRepository $artifactBlobs,
    ) {
    }

    /** Returns false when the project already holds an artifact. */
    public function seed(Project $project): bool
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE feature_flag SET value = 'true' WHERE name = ?",
            [ArtifactInstallFlags::FLAG_ENABLED],
        );

        if ([] !== $this->artifacts->findByProject($project, includeArchived: true)) {
            return false;
        }

        $artifact = new Artifact($project, self::NAME, $project->owner, self::SOURCE_LINK);
        $first = $artifact->addVersion($project->owner, notes: 'First draft of the pricing page.');
        $first->addFile('index.html', $this->artifactBlobs->findOrCreate($project, self::FIRST), 'text/html');
        $first->addFile('style.css', $this->artifactBlobs->findOrCreate($project, self::STYLE), 'text/css');
        $second = $artifact->addVersion(null, 'dev-seed-session', 'Added a button that counts its clicks.');
        $second->addFile('index.html', $this->artifactBlobs->findOrCreate($project, self::SECOND), 'text/html');
        $second->addFile('plans.html', $this->artifactBlobs->findOrCreate($project, self::PLANS), 'text/html');
        $second->addFile('style.css', $this->artifactBlobs->findOrCreate($project, self::STYLE), 'text/css');
        $second->addFile('app.js', $this->artifactBlobs->findOrCreate($project, self::SCRIPT), 'text/javascript');
        $this->em->persist($artifact);
        $this->em->flush();

        return true;
    }
}
