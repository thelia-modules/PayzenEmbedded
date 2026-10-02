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
 * order and per form, until the PayZen block of that order is displayed again, for a few minutes
 * at most: a message read on a later visit would speak of an operation the administrator no longer
 * has in mind.
 */
final readonly class OrderEditMessages
{
    public const string UPDATE = 'update';
    public const string REFUND = 'refund';
    public const string REFRESH = 'refresh';

    /** Long enough for the redirect that follows the operation, short of a later visit. */
    public const int MAXIMUM_AGE_SECONDS = 300;

    private const string SESSION_KEY = 'payzen-embedded.order-edit-messages';

    public static function add(SessionInterface $session, int $orderId, string $form, string $type, string $message, ?int $now = null): void
    {
        $now ??= time();

        $messages = self::withoutExpired($session->get(self::SESSION_KEY, []), $now);
        $messages[$orderId][$form][] = ['type' => $type, 'message' => $message, 'at' => $now];

        $session->set(self::SESSION_KEY, $messages);
    }

    /**
     * Read once: a message is shown on the page that follows the operation, never again.
     *
     * @return array<string, list<array{type: string, message: string}>> the messages of the order, by form
     */
    public static function take(SessionInterface $session, int $orderId, ?int $now = null): array
    {
        $stored = $session->get(self::SESSION_KEY, []);
        $messages = self::withoutExpired($stored, $now ?? time());

        $ofOrder = $messages[$orderId] ?? [];
        unset($messages[$orderId]);

        // Every order page displays the hook: the session is written only when something left it.
        if ($messages !== $stored) {
            if ([] === $messages) {
                $session->remove(self::SESSION_KEY);
            } else {
                $session->set(self::SESSION_KEY, $messages);
            }
        }

        return array_map(
            static fn (array $ofForm): array => array_map(
                static fn (array $item): array => ['type' => $item['type'], 'message' => $item['message']],
                $ofForm
            ),
            $ofOrder
        );
    }

    /**
     * Every order's expired messages go, not only the order displayed: an order whose page is never
     * displayed again would keep its own for as long as the session lives.
     *
     * @param array<int, array<string, list<array{type: string, message: string, at?: int}>>> $messages
     *
     * @return array<int, array<string, list<array{type: string, message: string, at: int}>>>
     */
    private static function withoutExpired(array $messages, int $now): array
    {
        $kept = [];

        foreach ($messages as $orderId => $ofOrder) {
            foreach ($ofOrder as $form => $ofForm) {
                // An entry without its date is dropped, its age unknown; one dated ahead of the clock
                // (set back since) is aged both ways, so that it does not outlive the delay.
                $fresh = array_values(array_filter(
                    $ofForm,
                    static fn (array $item): bool => isset($item['at']) && abs($now - $item['at']) < self::MAXIMUM_AGE_SECONDS
                ));

                if ([] !== $fresh) {
                    $kept[$orderId][$form] = $fresh;
                }
            }
        }

        return $kept;
    }
}
