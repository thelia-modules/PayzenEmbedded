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

namespace PayzenEmbedded\Command;

use PayzenEmbedded\Service\LegacyTokenCanceller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Has PayZen cancel the cards of guests and anonymized accounts that the update to 3.6.0 left in the old table,
 * a batch at a time (see LegacyTokenCanceller). Replayable. "Remaining" counts the cards PayZen refused too, which keep
 * their row: it reaches zero only when none is refused. A cron goes forward with --after-id (the "Last row" of the
 * previous run), and is done when no row is left after the last one tried.
 */
#[AsCommand(
    name: 'payzen-embedded:legacy-tokens:cancel',
    description: 'Cancels at PayZen the cards of guests and anonymized accounts left in the old token table, then drops their rows.',
)]
class CancelLegacyTokensCommand extends Command
{
    public const DEFAULT_LIMIT = 50;

    public function __construct(
        private readonly LegacyTokenCanceller $canceller,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'The most cards to ask PayZen to cancel in this run.', (string) self::DEFAULT_LIMIT)
            ->addOption('after-id', null, InputOption::VALUE_REQUIRED, 'Start after this row of the old table: the "last row" of a previous run, to get past a card PayZen keeps refusing.', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), \FILTER_VALIDATE_INT);
        $afterId = filter_var($input->getOption('after-id'), \FILTER_VALIDATE_INT);

        if (false === $limit || $limit < 1 || false === $afterId || $afterId < 0) {
            $output->writeln('<error>--limit must be a positive integer and --after-id a positive integer or zero.</error>');

            return Command::INVALID;
        }

        $result = $this->canceller->cancelAndDrop($limit, $afterId);

        $output->writeln(sprintf('Cancelled: %d', $result['cancelled']));
        $output->writeln(sprintf('Failed: %d', $result['failed']));
        $output->writeln(sprintf('Remaining: %d (the cards PayZen refused included)', $result['remaining']));
        $output->writeln(sprintf('Last row: %d', $result['lastId']));
        $output->writeln(sprintf('Remaining after row %d: %d', $result['lastId'], $result['beyond']));

        if ($result['failed'] > 0) {
            $output->writeln('<comment>The cards that failed keep their row, see the log (the class of the failure, never the card).</comment>');

            $output->writeln(
                $result['beyond'] > 0
                    ? sprintf('Next run: php Thelia payzen-embedded:legacy-tokens:cancel --limit %d --after-id %d', $limit, $result['lastId'])
                    : 'No row after the last one tried: what remains are the cards PayZen refused.'
            );
        }

        return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
