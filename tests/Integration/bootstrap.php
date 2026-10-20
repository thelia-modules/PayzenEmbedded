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
 * The integration tests boot Thelia: on top of what the unit tests load (the shop's autoloader,
 * the module, the generated models), they need the environment of the shop's tests, its database
 * among it. A Thelia checkout wires it in its own tests/bootstrap.php; any other shop reads its .env.
 */

use Symfony\Component\Dotenv\Dotenv;

require \dirname(__DIR__) . '/bootstrap.php';

$shop = __DIR__;

while (!is_file($shop . '/vendor/autoload.php')) {
    $shop = \dirname($shop);
}

if (is_file($shop . '/tests/bootstrap.php')) {
    require $shop . '/tests/bootstrap.php';

    return;
}

(new Dotenv())->bootEnv($shop . '/.env');
