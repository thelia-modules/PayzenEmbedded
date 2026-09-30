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

namespace PayzenEmbedded\Tests;

use PayzenEmbedded\LyraClient\LyraClientWrapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the platform says is cut to the column that keeps it: the history records every transaction
 * the platform lists, and a value too long for its column is refused with the row on a strict server.
 */
final class HistoryColumnBoundsTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, int, string|null}>
     */
    public static function values(): iterable
    {
        yield 'a status that fits' => ['PAID', 32, 'PAID'];
        yield 'the longest detailed status known' => ['WAITING_AUTHORISATION_TO_VALIDATE', 64, 'WAITING_AUTHORISATION_TO_VALIDATE'];
        yield 'a message longer than its column' => [str_repeat('é', 300), 255, str_repeat('é', 255)];
        yield 'a code longer than its column' => ['PSP_0123456789', 10, 'PSP_012345'];
        yield 'nothing said' => [null, 10, null];
        yield 'an empty value' => ['', 10, null];
        yield 'a number' => [5, 10, '5'];
        yield 'not a value' => [['x'], 10, null];
    }

    #[DataProvider('values')]
    public function testAValueIsCutToItsColumn(mixed $value, int $length, ?string $recorded): void
    {
        self::assertSame($recorded, (new \ReflectionMethod(LyraClientWrapper::class, 'bounded'))->invoke(null, $value, $length));
    }

    /**
     * The bound of each column is the one of the schema: a schema widened without the code, or the
     * other way round, would refuse or cut the row again.
     */
    public function testTheBoundsAreTheColumnsOfTheSchema(): void
    {
        $schema = simplexml_load_file(__DIR__ . '/../Config/schema.xml');
        self::assertNotFalse($schema);

        $sizes = [];
        foreach ($schema->xpath('//table[@name="payzen_embedded_transaction_history"]/column[@type="VARCHAR"]') as $column) {
            $sizes[(string) $column['name']] = (int) $column['size'];
        }

        $code = (string) file_get_contents(__DIR__ . '/../LyraClient/LyraClientWrapper.php');
        preg_match_all('/->set(\w+)\(self::bounded\([^;]*?, (\d+)\)\)/', $code, $calls, PREG_SET_ORDER);
        self::assertNotEmpty($calls);

        $byLowerName = array_change_key_case($sizes);
        foreach ($calls as [, $setter, $bound]) {
            self::assertArrayHasKey(strtolower($setter), $byLowerName, $setter . ' writes no VARCHAR column of the schema');
            self::assertSame($byLowerName[strtolower($setter)], (int) $bound, $setter . ' is not cut to its column');
        }
    }
}
