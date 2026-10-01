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

/*
 * The tests run from the shop the module is installed in: its autoloader knows the module and
 * Thelia. The ORM classes Thelia generates (the TableMap and Base classes of every model) live in
 * the shop's var/propel/<environment>/model once its cache was built; they are added here, so that
 * the few tests that need them run. Without them, those tests are skipped, see GeneratedModels.
 */
$directory = __DIR__;

while (!is_file($directory . '/vendor/autoload.php')) {
    $parent = \dirname($directory);

    if ($parent === $directory) {
        throw new RuntimeException('Run the tests from a shop the module is installed in: no vendor/autoload.php above ' . __DIR__);
    }

    $directory = $parent;
}

$loader = require $directory . '/vendor/autoload.php';
$loader->addPsr4('PayzenEmbedded\\', \dirname(__DIR__) . '/');

foreach (glob($directory . '/var/propel/*/model/*', \GLOB_ONLYDIR) ?: [] as $generated) {
    $loader->addPsr4(basename($generated) . '\\', $generated . '/');
}
