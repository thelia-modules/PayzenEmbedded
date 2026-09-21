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
