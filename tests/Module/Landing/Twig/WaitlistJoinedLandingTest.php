<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Twig;

use App\Module\Account\Form\WaitlistJoinFormType;
use App\Module\Account\Form\WaitlistJoinRequest;
use App\Site\SiteLinks;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final class WaitlistJoinedLandingTest extends KernelTestCase
{
    private function renderLanding(?string $waitlistJoined): Crawler
    {
        self::bootKernel();
        $container = static::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        $flag = $em->getRepository(FeatureFlag::class)->findOneBy(['name' => SiteLinks::DISCORD_FLAG])
            ?? new FeatureFlag(name: SiteLinks::DISCORD_FLAG, type: FeatureFlagType::String, value: '');
        $flag->value = 'https://discord.gg/loupe';
        $em->persist($flag);
        $em->flush();

        $container->get(RequestStack::class)->push(Request::create('/'));
        $forms = $container->get(FormFactoryInterface::class);

        return new Crawler($container->get(Environment::class)->render('@Landing/landing.html.twig', [
            'notifyForm' => $forms->create(WaitlistJoinFormType::class, new WaitlistJoinRequest())->createView(),
            'modalForm' => $forms->createNamed(WaitlistJoinFormType::MODAL_NAME, WaitlistJoinFormType::class, new WaitlistJoinRequest())->createView(),
            'waitlistJoined' => $waitlistJoined,
        ]));
    }

    public function test_the_home_page_shows_both_forms_by_default(): void
    {
        $page = $this->renderLanding(null);

        self::assertCount(1, $page->filter('.lp-home-ask #landing-ask-body[hidden] turbo-frame#waitlist-notify form'));
        self::assertCount(1, $page->filter('dialog turbo-frame#waitlist-modal form'));
        self::assertCount(0, $page->filter('[data-discord-card]'));
        self::assertCount(0, $page->filter('[data-modal-reopen-value]'));
    }

    public function test_the_notify_joined_state_opens_the_ask_with_the_discord_card_in_its_frame(): void
    {
        $page = $this->renderLanding('notify');

        self::assertCount(1, $page->filter('.lp-home-ask[data-landing-ask-open-value="true"] #landing-ask-body:not([hidden]) turbo-frame#waitlist-notify [data-discord-card]'));
        self::assertCount(0, $page->filter('turbo-frame#waitlist-notify form'));
        self::assertCount(1, $page->filter('dialog turbo-frame#waitlist-modal form'));
    }

    public function test_the_modal_joined_state_reopens_the_dialog_with_the_discord_card_and_close(): void
    {
        $page = $this->renderLanding('modal');

        self::assertCount(1, $page->filter('.lp-home[data-modal-reopen-value="true"]'));
        self::assertCount(1, $page->filter('dialog turbo-frame#waitlist-modal [data-discord-card]'));
        self::assertCount(1, $page->filter('dialog turbo-frame#waitlist-modal button[data-action="click->modal#close"]'));
        self::assertCount(0, $page->filter('turbo-frame#waitlist-modal form'));
        self::assertCount(1, $page->filter('#landing-ask-body[hidden] turbo-frame#waitlist-notify form'));
    }
}
