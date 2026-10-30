<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Twig;

use App\Module\Board\View\CardValueView;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardValueType;
use App\Tests\Module\Board\Controller\BoardScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

final class CardValueRowTest extends KernelTestCase
{
    use BoardScenario;

    public function test_the_switch_is_disabled_for_a_user_who_cannot_write_the_card(): void
    {
        $row = $this->row(new CardValueView('tech-design-interactive', CardValueType::Bool, 'l', 'c', 'h', 'hi', true, Actor::Agent));

        $switch = $row->filter('input.lp-switch');
        self::assertCount(1, $switch);
        self::assertNotNull($switch->attr('disabled'));
        self::assertNotNull($switch->attr('checked'));
    }

    public function test_a_value_set_by_an_agent_names_the_agent_and_the_time(): void
    {
        $row = $this->row(new CardValueView('tech-design-interactive', CardValueType::Bool, 'l', 'c', 'h', 'hi', false, Actor::System, null, new \DateTimeImmutable('-2 hours')));

        self::assertSame('Set by Loupe · 2h ago', preg_replace('/\s+/', ' ', trim($row->filter('.lp-card-value__meta')->text())));
        self::assertNull($row->filter('input.lp-switch')->attr('checked'));
    }

    private function row(CardValueView $value): Crawler
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $project = $this->project($em, $this->user($em, 'value-row-'.uniqid().'@example.com'));
        $card = $this->card($em, $project, 'A card');
        $twig = self::getContainer()->get(Environment::class);

        return new Crawler($twig->render('@Board/_card_value.html.twig', ['card' => $card, 'value' => $value, 'error' => null]));
    }
}
