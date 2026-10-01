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

use PayzenEmbedded\LyraClient\HistoryColumn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the platform says is cut to the column that keeps it: the history records every transaction
 * the platform lists, and a value too long for its column is refused with the row on a strict server.
 */
final class HistoryColumnBoundsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, mixed, string|null}>
     */
    public static function values(): iterable
    {
        yield 'a status that fits' => ['status', 'PAID', 'PAID'];
        yield 'the longest detailed status known' => ['detailedStatus', 'WAITING_AUTHORISATION_TO_VALIDATE', 'WAITING_AUTHORISATION_TO_VALIDATE'];
        yield 'a message longer than its column' => ['errorMessage', str_repeat('é', 300), str_repeat('é', 255)];
        yield 'a code longer than its column' => ['errorCode', 'PSP_0123456789', 'PSP_012345'];
        yield 'nothing said' => ['errorCode', null, null];
        yield 'an empty value' => ['errorCode', '', null];
        yield 'a number' => ['errorCode', 5, '5'];
        yield 'not a value' => ['errorCode', ['x'], null];
    }

    #[DataProvider('values')]
    public function testAValueIsCutToItsColumn(string $column, mixed $value, ?string $recorded): void
    {
        self::assertSame($recorded, HistoryColumn::bounded($column, $value));
    }

    public function testAColumnTheHistoryDoesNotHaveIsAMistake(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HistoryColumn::bounded('statuss', 'PAID');
    }

    /**
     * The bounds are the columns of the schema, every text column of the table: a schema widened
     * without the code, or a column added without a bound, would cut or refuse the row again.
     */
    public function testTheBoundsAreTheTextColumnsOfTheSchema(): void
    {
        $schema = simplexml_load_file(__DIR__ . '/../Config/schema.xml');
        self::assertNotFalse($schema);

        $sizes = [];
        foreach ($schema->xpath('//table[@name="payzen_embedded_transaction_history"]/column[@type="VARCHAR"]') as $column) {
            $sizes[(string) $column['name']] = (int) $column['size'];
        }

        ksort($sizes);
        $bounds = HistoryColumn::LENGTHS;
        ksort($bounds);

        self::assertSame($sizes, $bounds);
    }
}
