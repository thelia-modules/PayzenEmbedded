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

use PayzenEmbedded\LyraClient\LyraJavascriptClientManagementWrapper;

/**
 * The client of a payment, with the platform replaced by a script: it keeps what it was sent, and
 * answers a form token unless told otherwise. Nothing here reaches a network.
 */
final class RecordingLyraClient extends LyraJavascriptClientManagementWrapper
{
    /** @var array{string, array<string, mixed>}|null the last call: the target and what was sent */
    public ?array $sent = null;

    /** @var array<string, mixed>|\Throwable|null what the platform answers: a form token when null */
    public array|\Throwable|null $answer = null;

    public function post($target, $array)
    {
        $this->sent = [$target, $array];

        if ($this->answer instanceof \Throwable) {
            throw $this->answer;
        }

        return $this->answer ?? ['status' => 'SUCCESS', 'answer' => ['formToken' => 'form-token']];
    }
}
