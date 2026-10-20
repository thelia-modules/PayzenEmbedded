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

use PHPUnit\Framework\Attributes\DataProvider;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Admin;
use Thelia\Model\ModuleQuery;
use Thelia\Model\ProfileModule;
use Thelia\Model\SaleUnitQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The routes the module adds to the order sheet act on the money of an order: they answer the
 * sale units of the administrator and the payment rights of the core, as the rest of the back
 * office does. Boots Thelia, see phpunit.integration.xml.dist.
 */
final class OrderEditAccessTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());

        // The suite may run on a shop that already declares sale units.
        SaleUnitQuery::create()->find($this->getPropelConnection())->delete($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function routes(): iterable
    {
        yield 'update' => ['update-transaction'];
        yield 'refund' => ['refund-transaction'];
        yield 'refresh' => ['refresh-transaction'];
    }

    #[DataProvider('routes')]
    public function testAnOrderOfAnotherSaleUnitIsOutOfReach(string $route): void
    {
        $north = $this->factory->saleUnit();
        $south = $this->factory->saleUnit();
        $admin = $this->administrator([
            AdminResources::MODULE => [AccessManager::UPDATE],
            AdminResources::ORDER => [AccessManager::VIEW, AccessManager::UPDATE],
            AdminResources::ORDER_PAYMENT_CAPTURE => [AccessManager::CREATE],
        ]);
        $this->factory->adminSaleUnit($admin, $north);
        $order = $this->factory->order(null, ['paymentModuleCode' => PayzenEmbedded::getModuleCode()]);
        $order->setSaleUnitId($south->getId())->save($this->getPropelConnection());

        $this->client->request('POST', '/admin/module/payzen-embedded/'.$route.'/'.$order->getId());

        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'An order of another sale unit is no order of the administrator.');
    }

    public function testUpdatingATransactionNeedsTheCaptureRight(): void
    {
        $this->administrator([
            AdminResources::MODULE => [AccessManager::UPDATE],
            AdminResources::ORDER => [AccessManager::VIEW, AccessManager::UPDATE],
        ]);
        $order = $this->factory->order(null, ['paymentModuleCode' => PayzenEmbedded::getModuleCode()]);

        $this->client->request('POST', '/admin/module/payzen-embedded/update-transaction/'.$order->getId());

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @param array<string, list<string>> $grants
     */
    private function administrator(array $grants): Admin
    {
        $admin = $this->factory->restrictedAdmin($grants);
        $module = ModuleQuery::create()->findOneByCode(PayzenEmbedded::getModuleCode(), $this->getPropelConnection());
        self::assertNotNull($module, 'The module is installed in the shop running the tests.');

        $access = new AccessManager(0);
        $access->build([AccessManager::VIEW, AccessManager::UPDATE]);
        (new ProfileModule())
            ->setProfileId((int) $admin->getProfileId())
            ->setModuleId((int) $module->getId())
            ->setAccess($access->getAccessValue())
            ->save($this->getPropelConnection());

        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        return $admin;
    }
}
