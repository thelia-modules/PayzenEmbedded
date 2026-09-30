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
namespace PayzenEmbedded\LyraClient;
namespace PayzenEmbedded\Tests;

use PayzenEmbedded\LyraClient\LyraPaymentManagementWrapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\LockInterface;
use Thelia\Log\Tlog;
use Thelia\Model\Order;

/**
 * The order is given back once the operation is over: a store that fails to release the lock
 * never replaces the operation's own result, it is logged and the lock expires on its own.
 */
final class OrderLockReleaseTest extends TestCase
{
    public function testAReleaseTheStoreRefusesIsLoggedNotThrown(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->expects(self::once())->method('release')->willThrowException(new LockReleasingException('store down'));

        $log = $this->createMock(Tlog::class);
        $log->expects(self::once())->method('addError')->with(self::stringContains(LockReleasingException::class));

        $this->release($this->wrapperLoggingTo($log), $lock);
    }

    public function testAReleaseThatWorksLogsNothing(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->expects(self::once())->method('release');

        $log = $this->createMock(Tlog::class);
        $log->expects(self::never())->method('addError');

        $this->release($this->wrapperLoggingTo($log), $lock);
    }

    /**
     * The wrapper reads the module configuration when built: the release needs none of it.
     */
    private function wrapperLoggingTo(Tlog $log): LyraPaymentManagementWrapper
    {
        $wrapper = (new \ReflectionClass(LyraPaymentManagementWrapper::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(LyraPaymentManagementWrapper::class, 'log'))->setValue($wrapper, $log);

        return $wrapper;
    }

    private function release(LyraPaymentManagementWrapper $wrapper, LockInterface $lock): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(12);

        (new \ReflectionMethod(LyraPaymentManagementWrapper::class, 'releaseOrderLock'))->invoke($wrapper, $lock, $order);
    }
}
