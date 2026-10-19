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

use Thelia\Log\AbstractTlogDestination;

/**
 * Keeps the log lines in memory, so that a test can read what the module wrote.
 */
final class RecordingTlogDestination extends AbstractTlogDestination
{
    /** @var list<string> */
    public static array $lines = [];

    public function add($string): void
    {
        self::$lines[] = (string) $string;
    }

    public function write(&$res): void
    {
    }

    public function getTitle(): string
    {
        return 'Recording';
    }

    public function getDescription(): string
    {
        return 'Keeps the log lines in memory (tests).';
    }

    public static function everythingAbout(): string
    {
        return implode("\n", self::$lines);
    }
}
