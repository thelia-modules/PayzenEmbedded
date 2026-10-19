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

use PayzenEmbedded\Command\CancelLegacyTokensCommand;
use PayzenEmbedded\LyraClient\LyraTokenGateway;
use PayzenEmbedded\LyraClient\TokenCancellationFailedException;
use PayzenEmbedded\Model\PayzenEmbeddedCustomerToken;
use PayzenEmbedded\Model\PayzenEmbeddedCustomerTokenQuery;
use PayzenEmbedded\PayzenEmbedded;
use PayzenEmbedded\Service\LegacyTokenCanceller;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Thelia\Model\Customer;

/**
 * The cards of guests and anonymized accounts that the update left in the old table are cancelled by a command, a
 * batch at a time: the update itself only counts them, as it runs while the module is deactivated and a call to the
 * platform may wait for its timeout.
 */
final class LegacyTokenCommandTest extends PayzenIntegrationTestCase
{
    /**
     * No container, no gateway: the module the update builds by hand counts, and has nothing to ask the platform with.
     * The site is left unset, so that a call, if the update made one, would fail before leaving and be logged.
     */
    public function testTheUpdateCallsNoPlatformAndSaysHowManyCardsWait(): void
    {
        $this->setModuleConfig('site_id', '');
        $this->guestWithAToken('legacy-token-qqqqqqqqqqqqqqqq');
        $this->guestWithAToken('legacy-token-rrrrrrrrrrrrrrrr');

        (new PayzenEmbedded())->postUpdate('3.5.0', '3.6.0');

        self::assertSame(2, PayzenEmbeddedCustomerTokenQuery::create()->count($this->getPropelConnection()), 'No row is dropped by the update.');
        $log = RecordingTlogDestination::everythingAbout();
        self::assertStringContainsString('2 card(s)', $log, 'The count is logged.');
        self::assertStringContainsString('payzen-embedded:legacy-tokens:cancel', $log, 'And the command to run.');
        self::assertStringNotContainsString('not cancelled', $log, 'The update tried to cancel nothing.');
        self::assertStringNotContainsString('legacy-token-', $log);
    }

    public function testTheUpdateSaysNothingWhenNoCardWaits(): void
    {
        (new PayzenEmbedded())->postUpdate('3.5.0', '3.6.0');

        self::assertStringNotContainsString('payzen-embedded:legacy-tokens:cancel', RecordingTlogDestination::everythingAbout());
    }

    public function testTheCommandStopsAtTheLimitAndLeavesTheRest(): void
    {
        $tokens = ['legacy-token-ssssssssssssssss', 'legacy-token-tttttttttttttttt', 'legacy-token-uuuuuuuuuuuuuuuu'];
        foreach ($tokens as $token) {
            $this->guestWithAToken($token);
        }
        $gateway = new RecordingTokenGateway();

        [$code, $display] = $this->runCommand($gateway, ['--limit' => '2']);

        self::assertSame(Command::SUCCESS, $code);
        self::assertSame([$tokens[0], $tokens[1]], $gateway->cancelled, 'Two cards asked, the oldest rows first.');
        self::assertStringContainsString('Cancelled: 2', $display);
        self::assertStringContainsString('Failed: 0', $display);
        self::assertStringContainsString('Remaining: 1', $display);
        self::assertStringContainsString('Remaining after row', $display);
        self::assertSame(1, PayzenEmbeddedCustomerTokenQuery::create()->count($this->getPropelConnection()), 'The third row is left.');

        [, $second] = $this->runCommand($gateway, ['--limit' => '2']);

        self::assertSame($tokens, $gateway->cancelled, 'A second run goes on where the first stopped.');
        self::assertStringContainsString('Remaining: 0', $second);
        self::assertSame(0, PayzenEmbeddedCustomerTokenQuery::create()->count($this->getPropelConnection()));
    }

    /**
     * A card PayZen keeps refusing keeps its row, so "Remaining" never reaches zero: the output says how many rows lie
     * after the last one tried, and the command to run next.
     */
    public function testAFailureSaysWhatLiesAfterTheLastRowAndTheCommandToRunNext(): void
    {
        $first = PayzenEmbeddedCustomerTokenQuery::create()->findOneByCustomerId($this->guestWithAToken('legacy-token-1111111111111111')->getId());
        $this->guestWithAToken('legacy-token-2222222222222222');
        $this->guestWithAToken('legacy-token-3333333333333333');
        $gateway = $this->refusing('legacy-token-1111111111111111');

        [$code, $display] = $this->runCommand($gateway, ['--limit' => '1']);

        self::assertSame(Command::FAILURE, $code);
        self::assertStringContainsString('Remaining: 3 (the cards PayZen refused included)', $display);
        self::assertStringContainsString(sprintf('Remaining after row %d: 2', $first->getId()), $display);
        self::assertStringContainsString(sprintf('Next run: php Thelia payzen-embedded:legacy-tokens:cancel --limit 1 --after-id %d', $first->getId()), $display);

        // Following the advice, up to the end: what stays is the refused card alone.
        [, $last] = $this->runCommand($gateway, ['--limit' => '5', '--after-id' => (string) $first->getId()]);

        self::assertStringContainsString('Remaining: 1', $last);
        self::assertStringContainsString('Remaining after row', $last);
        self::assertStringNotContainsString('Next run', $last, 'Nothing failed this time, nothing to advise.');
    }

    public function testWhenTheRefusedCardIsTheLastNoNextRunIsAdvised(): void
    {
        $this->guestWithAToken('legacy-token-4444444444444444');

        [, $display] = $this->runCommand($this->refusing('legacy-token-4444444444444444'), []);

        self::assertStringContainsString('Remaining: 1', $display);
        self::assertStringContainsString(': 0', substr($display, (int) strpos($display, 'Remaining after row')));
        self::assertStringNotContainsString('Next run', $display);
        self::assertStringContainsString('No row after the last one tried', $display);
    }

    public function testTheDefaultLimitIsFifty(): void
    {
        self::assertSame(50, CancelLegacyTokensCommand::DEFAULT_LIMIT);
        self::assertSame('50', (new CancelLegacyTokensCommand(new LegacyTokenCanceller(new RecordingTokenGateway())))->getDefinition()->getOption('limit')->getDefault());
    }

    public function testACardThePlatformRefusesKeepsItsRowAndNoTokenIsPrinted(): void
    {
        $token = 'legacy-token-vvvvvvvvvvvvvvvv';
        $guest = $this->guestWithAToken($token);

        [$code, $display] = $this->runCommand(new RecordingTokenGateway(new TokenCancellationFailedException('unreachable')), []);

        self::assertSame(Command::FAILURE, $code);
        self::assertStringContainsString('Failed: 1', $display);
        self::assertStringContainsString('Remaining: 1', $display);
        self::assertNotNull(PayzenEmbeddedCustomerTokenQuery::create()->findOneByCustomerId($guest->getId()), 'The row stays.');
        self::assertStringNotContainsString($token, $display, 'No token in the output.');
        self::assertStringNotContainsString($token, RecordingTlogDestination::everythingAbout(), 'Nor in the log.');
        self::assertStringContainsString(TokenCancellationFailedException::class, RecordingTlogDestination::everythingAbout());
    }

    public function testAfterIdGetsPastACardThePlatformKeepsRefusing(): void
    {
        $refusedRow = PayzenEmbeddedCustomerTokenQuery::create()->findOneByCustomerId($this->guestWithAToken('legacy-token-wwwwwwwwwwwwwwww')->getId());
        $this->guestWithAToken('legacy-token-xxxxxxxxxxxxxxxx');
        $gateway = $this->refusing('legacy-token-wwwwwwwwwwwwwwww');

        $this->runCommand($gateway, ['--limit' => '1']);
        self::assertSame([], $gateway->cancelled, 'The refused card took the place of the other.');

        [, $display] = $this->runCommand($gateway, ['--limit' => '1']);
        self::assertStringContainsString('Last row: ' . $refusedRow->getId(), $display, 'The run says where it stopped.');

        $this->runCommand($gateway, ['--limit' => '1', '--after-id' => (string) $refusedRow->getId()]);
        self::assertSame(['legacy-token-xxxxxxxxxxxxxxxx'], $gateway->cancelled, 'Starting after the refused row reaches the next card.');
    }

    public function testTheCommandRefusesALimitThatIsNotAPositiveNumber(): void
    {
        $this->guestWithAToken('legacy-token-yyyyyyyyyyyyyyyy');
        $gateway = new RecordingTokenGateway();

        foreach (['0', '-1', 'many'] as $limit) {
            [$code] = $this->runCommand($gateway, ['--limit' => $limit]);
            self::assertSame(Command::INVALID, $code, "--limit $limit");
        }

        self::assertSame([], $gateway->cancelled);
    }

    public function testTheGatewayAsksThePlatformWithShortTimeouts(): void
    {
        $gateway = new class() extends LyraTokenGateway {
            public function post($target, $array)
            {
                return ['status' => 'SUCCESS'];
            }
        };

        $gateway->cancel('legacy-token-zzzzzzzzzzzzzzzz');

        $read = static fn (string $property): mixed => (new \ReflectionProperty(\Lyra\Client::class, $property))->getValue($gateway);
        self::assertSame(LyraTokenGateway::CONNECTION_TIMEOUT, $read('_connectionTimeout'));
        self::assertSame(LyraTokenGateway::TIMEOUT, $read('_timeout'));
        self::assertLessThanOrEqual(10, LyraTokenGateway::TIMEOUT);
    }

    /**
     * @param array<string, string> $options
     *
     * @return array{int, string}
     */
    private function runCommand(\PayzenEmbedded\LyraClient\StoredTokenGateway $gateway, array $options): array
    {
        $tester = new CommandTester(new CancelLegacyTokensCommand(new LegacyTokenCanceller($gateway)));
        $code = $tester->execute($options);

        return [$code, $tester->getDisplay()];
    }

    /**
     * A platform that refuses one card and cancels the others.
     */
    private function refusing(string $refusedToken): object
    {
        return new class($refusedToken) implements \PayzenEmbedded\LyraClient\StoredTokenGateway {
            /** @var list<string> */
            public array $cancelled = [];

            public function __construct(private readonly string $refused)
            {
            }

            public function cancel(string $token): void
            {
                if ($this->refused === $token) {
                    throw new TokenCancellationFailedException('refused');
                }

                $this->cancelled[] = $token;
            }
        };
    }

    private function guestWithAToken(string $token): Customer
    {
        $guest = $this->factory->guestCustomer($this->factory->customerTitle());
        (new PayzenEmbeddedCustomerToken())->setCustomerId($guest->getId())->setPaymentToken($token)->save($this->getPropelConnection());

        return $guest;
    }
}
