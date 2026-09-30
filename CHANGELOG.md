# 3.4.0

## Cancel or refund from the order page
- A payment can be cancelled or refunded from the order page of the back-office, in full or in part (`Transaction/CancelOrRefund`, `resolutionMode AUTO`). The platform decides: a transaction waiting for its capture is cancelled, in full, whatever its validation mode; a captured one gets a refund transaction, as many times as needed up to the amount paid. The order moves to the refunded status once the platform confirmed every refund and nothing is left to refund, to the cancelled status when the transaction was cancelled, and stays as it is after a partial or a pending refund.
- `PayzenEmbedded::TRANSACTION_REFUND_EVENT` does the same from code, for instance from a returns or picking module: dispatch a `TransactionRefundEvent` with the order, the amount in minor units, a reason, the administrator and, optionally, the amount seen as refunded so far, then read `getOutcome()` (`Cancelled`, `Refunded`, `PartiallyRefunded` or `Pending`).
- Refunding needs the permission on the orders as well as on the module, and so do the transaction update and the history refresh, which needed the permission on the module only: a profile that captured or refreshed without the permission to update orders has to be granted it; the order page shows the transaction forms to the administrators allowed to use them. Every attempt, failed or not, is written to the administrator log.

## Guards on the refund
- Before the balance is checked, the platform's own list of the order's transactions is recorded (`Order/Get`): a refund whose answer was lost, or one made from the PayZen back-office, is counted. The balance is read on the transaction the order stands on, so the attempts the shopper gave up on do not add up; a refund still running counts as money already promised, never offered twice, but not as money given back until the platform confirms it.
- The refund form carries what the page showed as refunded so far: a refund the page did not show is refused until the page is reloaded, since asking again would refund twice. One refund at a time per order, under the framework's lock (see the Readme for a shop served by several servers): the history refresh refuses a locked order, the transaction update (the capture after picking) waits for it up to 15 seconds, and the form button is disabled once the refund is confirmed. The figure shown as refunded so far is required by the form; a `TransactionRefundEvent` dispatched without it relies on the lock and on the balance alone.
- The platform's answer is read before anything is written: only a credit, or the debit back as cancelled, is accepted; anything else is refused and the order is left alone. A request the platform did not answer, or an answer that could not be recorded, is reported as such, never as "not sent", and the next attempt starts by reading the platform. A refund nothing handled (a listener stopped the event) is reported as a failure.
- The update form compares its amount to the order total in the smallest unit of the currency: a legacy total with four decimals refused the amount the form was filled with. Its guidance says the amount can only be lowered, as the rule does.
- Amounts typed by an administrator are read strictly into the smallest unit of the currency ("1 234,56" or "12abc" are refused; "4,50" was sent as 4.00 by the update form), with the decimals of the currency (none for JPY or XPF, three for KWD or TND). A total the shop computes, such as the capture after picking, is converted whatever its decimals.

## Transaction history and notifications
- A credit the module asks for is tied to the debit it asks it on when the platform's answer does not name it, and a transaction the history already holds for the order is recognised as the shop's when the platform lists or notifies it again: a refund still running is seen settled once confirmed. An answer that does not repeat a credit's parent no longer erases it, and a credit is counted positive whatever the sign it is written with.
- An answer that does not say a transaction's type no longer turns a credit the history holds into a debit, and a transaction marked by another shop stays out even when the history knows it. The trace of a refund answer keeps its shape (parent, shop marker) without the card data the transaction details carry.
- The same transaction notified or read again in the same state (a refresh of an authorisation still running) no longer moves back an order that left the unpaid state: an order being prepared stayed prepared, where it went back to paid and the confirmation was sent again.
- The creation dates of the history are read back as the UTC the platform gave them: the column keeps no time zone and a shop outside UTC read its own history hours off the notifications it weighed, which could let the late refusal of an earlier attempt cancel an order standing on a later one. The transactions of one notification are judged debits first, each on the order the previous one left: a credit sent with its debit is no longer set aside. The refresh names, in a warning, an attempt the platform lists that outranks the one the order stands on, since only its notification moves the order.
- The notification endpoint accepts only an answer signed with the REST password, as the platform signs its notifications. The answer the shopper's browser receives, signed with the public HMAC key, was accepted there too and could be replayed by the shopper.
- A payment the platform took always outranks an unpaid transaction the order stands on, whatever the order of the attempts: a refusal of a later attempt notified first no longer leaves the payment of an earlier one on a cancelled order. A payment notified once every cent of it was already given back (its refund notified or listed first) settles the order as refunded instead of paid.
- An order that carries no transaction reference was never moved by a notification: the transactions the refresh records for it are the platform's word, not a move. It takes any payment, a refusal is weighed against a payment the platform lists only, it is never set refunded, and its page offers neither a refund nor an update until a notification ties it to its payment.
- A new `operationType` column tells a refund (`CREDIT`) from a payment (`DEBIT`), `parentUuid` names the debit a credit gives money back on. Refunds show in the history with a negative amount. A shop reading the history by status has to keep the debits: a credit is `PAID` too (`payzen_embedded_history` takes `operation_type="DEBIT"`). The rows written before this version are marked as debits by the update script. `detailedStatus` is widened to 64 characters and `status` to 32: the platform's `WAITING_AUTHORISATION_TO_VALIDATE` did not fit in 32, nor `PARTIALLY_PAID` in 10. Depending on the SQL mode of the server, a value too long was cut, then read back as another status, or refused with the row and the refund. Every value the platform sends is now also cut to its column before it is written.
- A credit notified by the platform is recorded and never moves the order, except a pending refund the shop asked for, which settles the order once confirmed. A notification for an order paid with another module is ignored. The platform is answered about the transaction the order stands on, whatever the order of the transactions in its notification.
- Every payment carries a shop marker in its metadata, and what the platform lists or notifies is checked against it, with the space (TEST or PRODUCTION): two shops on one contract, or two environments in the TEST space, produce the same order references. A transaction without a marker is tied to an order only when it is the debit the order stands on, or a credit of that debit; a payment notified without one is still accepted, since a payment precedes the marker. See the Readme before changing the shop URL.

## Twig back-office fixes
- The PayZen block reads the order id whichever name the back-office hands it: the Twig order page hands `order` to `order-edit.bottom` where every other hook receives `order_id`.
- The "Refresh history" button is offered on every order paid with PayZen, with or without a history, and also records every transaction the platform lists for the order (`Order/Get`): a refund or a cancellation made from the PayZen back-office now shows in the history, where the notification arbiter alone left a finished transaction untouched. A refund confirmed by the platform that leaves nothing to refund moves the order to the refunded status, as its notification does; a cancellation leaves the order status to the shop. The refresh holds the same lock as the refund, so the two never write the history at the same time. An order that still carries no transaction (its payment was never notified) is refreshed with a warning: the history is recorded, but only the notification ties the order to its transaction.
- The transaction update reports a failure when nothing handled its event or the platform answered an unexpected status, instead of "updated".
- The order page of a transaction still open broke on the capture date widget; the outcome and the errors of the three actions went to the parser context and were lost on redirect, they are flashed now; the field guidance is rendered again; the update form is kept until the payment is captured, and its amount rule is declared the way the current form component expects, every submission failed on it before.

## Payment request
- The amount sent to create a payment is rounded to the smallest unit of the currency, where it was truncated: a total with more decimals than the currency (a legacy order total keeps four) is sent to the nearest cent instead of the cent below.

## Front-office
- The SmartForm keeps the card form collapsed when the option says so: the setting was ignored.
- The notification endpoint answers KO to any failure, a malformed answer included, and logs its class and place, where some failures answered an HTTP 500.
- The SmartForm no longer asks to register the card when the one click payments are allowed: the platform left Apple Pay and Google Pay out of such a form. A customer who already registered a card keeps paying with it. The card form is unchanged.
- The payment error page no longer links to a `contact` route: Flexy has none, and the missing route turned a refused payment form into a 500.

## Upgrading
- `module:refresh` plays `Config/update/3.4.0.sql`: two columns added, two widened, the administrator constraint set to `ON DELETE SET NULL`. It can be played again.
- Rebuild the Propel models (remove `var/propel/<environment>`) and clear the cache: the models have to know the new columns, and the new services (refund listener, wrappers with the lock factory) have to be compiled.
- A shop served by several servers configures a shared lock store (`LOCK_DSN`), see the Readme.
- A module that builds a `LyraPaymentManagementWrapper` or one of its subclasses passes nothing, or a `LockFactory`, as its second argument.

# 3.3.1
- `pay()` asks the same provider as a theme does, so the platform is called once per display of the payment step whichever of the two asks first.

# 3.3.0
- The same order can be presented to the module on every payment attempt: `supportsPaymentRetry()` answers true, so a Thelia that carries the capability reuses the unpaid order instead of cancelling it and placing a new one. The shopper keeps their cart and their order reference after a refusal.
- Notifications are applied in the order the platform created them. An order can carry one transaction per attempt, and the platform notifies each on its own schedule: applied as they arrived, the refusal of an attempt the shopper had given up on cancelled an order that was already paid for. A notification now moves the order only when its transaction outranks the one the order stands on.
- One row per transaction in the history, keyed on the identifier the platform gives it. Every notification used to insert a row, so one payment left as many rows as notifications, and a shop could not tell a retry from a duplicate. An install holding duplicates folds them into the row it last wrote, on update.
- `CardFormProvider::forOrder()` hands a template the form token and the public key of an order, without rendering a page. A Twig theme can mount the card fields inside its own checkout step. `pay()` is unchanged for the themes that redirect.
- The one click path no longer empties the cart when a payment is in progress. It did so because the core emptied the cart at placement; a core that keeps the cart until the payment is confirmed consumes it itself.

# 3.2.1
- Fixed the transaction history, which could not record a refused payment: the detailed error message was written into the 32-character `detailedStatus` column, where the platform's sentence did not fit, and the insert failed. The message now lands in its own `detailedErrorMessage` column and `detailedStatus` keeps the status.

# 3.2.0
- Rebuilt the module configuration page around the three questions a shop asks: what customers pay with, how the money is collected, and the PayZen connection, that last one folded away once it is filled in.
- Payment methods are now checkboxes reading "offered to your customers", one per method the contract allows, instead of a list of identifiers to type.
- Every field shows its guidance again: the Twig back-office rendered none of it, and the wording has been cut down to one line per field.
- Completed the French catalogue of the configuration page.

# 3.1.1
- The card form is dressed by the neon theme too: under the older classic theme it lost its field placeholders, its brand logos and its button wording.
- Added the back-office wording missing from the French catalogue.

# 3.1.0
- The payment form can be rendered as the PayZen SmartForm, which offers the wallets activated on the shop contract, such as Apple Pay or Google Pay. The credit card form stays the default.
- The payment methods activated on the contract are listed in the module configuration, where each of them can be switched off, for instance while one of them is out of service.
- New configuration variables: `form_type`, `smart_form_card_form_expanded` and `excluded_payment_methods`.
- The Krypton client and its theme are now loaded from the platform set in `webservice_endpoint`, instead of a hardcoded host.
- Fixed the payment page, which ended in a 500 error: it called Twig components no theme provides.

# 3.0.0
- Thelia 3 support: templates rendered through the ParserResolver, Twig back-office templates, `#[Route]` attributes.
