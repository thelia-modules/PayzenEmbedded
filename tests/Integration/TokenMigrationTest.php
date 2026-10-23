<?php

declare(strict_types=1);

/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace PayzenEmbedded\Tests\Integration;

use PayzenEmbedded\LyraClient\StoredTokenGateway;
use PayzenEmbedded\LyraClient\TokenCancellationFailedException;
use PayzenEmbedded\Model\PayzenEmbeddedCustomerToken;
use PayzenEmbedded\Model\PayzenEmbeddedCustomerTokenQuery;
use PayzenEmbedded\Model\PayzenEmbeddedStoredMethodContractQuery;
use PayzenEmbedded\PayzenEmbedded;
use PayzenEmbedded\Service\LegacyTokenCanceller;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\Customer;
use Thelia\Model\CustomerPaymentMethod;
use Thelia\Model\CustomerPaymentMethodQuery;
use Thelia\Model\ModuleQuery;

/**
 * The cards of the 1-click payment, kept one per customer in the module's own table until 3.5.0, become
 * payment methods of the shop when the module is updated. The update script runs as the shop runs it
 * (PayzenEmbedded::update()), on the rows a shop really holds.
 */
final class TokenMigrationTest extends PayzenIntegrationTestCase
{
    public function testEachCardBecomesAPaymentMethodOfItsCustomerThroughThisModule(): void
    {
        $customer = $this->customer();
        $this->legacyToken($customer, 'legacy-token-aaaaaaaaaaaaaaaa');

        $this->migrate();

        $methods = $this->methodsOf($customer);
        self::assertCount(1, $methods);
        self::assertSame($this->module->getId(), $methods[0]->getPaymentModuleId());
        self::assertSame('legacy-token-aaaaaaaaaaaaaaaa', $methods[0]->getToken());
        self::assertSame('PayZen card', $methods[0]->getLabel());
        self::assertNull($methods[0]->getExpiresAt(), 'The old table knew no expiry.');
        self::assertTrue($methods[0]->getIsDefault(), 'A customer has a default method as soon as they have any.');
    }

    public function testASecondPassAddsNothing(): void
    {
        $first = $this->customer();
        $second = $this->customer();
        $this->legacyToken($first, 'legacy-token-bbbbbbbbbbbbbbbb');
        $this->legacyToken($second, 'legacy-token-cccccccccccccccc');

        $this->migrate();
        $afterFirstPass = $this->snapshot([$first, $second]);
        $this->migrate();
        $this->migrate();

        self::assertSame($afterFirstPass, $this->snapshot([$first, $second]), 'The same rows, with the same ids, after every pass.');
        self::assertCount(2, $afterFirstPass);
    }

    public function testACardAlreadyKeptByTheShopIsNotKeptTwiceAndKeepsItsLabelAndItsDefault(): void
    {
        $customer = $this->customer();
        $token = 'legacy-token-dddddddddddddddd';
        $this->legacyToken($customer, $token);
        $already = (new CustomerPaymentMethod())
            ->setCustomerId($customer->getId())
            ->setPaymentModuleId($this->module->getId())
            ->setToken($token)
            ->setLabel('Visa •••• 4242')
            ->setIsDefault(true);
        $already->save($this->getPropelConnection());

        $this->migrate();

        $methods = $this->methodsOf($customer);
        self::assertCount(1, $methods);
        self::assertSame($already->getId(), $methods[0]->getId());
        self::assertSame('Visa •••• 4242', $methods[0]->getLabel());
    }

    public function testACustomerWhoHasADefaultMethodKeepsIt(): void
    {
        $customer = $this->customer();
        $other = ModuleQuery::create()->filterByCode('PayzenEmbedded', Criteria::NOT_EQUAL)->findOne() ?? self::fail('No other module.');
        // The oldest method of the customer is not the default one: the script must not take it for the first card to elect.
        (new CustomerPaymentMethod())
            ->setCustomerId($customer->getId())
            ->setPaymentModuleId($other->getId())
            ->setToken('another-provider-first-token')
            ->setLabel('Amex •••• 0005')
            ->setIsDefault(false)
            ->save($this->getPropelConnection());
        $default = (new CustomerPaymentMethod())
            ->setCustomerId($customer->getId())
            ->setPaymentModuleId($other->getId())
            ->setToken('another-provider-token')
            ->setLabel('Mastercard •••• 1111')
            ->setIsDefault(true);
        $default->save($this->getPropelConnection());
        $this->legacyToken($customer, 'legacy-token-eeeeeeeeeeeeeeee');

        $this->migrate();

        $methods = $this->methodsOf($customer);
        self::assertCount(3, $methods);
        $defaults = array_values(array_filter($methods, static fn (CustomerPaymentMethod $method): bool => $method->getIsDefault()));
        self::assertCount(1, $defaults, 'At most one default per customer.');
        self::assertSame($default->getId(), $defaults[0]->getId());
    }

    public function testAnEmptyTokenAndTheSameTokenTwiceMigrateAtMostOnce(): void
    {
        $empty = $this->customer();
        $twice = $this->customer();
        $this->legacyToken($empty, '');
        $this->legacyToken($twice, 'legacy-token-ffffffffffffffff');
        $this->legacyToken($twice, 'legacy-token-ffffffffffffffff');

        $this->migrate();

        self::assertSame([], $this->methodsOf($empty));
        self::assertCount(1, $this->methodsOf($twice));
    }

    public function testEveryCardOfTheModuleIsTiedToTheNationalContractAndAReplayKeepsTheLinkOfACardOfASaleUnit(): void
    {
        $customer = $this->customer();
        $this->legacyToken($customer, 'legacy-token-hhhhhhhhhhhhhhhh');
        $registeredBefore = (new CustomerPaymentMethod())
            ->setCustomerId($customer->getId())
            ->setPaymentModuleId($this->module->getId())
            ->setToken('registered-by-a-build-with-no-link')
            ->setLabel('Visa •••• 4242')
            ->setIsDefault(false);
        $registeredBefore->save($this->getPropelConnection());

        $this->migrate();

        $links = PayzenEmbeddedStoredMethodContractQuery::create()->find();
        $methods = $this->methodsOf($customer);
        self::assertCount(2, $methods, 'The migrated card and the one already kept.');

        foreach ($methods as $method) {
            $link = PayzenEmbeddedStoredMethodContractQuery::create()->findPk($method->getId());
            self::assertNotNull($link, 'Every card of the module is linked.');
            self::assertNull($link->getSaleUnitId(), 'The cards migrated and the ones kept are of the national contract: the old table never knew the unit.');
        }

        // A card the module registers afterwards on a sale unit keeps its link through a replay of the script.
        $unit = $this->factory->saleUnit();
        $ofTheUnit = (new CustomerPaymentMethod())
            ->setCustomerId($customer->getId())
            ->setPaymentModuleId($this->module->getId())
            ->setToken('registered-on-a-sale-unit-contract')
            ->setLabel('Visa •••• 1111')
            ->setIsDefault(false);
        $ofTheUnit->save($this->getPropelConnection());
        \PayzenEmbedded\Service\StoredMethodContract::link($ofTheUnit, $unit->getId());

        $this->migrate();

        self::assertSame($unit->getId(), PayzenEmbeddedStoredMethodContractQuery::create()->findPk($ofTheUnit->getId())?->getSaleUnitId());
        self::assertCount(\count($links) + 1, PayzenEmbeddedStoredMethodContractQuery::create()->find());
    }

    public function testACardOfAnotherModuleIsNotLinked(): void
    {
        $customer = $this->customer();
        $other = ModuleQuery::create()->filterByCode('PayzenEmbedded', Criteria::NOT_EQUAL)->findOne() ?? self::fail('No other module.');
        $foreign = (new CustomerPaymentMethod())
            ->setCustomerId($customer->getId())
            ->setPaymentModuleId($other->getId())
            ->setToken('another-provider-token')
            ->setLabel('Amex •••• 0005')
            ->setIsDefault(false);
        $foreign->save($this->getPropelConnection());

        $this->migrate();

        self::assertNull(PayzenEmbeddedStoredMethodContractQuery::create()->findPk($foreign->getId()));
    }

    public function testAMigratedCardLeavesTheOldTableAndTheTableStaysInPlace(): void
    {
        $customer = $this->customer();
        $this->legacyToken($customer, 'legacy-token-gggggggggggggggg');

        $this->migrate();

        self::assertCount(1, $this->methodsOf($customer));
        self::assertNull(PayzenEmbeddedCustomerTokenQuery::create()->findOneByCustomerId($customer->getId()), 'The card was moved, not copied.');
    }

    public function testACardTheCustomerForgotIsNotBroughtBackByAReplay(): void
    {
        $customer = $this->customer();
        $this->legacyToken($customer, 'legacy-token-jjjjjjjjjjjjjjjj');
        $this->migrate();
        $this->methodsOf($customer)[0]->delete($this->getPropelConnection());

        $this->migrate();

        self::assertSame([], $this->methodsOf($customer), 'A card the customer forgot stays forgotten.');
    }

    /**
     * PayZen still holds the card of a guest: the row is all that is left to cancel it with, so the script leaves it
     * to LegacyTokenCanceller (the command), which has the platform cancel the card, then drops the row.
     */
    public function testAGuestIsNotMigratedAndItsCardIsCancelledAtThePlatformBeforeItsRowIsDropped(): void
    {
        $guest = $this->factory->guestCustomer($this->factory->customerTitle());
        $this->legacyToken($guest, 'legacy-token-kkkkkkkkkkkkkkkk');

        $this->migrate();

        self::assertSame([], $this->methodsOf($guest));
        self::assertNotNull(PayzenEmbeddedCustomerTokenQuery::create()->findOneByCustomerId($guest->getId()), 'The script leaves the row: the card is still active at PayZen.');

        $gateway = new RecordingTokenGateway();
        $this->cancelWith($gateway);

        self::assertSame(['legacy-token-kkkkkkkkkkkkkkkk'], $gateway->cancelled);
        self::assertNull(PayzenEmbeddedCustomerTokenQuery::create()->findOneByCustomerId($guest->getId()), 'Cancelled: the row goes.');
    }

    public function testAnAnonymizedAccountIsNotMigratedAndItsCardIsCancelledAtThePlatformBeforeItsRowIsDropped(): void
    {
        $customer = $this->customer();
        $this->legacyToken($customer, 'legacy-token-llllllllllllllll');
        \Thelia\Model\CustomerQuery::create()->filterById($customer->getId())->update(['AnonymizedAt' => new \DateTime()], $this->getPropelConnection());

        $this->migrate();

        self::assertSame([], $this->methodsOf($customer));
        self::assertNotNull(PayzenEmbeddedCustomerTokenQuery::create()->findOneByCustomerId($customer->getId()));

        $gateway = new RecordingTokenGateway();
        $this->cancelWith($gateway);

        self::assertSame(['legacy-token-llllllllllllllll'], $gateway->cancelled);
        self::assertNull(PayzenEmbeddedCustomerTokenQuery::create()->findOneByCustomerId($customer->getId()));
    }

    public function testACardThePlatformDidNotCancelKeepsItsRowAndNoTokenIsLogged(): void
    {
        $guest = $this->factory->guestCustomer($this->factory->customerTitle());
        $token = 'legacy-token-nnnnnnnnnnnnnnnn';
        $this->legacyToken($guest, $token);
        $this->migrate();

        $this->cancelWith(new RecordingTokenGateway(new TokenCancellationFailedException('unreachable')));

        self::assertNotNull(PayzenEmbeddedCustomerTokenQuery::create()->findOneByCustomerId($guest->getId()), 'Not cancelled: the row stays, for another run of the command.');
        self::assertStringContainsString(TokenCancellationFailedException::class, RecordingTlogDestination::everythingAbout(), 'The class of the failure is logged.');
        self::assertStringNotContainsString($token, RecordingTlogDestination::everythingAbout());

        $gateway = new RecordingTokenGateway();
        $this->cancelWith($gateway);

        self::assertSame([$token], $gateway->cancelled, 'A later run of the command cancels it.');
        self::assertNull(PayzenEmbeddedCustomerTokenQuery::create()->findOneByCustomerId($guest->getId()));
    }

    public function testACardAlreadyCancelledIsNotAskedAgainAndACustomersCardIsNeverCancelled(): void
    {
        $guest = $this->factory->guestCustomer($this->factory->customerTitle());
        $customer = $this->customer();
        $this->legacyToken($guest, 'legacy-token-oooooooooooooooo');
        $this->legacyToken($customer, 'legacy-token-pppppppppppppppp');
        // PayZen is not registered as a module of the shop: the card of the customer is not copied and its row stays.
        ModuleQuery::create()->filterByCode('PayzenEmbedded')->update(['Code' => 'PayzenEmbeddedElsewhere'], $this->getPropelConnection());
        \Thelia\Model\Map\ModuleTableMap::clearInstancePool();

        try {
            $this->migrate();
        } finally {
            ModuleQuery::create()->filterByCode('PayzenEmbeddedElsewhere')->update(['Code' => 'PayzenEmbedded'], $this->getPropelConnection());
            \Thelia\Model\Map\ModuleTableMap::clearInstancePool();
        }

        $gateway = new RecordingTokenGateway();
        $this->cancelWith($gateway);
        $this->cancelWith($gateway);

        self::assertSame(['legacy-token-oooooooooooooooo'], $gateway->cancelled, 'Once, and only the card of a person the shop holds no identity for.');
        self::assertNotNull(PayzenEmbeddedCustomerTokenQuery::create()->findOneByCustomerId($customer->getId()), 'The card of an account is left for the next pass of the script.');
    }

    public function testACardTheScriptCouldNotCopyIsLeftForTheNextPass(): void
    {
        $customer = $this->customer();
        $this->legacyToken($customer, 'legacy-token-mmmmmmmmmmmmmmmm');
        // PayZen is not registered as a module of the shop: nothing can be copied, and nothing may be lost.
        ModuleQuery::create()->filterByCode('PayzenEmbedded')->update(['Code' => 'PayzenEmbeddedElsewhere'], $this->getPropelConnection());
        \Thelia\Model\Map\ModuleTableMap::clearInstancePool();

        try {
            $this->migrate();
        } finally {
            ModuleQuery::create()->filterByCode('PayzenEmbeddedElsewhere')->update(['Code' => 'PayzenEmbedded'], $this->getPropelConnection());
            \Thelia\Model\Map\ModuleTableMap::clearInstancePool();
        }

        self::assertNotNull(PayzenEmbeddedCustomerTokenQuery::create()->findOneByCustomerId($customer->getId()), 'The row stays: nothing was copied.');
    }

    public function testNoTokenIsLoggedWhileTheScriptRuns(): void
    {
        $customer = $this->customer();
        $this->legacyToken($customer, 'legacy-token-hhhhhhhhhhhhhhhh');

        $this->migrate();

        self::assertCount(1, $this->methodsOf($customer), 'The script did migrate the card.');
        self::assertStringNotContainsString('legacy-token-hhhhhhhhhhhhhhhh', RecordingTlogDestination::everythingAbout());
    }

    public function testTheScriptRunsOnTheUpdateFromTheLastReleaseAndNotAfterThis(): void
    {
        $customer = $this->customer();
        $this->legacyToken($customer, 'legacy-token-iiiiiiiiiiiiiiii');

        (new PayzenEmbedded())->update('3.6.0', '3.7.0', $this->getPropelConnection());

        self::assertSame([], $this->methodsOf($customer), 'A shop already on the version that migrated does not migrate again.');

        (new PayzenEmbedded())->update('3.5.0', '3.6.0', $this->getPropelConnection());

        self::assertCount(1, $this->methodsOf($customer));
    }

    private function legacyToken(Customer $customer, string $token): void
    {
        (new PayzenEmbeddedCustomerToken())
            ->setCustomerId($customer->getId())
            ->setPaymentToken($token)
            ->save($this->getPropelConnection());
    }

    private function cancelWith(StoredTokenGateway $gateway): void
    {
        (new LegacyTokenCanceller($gateway))->cancelAndDrop(50);
    }

    private function migrate(): void
    {
        (new PayzenEmbedded())->update('3.5.0', '3.6.0', $this->getPropelConnection());
    }

    /**
     * @return list<CustomerPaymentMethod>
     */
    private function methodsOf(Customer $customer): array
    {
        \Thelia\Model\Map\CustomerPaymentMethodTableMap::clearInstancePool();

        return array_values(CustomerPaymentMethodQuery::create()->filterByCustomerId($customer->getId())->orderById()->find()->getData());
    }

    /**
     * @param list<Customer> $customers
     *
     * @return list<array{int, int, string, bool}> the id, the customer, the label and the default flag of every method
     */
    private function snapshot(array $customers): array
    {
        $rows = [];

        foreach ($customers as $customer) {
            foreach ($this->methodsOf($customer) as $method) {
                $rows[] = [(int) $method->getId(), (int) $method->getCustomerId(), (string) $method->getLabel(), (bool) $method->getIsDefault()];
            }
        }

        return $rows;
    }
}
