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

use PayzenEmbedded\LyraClient\LyraTransactionRefundWrapper;

/**
 * The refund wrapper with the platform replaced by a script: it lists the transactions it was given
 * for the order, answers Transaction/CancelOrRefund with what it was told (a refund the platform
 * then lists too), and keeps what it was sent. Nothing here reaches a network.
 */
final class RecordingRefundClient extends LyraTransactionRefundWrapper
{
    /** @var list<array{string, array<string, mixed>}> every call: the target and what was sent */
    public array $sent = [];

    /** @var list<array<string, mixed>> the transactions Order/Get lists for the order */
    public array $listed = [];

    /** @var array<string, mixed>|\Throwable|null what Transaction/CancelOrRefund answers */
    public array|\Throwable|null $refundAnswer = null;

    /** @var \Throwable|null what Order/Get fails with, before anything is sent */
    public ?\Throwable $listFailure = null;

    public function post($target, $array)
    {
        $this->sent[] = [$target, $array];

        if ('V4/Order/Get' === $target) {
            if (null !== $this->listFailure) {
                throw $this->listFailure;
            }

            return ['status' => 'SUCCESS', 'answer' => ['transactions' => $this->listed]];
        }

        if ('V4/Transaction/CancelOrRefund' === $target) {
            if ($this->refundAnswer instanceof \Throwable) {
                throw $this->refundAnswer;
            }

            $answer = $this->refundAnswer ?? throw new \LogicException('No answer was scripted for Transaction/CancelOrRefund.');

            if ('SUCCESS' === ($answer['status'] ?? null) && isset($answer['answer']['uuid'], $answer['answer']['operationType'])) {
                $this->listed[] = $answer['answer'];
            }

            return $answer;
        }

        throw new \LogicException('Unexpected call to '.$target);
    }

    /**
     * @return list<array<string, mixed>> what was sent to Transaction/CancelOrRefund
     */
    public function refundRequests(): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call[1],
            array_filter($this->sent, static fn (array $call): bool => 'V4/Transaction/CancelOrRefund' === $call[0]),
        ));
    }
}
