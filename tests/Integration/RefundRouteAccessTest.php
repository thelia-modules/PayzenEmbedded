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

use PayzenEmbedded\Model\PayzenEmbeddedTransactionHistory;
use PayzenEmbedded\PayzenEmbedded;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Model\Admin;
use Thelia\Model\ModuleQuery;
use Thelia\Model\ProfileModule;
use Thelia\Model\SaleUnitQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The refund route the module adds to the order sheet gives money back: it answers the sale units of
 * the administrator and the refund right of the core, as the rest of the back office does, and the
 * order sheet offers the form only to the administrators the route lets through.
 */
final class RefundRouteAccessTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        // A page rendered by an earlier test leaves its parser current, and the hooks of the next
        // Twig page would be rendered with it: the PayZen block would be missing.
        (new \ReflectionProperty(ParserResolver::class, 'currentParser'))->setValue(null, null);

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

    public function testRefundingATransactionNeedsTheRefundRight(): void
    {
        $this->administrator([
            AdminResources::MODULE => [AccessManager::UPDATE],
            AdminResources::ORDER => [AccessManager::VIEW, AccessManager::UPDATE],
        ]);
        $order = $this->factory->order(null, ['paymentModuleCode' => PayzenEmbedded::getModuleCode()]);

        $this->client->request('POST', '/admin/module/payzen-embedded/refund-transaction/'.$order->getId());

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testWithTheRefundRightTheRouteLetsTheAdministratorThrough(): void
    {
        $this->administrator([
            AdminResources::MODULE => [AccessManager::UPDATE],
            AdminResources::ORDER => [AccessManager::VIEW, AccessManager::UPDATE],
            AdminResources::ORDER_PAYMENT_REFUND => [AccessManager::CREATE],
        ]);
        $order = $this->factory->order(null, ['paymentModuleCode' => PayzenEmbedded::getModuleCode()]);

        $this->client->request('POST', '/admin/module/payzen-embedded/refund-transaction/'.$order->getId());

        self::assertNotSame(403, $this->client->getResponse()->getStatusCode());
        self::assertNotSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testAnOrderOfAnotherSaleUnitIsOutOfReachBeforeTheRefundRightIsAsked(): void
    {
        // 404, not 403: the administrator learns nothing of an order outside their sale units.
        $north = $this->factory->saleUnit();
        $south = $this->factory->saleUnit();
        $admin = $this->administrator([
            AdminResources::MODULE => [AccessManager::UPDATE],
            AdminResources::ORDER => [AccessManager::VIEW, AccessManager::UPDATE],
        ]);
        $this->factory->adminSaleUnit($admin, $north);
        $order = $this->factory->order(null, ['paymentModuleCode' => PayzenEmbedded::getModuleCode()]);
        $order->setSaleUnitId($south->getId())->save($this->getPropelConnection());

        $this->client->request('POST', '/admin/module/payzen-embedded/refund-transaction/'.$order->getId());

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testAnOrderOfAnotherSaleUnitIsOutOfReachEvenWithTheRefundRight(): void
    {
        $north = $this->factory->saleUnit();
        $south = $this->factory->saleUnit();
        $admin = $this->administrator([
            AdminResources::MODULE => [AccessManager::UPDATE],
            AdminResources::ORDER => [AccessManager::VIEW, AccessManager::UPDATE],
            AdminResources::ORDER_PAYMENT_REFUND => [AccessManager::CREATE],
        ]);
        $this->factory->adminSaleUnit($admin, $north);
        $order = $this->factory->order(null, ['paymentModuleCode' => PayzenEmbedded::getModuleCode()]);
        $order->setSaleUnitId($south->getId())->save($this->getPropelConnection());

        $this->client->request('POST', '/admin/module/payzen-embedded/refund-transaction/'.$order->getId());

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @return iterable<string, array{array<string, list<string>>, int}>
     */
    public static function refundGrants(): iterable
    {
        yield 'with the refund right' => [[AdminResources::ORDER_PAYMENT_REFUND => [AccessManager::CREATE]], 1];
        yield 'without it' => [[], 0];
    }

    /**
     * @param array<string, list<string>> $extraGrants
     */
    #[DataProvider('refundGrants')]
    public function testTheOrderSheetOffersTheRefundToTheAdministratorsTheRouteLetsThrough(array $extraGrants, int $expectedForms): void
    {
        $this->administrator([
            AdminResources::MODULE => [AccessManager::UPDATE],
            AdminResources::ORDER => [AccessManager::VIEW, AccessManager::UPDATE],
        ] + $extraGrants);
        $order = $this->factory->order(null, ['paymentModuleCode' => PayzenEmbedded::getModuleCode()]);
        $order->setTransactionRef('uuid-'.$order->getId())->save($this->getPropelConnection());
        (new PayzenEmbeddedTransactionHistory())
            ->setCustomerId((int) $order->getCustomerId())
            ->setOrderId((int) $order->getId())
            ->setUuid('uuid-'.$order->getId())
            ->setStatus('PAID')
            ->setDetailedstatus('CAPTURED')
            ->setOperationtype('DEBIT')
            ->setAmount(10000)
            ->setCurrencyId((int) $order->getCurrencyId())
            ->setCreationdate(new \DateTime('-1 day'))
            ->setFinished(true)
            ->save($this->getPropelConnection());

        $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('form[action$="/refresh-transaction/'.$order->getId().'"]'), 'The PayZen block is rendered.');
        self::assertCount($expectedForms, $crawler->filter('form[action$="/refund-transaction/'.$order->getId().'"]'));
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
