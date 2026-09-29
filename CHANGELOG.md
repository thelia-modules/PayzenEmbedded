# 3.4.0
- A payment can be cancelled or refunded from the order page of the back-office, in full or in part. The platform decides: a transaction waiting for its capture is cancelled, a captured one gets a refund transaction. An order refunded in full moves to the refunded status, a cancelled one to the cancelled status, a partial refund leaves it as it is.
- `PayzenEmbedded::TRANSACTION_REFUND_EVENT` does the same from code, for instance from a returns module: dispatch a `TransactionRefundEvent` with the order, the amount and a reason, and read the outcome on the event.
- The history tells a refund from a payment: a new `operationType` column holds `DEBIT` or `CREDIT`, refunds show as such with a negative amount, and a refund notification never moves the order. The rows written before this version are payments, and the update marks them as debits.
- The order page actions flash their outcome and their errors, which the Twig back-office showed nowhere: a refused amount or a platform error went to the log only.
- Fixed the order page of a transaction still open in the Twig back-office, which broke on the capture date widget, and restored the field guidance the Twig back-office did not render.
- The PayZen block reads the order id whichever name the back-office hands it: the Twig order page hands `order` to `order-edit.bottom` where every other hook receives `order_id`.

- Every payment carries a shop marker in its metadata, and what the platform lists (`Order/Get`) or notifies is checked against it: two shops on one contract, or two environments in the TEST space, produce the same order references. A transaction without a marker is tied to an order only when it is the debit the order stands on, or a credit of that debit; a credit is deducted from the debit it gives money back on (`parentUuid`).
- The transaction the order stands on is the one its reference names; the paid or latest attempt only speaks when the history does not know that reference.
- A pending refund settles the order once the platform confirms it. A refund the platform answered but whose result could not be recorded is reported as such, never as "not sent". A request the platform did not answer is reported the same way, since it may have processed it.
- The refund form carries what the page showed as refunded so far, and `TransactionRefundEvent` takes it as an option: a refund the administrator did not see (an answer lost, or a refund made from the PayZen back-office) is refused until the page is reloaded. Asking again would have refunded twice.
- The order page shows the transaction forms to the administrators allowed to use them, reads the transaction the order stands on, and keeps the update form until the payment is captured.
- The amount typed in the update form is read strictly too: "4,50" was sent as 4.00. A total the shop computes, such as the capture after picking, is converted whatever its decimals: a legacy order total keeps four of them.
- A refund the platform is still processing counts as money already given back, and is reported as pending rather than refused: asking again would have refunded twice.
- The balance is read on the transaction the order stands on: the attempts the shopper gave up on, now listed by the platform, no longer add up. A payment authorised but not captured yet, whatever its validation mode, can only be cancelled in full.
- The lock on a refund goes through the framework's lock factory, shared by every node of the shop, and is named per shop. The transactions the platform lists for another order, currency or shop are left out of the history.
- Amounts are converted with the decimals of the currency (none for JPY or XPF, three for KWD or TND) when a payment is created, updated, refunded or displayed.
- The platform is answered about the transaction the order stands on, whatever the order of the transactions in its notification.
- A notification for an order paid with another module is ignored. A refund notified without the shop marker is tied to the order only when it gives money back on the order's own transaction; a payment without one is still accepted, since a payment precedes the marker. The space (TEST or PRODUCTION) is read where the notification names it, at its top level.
- The back-office reports a refund nothing handled (a listener that stopped the event) as a failure, not as "sent".
- A refund recorded whose order status could not be updated is reported as such, never as "not sent". The update and refresh actions get the same permissions, messages and log as the refund.
- Before a refund, the platform's own list of the order's transactions is recorded (`Order/Get`): a refund whose answer was lost to a timeout, or one made from the PayZen back-office, is counted before the balance is checked.
- One refund at a time per order, under a lock: two requests reading the same balance would both have reached the platform. The form button is disabled once the refund is confirmed.
- The amount typed in the refund form is read strictly, in the smallest unit of the currency: "1 234,56" or "12abc" are refused instead of being read as another amount. `TransactionRefundEvent` carries the amount in minor units, and the administrator who asked for the refund, kept on the history row.
- An answer of the platform that is neither a credit nor the debit cancelled is refused before anything is written: an order is never cancelled on a guess. What the platform really answers is logged.
- An authorisation still waiting for its capture can be cancelled from the order page, in full: the manual validation flow left nothing to refund before the capture.
- Refunding needs the permission on the orders as well as on the module. Failed attempts are written to the administrator log too.
- The history holds credits from now on: a shop reading it by status has to keep the debits (`payzen_embedded_history` takes `operation_type="DEBIT"`).
- The payment error page no longer links to a `contact` route: Flexy has none, and the missing route turned a refused payment form into a 500.
- The SmartForm no longer asks to register the card when the one click payments are allowed: the platform left Apple Pay and Google Pay out of the form. A customer who already registered a card keeps paying with it.

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
