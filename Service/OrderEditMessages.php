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
namespace PayzenEmbedded\Service;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * The outcome of an operation of the order page, kept for the form that asked for it.
 *
 * The back office shows its flashes at the top of the page, far above the PayZen block: a refusal
 * read nowhere near the button that caused it goes unseen. The same message is kept here, per
 * order and per form, until the PayZen block of that order is displayed again.
 */
final readonly class OrderEditMessages
{
    public const string UPDATE = 'update';
    public const string REFUND = 'refund';
    public const string REFRESH = 'refresh';

    private const string SESSION_KEY = 'payzen-embedded.order-edit-messages';

    public static function add(SessionInterface $session, int $orderId, string $form, string $type, string $message): void
    {
        $messages = $session->get(self::SESSION_KEY, []);
        $messages[$orderId][$form][] = ['type' => $type, 'message' => $message];

        $session->set(self::SESSION_KEY, $messages);
    }

    /**
     * Read once: a message is shown on the page that follows the operation, never again.
     *
     * @return array<string, list<array{type: string, message: string}>> the messages of the order, by form
     */
    public static function take(SessionInterface $session, int $orderId): array
    {
        $messages = $session->get(self::SESSION_KEY, []);

        if (!isset($messages[$orderId])) {
            return [];
        }

        $ofOrder = $messages[$orderId];
        unset($messages[$orderId]);

        if ([] === $messages) {
            $session->remove(self::SESSION_KEY);
        } else {
            $session->set(self::SESSION_KEY, $messages);
        }

        return $ofOrder;
    }
}
