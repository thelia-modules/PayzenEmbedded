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
use PayzenEmbedded\Model\PayzenEmbeddedTransactionHistory;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Domain\Payment\Enum\PaymentTransactionState;
use Thelia\Domain\Payment\Service\PaymentTransactionRecorder;
use Thelia\Model\Admin;
use Thelia\Model\Order;
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
            AdminResources::ORDER_PAYMENT_REFUND => [AccessManager::CREATE],
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

    public function testMovingTheCaptureDateOfAnAuthorizationNeedsNoCaptureRight(): void
    {
        // Authorizing first, the form only moves the capture date: the capture itself is made
        // from Thelia's dialog, under its right.
        $this->administrator([
            AdminResources::MODULE => [AccessManager::UPDATE],
            AdminResources::ORDER => [AccessManager::VIEW, AccessManager::UPDATE],
        ]);
        $order = $this->factory->order(null, ['paymentModuleCode' => PayzenEmbedded::getModuleCode()]);
        $previous = PayzenEmbedded::getConfigValue('validation_mode', '');
        PayzenEmbedded::setConfigValue('validation_mode', 'YES');

        try {
            $this->client->request('POST', '/admin/module/payzen-embedded/update-transaction/'.$order->getId());
        } finally {
            PayzenEmbedded::setConfigValue('validation_mode', $previous);
        }

        self::assertNotSame(403, $this->client->getResponse()->getStatusCode());
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

    public function testCancellingAnAuthorizationFromTheModuleNeedsTheCaptureRight(): void
    {
        // Authorizing first, giving a payment the bank has not taken back cancels the
        // authorization Thelia's journal holds: a release, under its capture right.
        $this->administrator([
            AdminResources::MODULE => [AccessManager::UPDATE],
            AdminResources::ORDER => [AccessManager::VIEW, AccessManager::UPDATE],
            AdminResources::ORDER_PAYMENT_REFUND => [AccessManager::CREATE],
        ]);
        $order = $this->authorizedOrderAtPayzen();

        $this->whileAuthorizingFirst(fn () => $this->client->request('POST', '/admin/module/payzen-embedded/refund-transaction/'.$order->getId()));

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @return iterable<string, array{array<string, list<string>>, int}>
     */
    public static function cancellationGrants(): iterable
    {
        yield 'with the capture right' => [[AdminResources::ORDER_PAYMENT_CAPTURE => [AccessManager::CREATE]], 1];
        yield 'without it' => [[], 0];
    }

    /**
     * @param array<string, list<string>> $extraGrants
     */
    #[DataProvider('cancellationGrants')]
    public function testTheOrderSheetOffersTheCancellationOfAnAuthorizationUnderTheCaptureRight(array $extraGrants, int $expectedForms): void
    {
        $this->administrator([
            AdminResources::MODULE => [AccessManager::UPDATE],
            AdminResources::ORDER => [AccessManager::VIEW, AccessManager::UPDATE],
            AdminResources::ORDER_PAYMENT_REFUND => [AccessManager::CREATE],
        ] + $extraGrants);
        $order = $this->authorizedOrderAtPayzen();

        $crawler = $this->whileAuthorizingFirst(fn () => $this->client->request('GET', '/admin/order/update/'.$order->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('form[action$="/refresh-transaction/'.$order->getId().'"]'), 'The PayZen block is rendered.');
        self::assertCount($expectedForms, $crawler->filter('form[action$="/refund-transaction/'.$order->getId().'"]'));
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function journals(): iterable
    {
        yield 'the journal holds the capture' => [true, 'hidden'];
        yield 'it does not' => [false, 'text'];
    }

    #[DataProvider('journals')]
    public function testTakingThePaymentAtOnceTheAmountIsTheJournalsOnceItHoldsTheCapture(bool $captureInTheJournal, string $amountInput): void
    {
        $this->administrator([
            AdminResources::MODULE => [AccessManager::UPDATE],
            AdminResources::ORDER => [AccessManager::VIEW, AccessManager::UPDATE],
            AdminResources::ORDER_PAYMENT_CAPTURE => [AccessManager::CREATE],
        ]);
        $order = $this->factory->order(null, ['paymentModuleCode' => PayzenEmbedded::getModuleCode()]);
        $uuid = 'uuid-'.$order->getId();
        $order->setTransactionRef($uuid)->save($this->getPropelConnection());
        (new PayzenEmbeddedTransactionHistory())
            ->setCustomerId((int) $order->getCustomerId())
            ->setOrderId((int) $order->getId())
            ->setUuid($uuid)
            ->setStatus('PAID')
            ->setDetailedstatus('AUTHORISED')
            ->setOperationtype('DEBIT')
            ->setAmount(10000)
            ->setCurrencyId((int) $order->getCurrencyId())
            ->setCreationdate(new \DateTime('-1 day'))
            ->setFinished(false)
            ->save($this->getPropelConnection());

        if ($captureInTheJournal) {
            $this->getService(PaymentTransactionRecorder::class)->recordCapture($order, 100, $uuid, moduleCode: PayzenEmbedded::getModuleCode());
        }

        $previous = PayzenEmbedded::getConfigValue('validation_mode', '');
        PayzenEmbedded::setConfigValue('validation_mode', 'NO');

        try {
            $crawler = $this->client->request('GET', '/admin/order/update/'.$order->getId());
        } finally {
            PayzenEmbedded::setConfigValue('validation_mode', $previous);
        }

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('form[action$="/update-transaction/'.$order->getId().'"] input[name$="[amount]"][type="'.$amountInput.'"]'));
    }

    public function testAnOrderAuthorizedFirstStaysSoOnceTheModeChanged(): void
    {
        // The journal holds the authorization: cancelling it is a release, whatever the
        // configuration became since.
        $this->administrator([
            AdminResources::MODULE => [AccessManager::UPDATE],
            AdminResources::ORDER => [AccessManager::VIEW, AccessManager::UPDATE],
        ]);
        $order = $this->authorizedOrderAtPayzen();
        $this->getService(PaymentTransactionRecorder::class)->recordAuthorization($order, 100, 'uuid-'.$order->getId(), moduleCode: PayzenEmbedded::getModuleCode());
        $previous = PayzenEmbedded::getConfigValue('validation_mode', '');
        PayzenEmbedded::setConfigValue('validation_mode', 'NO');

        try {
            $this->client->request('POST', '/admin/module/payzen-embedded/refund-transaction/'.$order->getId());
        } finally {
            PayzenEmbedded::setConfigValue('validation_mode', $previous);
        }

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAuthorizingFirstTheSheetOffersNoDateChangeOnceACaptureWasAsked(): void
    {
        $this->administrator([
            AdminResources::MODULE => [AccessManager::UPDATE],
            AdminResources::ORDER => [AccessManager::VIEW, AccessManager::UPDATE],
        ]);
        $order = $this->authorizedOrderAtPayzen();
        $recorder = $this->getService(PaymentTransactionRecorder::class);
        $recorder->recordAuthorization($order, 100, 'uuid-'.$order->getId(), moduleCode: PayzenEmbedded::getModuleCode());
        $recorder->recordCapture($order, 40, 'uuid-'.$order->getId(), PaymentTransactionState::PENDING, moduleCode: PayzenEmbedded::getModuleCode());

        $crawler = $this->whileAuthorizingFirst(fn () => $this->client->request('GET', '/admin/order/update/'.$order->getId()));

        self::assertCount(1, $crawler->filter('form[action$="/refresh-transaction/'.$order->getId().'"]'), 'The PayZen block is rendered.');
        self::assertCount(0, $crawler->filter('form[action$="/update-transaction/'.$order->getId().'"]'));
    }

    public function testTheOrderSheetOffersNoCancellationThatWouldCancelACaptureAsked(): void
    {
        $this->administrator([
            AdminResources::MODULE => [AccessManager::UPDATE],
            AdminResources::ORDER => [AccessManager::VIEW, AccessManager::UPDATE],
            AdminResources::ORDER_PAYMENT_CAPTURE => [AccessManager::CREATE],
        ]);
        $order = $this->authorizedOrderAtPayzen();
        $recorder = $this->getService(PaymentTransactionRecorder::class);
        $recorder->recordAuthorization($order, 100, 'uuid-'.$order->getId(), moduleCode: PayzenEmbedded::getModuleCode());
        $recorder->recordCapture($order, 40, 'uuid-'.$order->getId(), PaymentTransactionState::PENDING, moduleCode: PayzenEmbedded::getModuleCode());

        $crawler = $this->whileAuthorizingFirst(fn () => $this->client->request('GET', '/admin/order/update/'.$order->getId()));

        self::assertCount(1, $crawler->filter('form[action$="/refresh-transaction/'.$order->getId().'"]'), 'The PayZen block is rendered.');
        self::assertCount(0, $crawler->filter('form[action$="/refund-transaction/'.$order->getId().'"]'));
    }

    private function authorizedOrderAtPayzen(): Order
    {
        $order = $this->factory->order(null, ['paymentModuleCode' => PayzenEmbedded::getModuleCode()]);
        $order->setTransactionRef('uuid-'.$order->getId())->save($this->getPropelConnection());
        (new PayzenEmbeddedTransactionHistory())
            ->setCustomerId((int) $order->getCustomerId())
            ->setOrderId((int) $order->getId())
            ->setUuid('uuid-'.$order->getId())
            ->setStatus('RUNNING')
            ->setDetailedstatus('AUTHORISED_TO_VALIDATE')
            ->setOperationtype('DEBIT')
            ->setAmount(10000)
            ->setCurrencyId((int) $order->getCurrencyId())
            ->setCreationdate(new \DateTime('-1 day'))
            ->setFinished(false)
            ->save($this->getPropelConnection());

        return $order;
    }

    /**
     * @template T
     *
     * @param callable(): T $request
     *
     * @return T
     */
    private function whileAuthorizingFirst(callable $request): mixed
    {
        $previous = PayzenEmbedded::getConfigValue('validation_mode', '');
        PayzenEmbedded::setConfigValue('validation_mode', 'YES');

        try {
            return $request();
        } finally {
            PayzenEmbedded::setConfigValue('validation_mode', $previous);
        }
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
