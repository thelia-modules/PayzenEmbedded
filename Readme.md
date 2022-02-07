# Module PayZen pour Thelia

Ce module permet d'intégrer dans votre boutique le système de paiement PayZen de la société Lyra Networks.
 
## Paiement par carte bancaire

Le paiement est réalisé via le formulaire de saisie des informations de carte bancaire PayZen qui est intégré à votre boutique.
Vos clients ne sortent pas du site pour payer leurs achats.
Le formulaire de saisie est intégralement géré par PayZen, les données de carte bancaires ne sont pas stockées ou manipulées 
par le module. Une certification PCI-DSS n'est pas nécessaire.

Vous pouvez choisir d'afficher le formulaire de paiement dans une pop-in sur la page de récapitulation de la commande, 
il n'est alors pas nécessaire de passer à une nouvelle page pour payer la commande.

## Paiement en 1-clic

Le module supporte le paiement en un clic (ou paiement par alias / token). Lors de chaque paiement, vos clients ont
la possibilité d'enregistrer leurs informations de carte bancaire. Lors des achats suivants, ils n'auront alors plus
besoin d'indiquer ces informations : un clic suffit à payer leur commande.
Les informations de paiement sont enregistrées par PayZen, et ne sont jamais stockées ou manipulées par le module, une
certification PCI-DSS n'est pas nécessaire.

Les clients peuvent demander à tout moment la suppression des informations de paiement enregistrées, depuis leur compte
client, ou au moment de payer leur commande.

## Historique des transactions

L'historique des transactions PayZen est disponible pour chaque commande sur le détail de la commande dans le 
back-office.

Un historique de toutes les transactions PayZen effectuées par un client est disponible sur la fiche client dans le 
back-office.

## Modification des transaction avant remise

Lorsque le module est configuré pour une validation manuelle des transactions, il devient possible d'ajuster à la baisse
le montant final des commandes des clients depuis la page de détail de commande dans le back-office.

L'administrateur de la boutique peut alors modifier :
- Le montant qui sera payé par le client (<= au montant initial)
- la date de remise en banque 
- le mode de validation de la transaction (automatique pour une validation immédiate, ou manuel).

Ce mode de fonctionnement est aussi pratique pour faire du débit à l'expédition.

### Evènement de mise à jour des transactions

Le module propose un event qui permet de réaliser cette opération programmatiquement, depuis un module de picking par
exemple :

Nom de l'évènement : `PayZenEmbedded::TRANSACTION_UPDATE_EVENT`

L'action event `\PayZenEmbedded\Event\TransactionUpdateEvent` permet de définir :

- l'ID ($orderId) de la commande concernée,
- le nouveau montant ($amount) de la transaction
- la date de capture requise ($expectedCaptureDate)
- le mode de validation de la transaction ($manualValidation - true/false)

Une fois dispatché, l'event retourne à travers $paymentStatus le statut de l'opération, qui est une des constantes
LyraClientWrapper::PAYMENT_STATUS_* :

- PAYMENT_STATUS_PAID : la transaction est terminée, et la commande est payée.
- PAYMENT_STATUS_NOT_PAID : la transaction est terminée, et la commande n'a pas été payée.
- PAYMENT_STATUS_IN_PROGRESS : la transaction est en cours, et peut être modifiée si nécessaire.
- PAYMENT_STATUS_ERROR : l'opération de modification a échoué, généralement parce que la transaction est terminée ou
expirée.

## Installation

Vous pouvez installer ce module avec Composer :

```
composer require thelia/payzen-embedded-module:~1.0
```

Si vous ne pouvez pas utiliser Composer, il existe une version autonome du module qui embarque les dépendances nécessaires. 
Choisissez la branche "standalone" pour télécharger cette version et l'installer sur votre Thelia depuis le back-office,
ou par FTP.

## Utilisation

Pour utiliser le module PayZen, vous devez tout d'abord le configurer. Pour ce faire, rendez-vous dans votre back-office,
onglet Modules, et activez le module PayZen. Cliquez ensuite sur "Configurer" sur la ligne du module, et renseignez les
informations requises, que vous trouverez dans votre outil de gestion de caisse PayZen -&gt; Paramétrage -&gt; Boutiques
-&gt; *votre boutique*

Lors de la phase de test, vous pouvez définir les adresses IP qui seront autorisées à utiliser le module en front-office, 
afin de ne pas laisser vos clients payer leur commandes avec PayZen pendant la phase de test. Une fois le module en production,
vous pouvez aussi restreindre les IP autorisées à payer avec le module avec le mode "Production restreinte".

## URL de retour

Pour que vos commandes passent automatiquement au statut payé lorsque vos clients ont payé leurs commandes, vous devez
renseigner une **URL de retour** dans votre outils de gestion de caisse PayZen.

Cette adresse est formée de la manière suivante: `https://www.votresite.com/payzen-embedded/ipn-callback`
Par exemple, pour le site `thelia.net`, l'adresse en mode test et en mode production serait: `https://www.thelia.net/payzen-embedded/ipn-callback`. 

Vous trouverez l'adresse exacte à utiliser dans votre back-office Thelia, sur la page de configuration du module PayZen.

Pour mettre en place cette URL de retour rendez-vous dans votre outil de gestion de caisse PayZen -&gt; Paramétrage -&gt;
Boutiques -&gt; *votre boutique*, et copier/collez votre URL de retour dans les champs "*URL de retour de la boutique en mode test*" 
et "*URL de retour de la boutique en mode production*".

## Intégration en front-office

L'essentiel de l'intégration est réalisée via les hooks. Le module définit cependant une page de paiement spécifique, qui permet 
l'affichage du formulaire embarqué: `PayZenEmbedded/templates/frontOffice/default/payzen-embedded/embedded-payment-page.html`

Vous pouvez mettre cette page aux couleurs de votre template spécifique si nécessaire. Si vous utilisez le formulaire en
pop-in, cette page ne sera pas utilisée.

---

# PayZen module for Thelia

This module allows you to integrate Lyra Networks PayZen payment system in your shop.
 
## Payment by credit card

Payment is made via the PayZen credit card information form which is integrated into your shop.
Your customers do not leave the site to pay their purchases.
The entry form is fully managed by PayZen, the credit card data is not stored or manipulated
by the module. PCI-DSS certification is not required.

## One-click payment

The module supports one-click payment (or payment by alias / token). For each payment, your customers can
register their credit card information. In subsequent purchases, they will no longer have
to enter them again: a single click is enough to pay their order.
Payment information is saved by PayZen, and is never stored or manipulated by the module, a
PCI-DSS certification is not required.

Customers may request at any time the removal of the registered payment information from their account
customer page, or before paying an order.

## Transaction History

The PayZen transaction history is available for each order on the order detail page in the
back office.

A history of all PayZen transactions made by a customer is available on the customer page in the
back office.

## Edit order totals

When the module is configured for manual validation of transactions, it is possible to adjust
the final amount of orders from the order detail page in the back office.

The  administrator can change :
- The amount that will be paid by the customer (always less or equal to the original amount)
- the date of bank receipt
- the validation mode of the transaction (automatic for an immediate validation, or manual)

### Transaction update event

The module includes an event to programmatically update a transaction, from a picking module for
example:

Event Name: `PayZenEmbedded::TRANSACTION_UPDATE_EVENT`

The event `\PayZenEmbedded\Event\TransactionUpdateEvent` contazins the following information :

- the ID ($orderId) of the command concerned,
- the new amount ($amount) of the transaction
- the required capture date ($expectedCaptureDate)
- the validation mode of the transaction ($manualValidation - true / false)

Once dispatched, the event returns in $paymentStatus the status of the transaction, which is one of the constants
`LyraClientWrapper::PAYMENT_STATUS_ *`:

- `PAYMENT_STATUS_PAID`: the transaction is complete, and the order is paid.
- `PAYMENT_STATUS_NOT_PAID`: the transaction is complete, and the order has not been paid.
- `PAYMENT_STATUS_IN_PROGRESS`: the transaction is in progress, and can be modified if necessary.
- `PAYMENT_STATUS_ERROR`: The change operation failed, usually because the transaction is complete or
expired.

## Installation

You can install this module with Composer:

`` `
composer require thelia / payzen-embedded-module: ~ 1.0
`` `

If you can't use Composer, there is a stand-alone version of the module that embeds the necessary dependencies.
Choose the "standalone" branch to download this version and install it on your Thelia from the back office,
or by FTP.

## Use

To use the PayZen module, you must first configure it. To do this, go to your back office,
Modules tab, and activate the PayZen module. Then click on "Configure" on the line of the module, and fill in the
Required information, which you will find in your PayZen Cash Management Tool - &gt; Settings - &gt; Shops
-&gt; *your shop*

During the module test phase, you can define the IP addresses that will be allowed to use the module in the front office,
so as not to let your customers pay for their orders with PayZen during the test phase. Once the module is in production,
you can also restrict the IPs allowed to pay with the module with the "Restricted Production" mode.

## Return URL

For your orders to automatically go to paid status when your customers have paid their orders, you must
Enter a **Return URL** in your PayZen back-office.

This address is formed as follows: `https://www.yoursite.com/payzen-embedded/ipn-callback`
For example, for the `thelia.net` site, the address in test mode and in production mode would be:` https://www.thelia.net/payzen-embedded/ipn-callback`.

You will find the exact address to use in your Thelia back-office, on the PayZen module configuration page.

To set up this return URL go to your PayZen Cash Management Tool -&gt; Settings -&gt;
Shops -&gt; *your shop*, and copy / paste your return URL into the fields "*Shop return URL in test mode*"
and "*Return URL of the store in production mode*".

## Front Office Integration

Most of the integration is done via hooks. The module defines a specific payment page, which allows
Embedded form display: `PayZenEmbedded/templates/frontOffice/default/payzen-embedded/embedded-payment-page.html`

You can style this page to match your specific template.
