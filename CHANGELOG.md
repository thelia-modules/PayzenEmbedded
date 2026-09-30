# 3.4.0

## Cancel or refund from the order page
- A payment can be cancelled or refunded from the order page of the back-office, in full or in part (`Transaction/CancelOrRefund`, `resolutionMode AUTO`). The platform decides: a transaction waiting for its capture is cancelled, in full, whatever its validation mode; a captured one gets a refund transaction, as many times as needed up to the amount paid. The order moves to the refunded status once the platform confirmed every refund and nothing is left to refund, to the cancelled status when the transaction was cancelled, and stays as it is after a partial or a pending refund.
- `PayzenEmbedded::TRANSACTION_REFUND_EVENT` does the same from code, for instance from a returns or picking module: dispatch a `TransactionRefundEvent` with the order, the amount in minor units, a reason, the administrator and, optionally, the amount seen as refunded so far, then read `getOutcome()` (`Cancelled`, `Refunded`, `PartiallyRefunded` or `Pending`).
- Refunding needs the permission on the orders as well as on the module; the order page shows the transaction forms to the administrators allowed to use them. Every attempt, failed or not, is written to the administrator log.

## Guards on the refund
- Before the balance is checked, the platform's own list of the order's transactions is recorded (`Order/Get`): a refund whose answer was lost, or one made from the PayZen back-office, is counted. The balance is read on the transaction the order stands on, so the attempts the shopper gave up on do not add up; a refund still running counts as money already promised, never offered twice, but not as money given back until the platform confirms it.
- The refund form carries what the page showed as refunded so far: a refund the page did not show is refused until the page is reloaded, since asking again would refund twice. One refund at a time per order, under the framework's lock (see the Readme for a shop served by several servers), and the form button is disabled once the refund is confirmed.
- The platform's answer is read before anything is written: only a credit, or the debit back as cancelled, is accepted; anything else is refused and the order is left alone. A request the platform did not answer, or an answer that could not be recorded, is reported as such, never as "not sent", and the next attempt starts by reading the platform. A refund nothing handled (a listener stopped the event) is reported as a failure.
- Amounts typed by an administrator are read strictly into the smallest unit of the currency ("1 234,56" or "12abc" are refused; "4,50" was sent as 4.00 by the update form), with the decimals of the currency (none for JPY or XPF, three for KWD or TND). A total the shop computes, such as the capture after picking, is converted whatever its decimals.

## Transaction history and notifications
- A new `operationType` column tells a refund (`CREDIT`) from a payment (`DEBIT`), `parentUuid` names the debit a credit gives money back on. Refunds show in the history with a negative amount. A shop reading the history by status has to keep the debits: a credit is `PAID` too (`payzen_embedded_history` takes `operation_type="DEBIT"`). The rows written before this version are marked as debits by the update script.
- A credit notified by the platform is recorded and never moves the order, except a pending refund the shop asked for, which settles the order once confirmed. A notification for an order paid with another module is ignored. The platform is answered about the transaction the order stands on, whatever the order of the transactions in its notification.
- Every payment carries a shop marker in its metadata, and what the platform lists or notifies is checked against it, with the space (TEST or PRODUCTION): two shops on one contract, or two environments in the TEST space, produce the same order references. A transaction without a marker is tied to an order only when it is the debit the order stands on, or a credit of that debit; a payment notified without one is still accepted, since a payment precedes the marker. See the Readme before changing the shop URL.

## Twig back-office fixes
- The PayZen block reads the order id whichever name the back-office hands it: the Twig order page hands `order` to `order-edit.bottom` where every other hook receives `order_id`.
- The "Refresh history" button is offered on every order paid with PayZen, with or without a history, and also records every transaction the platform lists for the order (`Order/Get`): a refund or a cancellation made from the PayZen back-office now shows in the history, where the notification arbiter alone left a finished transaction untouched. A refund confirmed by the platform that leaves nothing to refund moves the order to the refunded status, as its notification does; a cancellation leaves the order status to the shop. The refresh holds the same lock as the refund, so the two never write the history at the same time.
- The transaction update reports a failure when nothing handled its event or the platform answered an unexpected status, instead of "updated".
- The order page of a transaction still open broke on the capture date widget; the outcome and the errors of the three actions went to the parser context and were lost on redirect, they are flashed now; the field guidance is rendered again; the update form is kept until the payment is captured, and its amount rule is declared the way the current form component expects, every submission failed on it before.

## Front-office
- The SmartForm no longer asks to register the card when the one click payments are allowed: the platform left Apple Pay and Google Pay out of such a form. A customer who already registered a card keeps paying with it. The card form is unchanged.
- The payment error page no longer links to a `contact` route: Flexy has none, and the missing route turned a refused payment form into a 500.

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
