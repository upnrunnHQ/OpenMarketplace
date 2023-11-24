<?php

/*
 * This file has been created by developers from BitBag.
 * Feel free to contact us once you face any issues or want to start
 * You can find more information about us on https://bitbag.io and write us
 * an email on hello@bitbag.io.
 */

declare(strict_types=1);

namespace Tests\BitBag\OpenMarketplace\Integration\Cli;

use ApiTestCase\JsonApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class SettlementGenerateCommandTest extends JsonApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $command = $application->find('bitbag:settlement:generate');
        $this->commandTester = new CommandTester($command);
        $this->settlementRepository = self::getContainer()->get('open_marketplace.repository.settlement');
        $this->vendorRepository = self::getContainer()->get('bitbag.open_marketplace.component.vendor.repository.vendor');
        $this->channelRepository = self::getContainer()->get('sylius.repository.channel');
    }

    public function test_it_throws_exception_if_settlement_frequency_is_not_valid(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Frequency "wrong_frequency" is not valid. Available options are: weekly, monthly, quarterly');
        $this->commandTester->execute(['frequency' => 'wrong_frequency']);
    }

    public function test_it_generates_settlements_for_all_vendors_with_weekly_frequency(): void
    {
        $this->loadFixturesFromFile('SettlementGenerateCommandTest/test_it_generates_settlements_for_all_vendors_with_weekly_frequency.yaml');
        $this->assertCount(0, $this->settlementRepository->findAll());
        $this->commandTester->execute([]);
        $this->commandTester->assertCommandIsSuccessful();
        $vendorWeyland = $this->vendorRepository->findOneBySlug('Weyland-Corp');
        $vendorWayne = $this->vendorRepository->findOneBySlug('Wayne-Enterprises-Inc');
        $channelEu = $this->channelRepository->findOneBy(['code' => 'EU']);
        $channelUs = $this->channelRepository->findOneBy(['code' => 'US']);
        $settlementsVendorWeyland = $this->settlementRepository->findBy(['vendor' => $vendorWeyland]);
        $settlementsVendorWayne = $this->settlementRepository->findBy(['vendor' => $vendorWayne]);
        $startDate = new \DateTime('last week monday 00:00:00');
        $endDate = new \DateTime('last week sunday 23:59:59');
        $this->assertCount(2, $settlementsVendorWayne);
        $this->assertSame(540, $settlementsVendorWayne[0]->getTotalAmount());
        $this->assertSame(35, $settlementsVendorWayne[0]->getTotalCommissionAmount());
        $this->assertSame($startDate->getTimestamp(), $settlementsVendorWayne[0]->getStartDate()->getTimestamp());
        $this->assertSame($endDate->getTimestamp(), $settlementsVendorWayne[0]->getEndDate()->getTimestamp());
        $this->assertSame($channelUs, $settlementsVendorWayne[0]->getChannel());
        $this->assertSame(1002, $settlementsVendorWayne[1]->getTotalAmount());
        $this->assertSame(70, $settlementsVendorWayne[1]->getTotalCommissionAmount());
        $this->assertSame($startDate->getTimestamp(), $settlementsVendorWayne[1]->getStartDate()->getTimestamp());
        $this->assertSame($endDate->getTimestamp(), $settlementsVendorWayne[1]->getEndDate()->getTimestamp());
        $this->assertSame($channelEu, $settlementsVendorWayne[1]->getChannel());

        $this->assertCount(2, $settlementsVendorWeyland);
        $this->assertSame(0, $settlementsVendorWeyland[0]->getTotalAmount());
        $this->assertSame(0, $settlementsVendorWeyland[0]->getTotalCommissionAmount());
        $this->assertSame($startDate->getTimestamp(), $settlementsVendorWeyland[0]->getStartDate()->getTimestamp());
        $this->assertSame($endDate->getTimestamp(), $settlementsVendorWeyland[0]->getEndDate()->getTimestamp());
        $this->assertSame($channelUs, $settlementsVendorWeyland[0]->getChannel());
        $this->assertSame(700, $settlementsVendorWeyland[1]->getTotalAmount());
        $this->assertSame(100, $settlementsVendorWeyland[1]->getTotalCommissionAmount());
        $this->assertSame($startDate->getTimestamp(), $settlementsVendorWeyland[1]->getStartDate()->getTimestamp());
        $this->assertSame($endDate->getTimestamp(), $settlementsVendorWeyland[1]->getEndDate()->getTimestamp());
        $this->assertSame($channelEu, $settlementsVendorWeyland[1]->getChannel());
    }
}
