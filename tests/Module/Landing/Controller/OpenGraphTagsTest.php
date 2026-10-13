<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Controller;

use App\Module\Account\Service\RegistrationGate;
use App\Tests\Support\InstalledInstance;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class OpenGraphTagsTest extends WebTestCase
{
    /** @return iterable<string, array{string}> */
    public static function pages(): iterable
    {
        yield 'home' => ['/'];
        yield 'privacy' => ['/privacy'];
        yield 'terms' => ['/terms'];
        yield 'ai policy' => ['/ai-policy'];
        yield 'waitlist' => ['/waitlist'];
    }

    private function base(): string
    {
        $appUrl = static::getContainer()->getParameter('app.url');
        self::assertIsString($appUrl);

        return rtrim($appUrl, '/');
    }

    /** The migration has already seeded the row, so the test changes its value. */
    private function setImageFlag(KernelBrowser $client, string $value): void
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $repository = $client->getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $repository);
        $flag = $repository->findAllIndexed()['open_graph.image'] ?? null;
        self::assertInstanceOf(FeatureFlag::class, $flag);
        self::assertSame(['hero', 'board'], $flag->options);
        $flag->value = $value;
        $em->flush();
    }

    private function persistFlag(KernelBrowser $client, FeatureFlag $flag): void
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->persist($flag);
        $em->flush();
    }

    private function meta(KernelBrowser $client, string $selector): string
    {
        return (string) $client->getCrawler()->filter($selector)->attr('content');
    }

    #[DataProvider('pages')]
    public function test_each_public_page_prints_its_own_preview_tags(string $path): void
    {
        $client = static::createClient();
        InstalledInstance::ensure($client->getContainer());
        $this->persistFlag($client, new FeatureFlag(name: RegistrationGate::CAP_FLAG, type: FeatureFlagType::Int, value: 1));

        $client->request(Request::METHOD_GET, $path, server: ['HTTP_HOST' => 'evil.example']);

        self::assertResponseIsSuccessful();
        $description = $this->meta($client, 'meta[name="description"]');
        self::assertNotSame('', $description);
        self::assertSame($description, $this->meta($client, 'meta[property="og:description"]'));
        self::assertSame(trim($client->getCrawler()->filter('title')->text()), $this->meta($client, 'meta[property="og:title"]'));
        self::assertSame('website', $this->meta($client, 'meta[property="og:type"]'));
        self::assertSame('summary_large_image', $this->meta($client, 'meta[name="twitter:card"]'));
        self::assertSame('1200', $this->meta($client, 'meta[property="og:image:width"]'));
        self::assertSame('630', $this->meta($client, 'meta[property="og:image:height"]'));
        self::assertNotSame('', $this->meta($client, 'meta[property="og:image:alt"]'));
        self::assertSame($this->base().$path, $this->meta($client, 'meta[property="og:url"]'));
        self::assertStringStartsWith($this->base().'/assets/images/og/hero-', $this->meta($client, 'meta[property="og:image"]'));
        self::assertStringNotContainsString('evil.example', (string) $client->getResponse()->getContent());
    }

    public function test_a_page_without_a_description_prints_no_preview_tags(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('meta[property="og:title"]');
        self::assertSelectorNotExists('meta[name="description"]');
    }

    public function test_the_flag_picks_the_board_image(): void
    {
        $client = static::createClient();
        $this->setImageFlag($client, 'board');

        $client->request(Request::METHOD_GET, '/privacy');

        self::assertStringStartsWith($this->base().'/assets/images/og/board-', $this->meta($client, 'meta[property="og:image"]'));
    }

    public function test_an_unknown_flag_value_falls_back_to_the_hero_image(): void
    {
        $client = static::createClient();
        $this->setImageFlag($client, 'poster');

        $client->request(Request::METHOD_GET, '/privacy');

        self::assertStringStartsWith($this->base().'/assets/images/og/hero-', $this->meta($client, 'meta[property="og:image"]'));
    }
}
