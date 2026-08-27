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
