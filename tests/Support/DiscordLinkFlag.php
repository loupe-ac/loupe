<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Site\SiteLinks;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

trait DiscordLinkFlag
{
    private function setDiscordLink(KernelBrowser $client, string $url): void
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $flag = $em->getRepository(FeatureFlag::class)->findOneBy(['name' => SiteLinks::DISCORD_FLAG]);
        if (null === $flag) {
            $flag = new FeatureFlag(name: SiteLinks::DISCORD_FLAG, type: FeatureFlagType::String, value: $url);
            $em->persist($flag);
        }
        $flag->value = $url;
        $em->flush();
    }
}
