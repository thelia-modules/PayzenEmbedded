# Module PayZen pour Thelia

Ce module permet d'intégrer dans votre boutique le système de paiement PayZen de la société Lyra Networks.
 
## Paiement par carte bancaire

Le paiement est réalisé via le formulaire de saisie des informations de carte bancaire PayZen qui est intégré à votre boutique.
Vos clients ne sortent pas du site pour payer leurs achats.
Le formulaire de saisie est intégralement géré par PayZen, les données de carte bancaires ne sont pas stockées ou manipulées 
par le module. Une certification PCI-DSS n'est pas nécessaire.

Vous pouvez choisir d'afficher le formulaire de paiement dans une pop-in sur la page de récapitulation de la commande, 
il n'est alors pas nécessaire de passer à une nouvelle page pour payer la commande.

## Portefeuilles : Apple Pay, Google Pay

Le formulaire de paiement se présente sous deux formes, au choix dans la configuration du module :

- **Formulaire carte bancaire** : l'acheteur saisit les informations de sa carte. C'est le comportement par défaut.
- **SmartForm** : la plateforme PayZen affiche tous les moyens de paiement activés sur votre contrat, la carte
  et les portefeuilles comme Apple Pay ou Google Pay. Vous pouvez déplier les champs carte par défaut, les autres
  moyens de paiement étant alors listés en dessous.

Les portefeuilles n'apparaissent que dans le SmartForm : le formulaire carte bancaire ne les affiche jamais, même
si le contrat correspondant est actif. Le SmartForm demande la page de paiement dédiée, il ne s'affiche pas dans
la pop-in.

La configuration du module liste les moyens de paiement activés sur votre contrat, tels que la plateforme les
annonce, et permet d'en désactiver un sans toucher au contrat : utile le temps d'une panne, par exemple. La liste
est relue à chaque affichage de la page de configuration.

### Prérequis

- Le contrat du moyen de paiement doit être activé sur votre boutique PayZen, en test comme en production.
  Un contrat associé mais inactif se traduit par un moyen de paiement absent du formulaire, et par une erreur
  `PSP_610 / NO_ACCEPTANCE_AGREEMENT_AVAILABLE` si vous le forcez dans la liste des moyens proposés.
- Apple Pay demande en production que chaque domaine servant la boutique soit déclaré dans le Back Office Expert
  et serve le fichier de validation Apple sous `.well-known/apple-developer-merchantid-domain-association`,
  en HTTPS et sans authentification. En mode test, Apple Pay s'affiche depuis n'importe quel navigateur.

## Paiement en 1-clic

Le module supporte le paiement en un clic (ou paiement par alias / token). Cette option reste compatible avec
le SmartForm et ses portefeuilles. Lors de chaque paiement, vos clients ont
la possibilité d'enregistrer leurs informations de carte bancaire. Lors des achats suivants, ils n'auront alors plus
besoin d'indiquer ces informations : un clic suffit à payer leur commande.
Les informations de paiement sont enregistrées par PayZen, et ne sont jamais stockées ou manipulées par le module, une
certification PCI-DSS n'est pas nécessaire.

Les clients peuvent demander à tout moment la suppression des informations de paiement enregistrées, depuis leur compte
client, ou au moment de payer leur commande.

Avec le formulaire SmartForm, l'enregistrement de la carte n'est pas proposé : PayZen retire Apple Pay et Google Pay
d'un formulaire qui demande un enregistrement. Un client qui a déjà enregistré sa carte continue de payer en un clic.

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

## Annulation et remboursement depuis le back-office

Sur la page de détail d'une commande payée, l'administrateur peut rendre au client tout ou partie de ce qu'il a payé.
Le module s'en remet à PayZen pour choisir l'opération :

- une transaction non encore remise en banque est annulée, en totalité ;
- une transaction remise en banque fait l'objet d'un remboursement, total ou partiel. Plusieurs remboursements partiels
  sont possibles, jusqu'au montant payé.

La commande passe au statut « remboursée » quand PayZen a confirmé chaque remboursement et qu'il ne reste rien à rembourser, au statut « annulée » quand la
transaction a été annulée, et reste inchangée après un remboursement partiel. Chaque remboursement apparaît dans
l'historique des transactions, avec un montant négatif.

### Évènement de remboursement

Nom de l'évènement : `PayzenEmbedded::TRANSACTION_REFUND_EVENT`

L'action event `\PayzenEmbedded\Event\TransactionRefundEvent` reçoit :

- l'ID ($orderId) de la commande concernée,
- le montant ($amount) à rembourser, dans la plus petite unité de la devise (1250 pour 12,50 EUR),
- un motif ($comment) facultatif, inscrit sur le remboursement dans le Back Office PayZen,
- l'ID ($adminId) facultatif de l'administrateur, conservé sur la ligne d'historique,
- le montant ($expectedRefundedAmount) facultatif déjà remboursé tel que l'appelant l'a vu, dans la plus petite
  unité : si la plateforme en connaît un autre, la demande est refusée, pour ne jamais redemander un remboursement
  que l'appelant n'a pas vu.

Une fois dispatché, l'event retourne à travers `getOutcome()` ce que PayZen a fait, une des valeurs de
`\PayzenEmbedded\LyraClient\RefundOutcome` : `Cancelled`, `Refunded`, `PartiallyRefunded` ou `Pending`. Un montant
hors limites ou un refus de la plateforme lève une `TheliaProcessException` ; une demande restée sans réponse lève une
`RefundOutcomeUnknownException`, et la tentative suivante commence par relire la plateforme.

### Statut et stock

Le module passe la commande en « Remboursée » quand la plateforme a confirmé des remboursements qui couvrent le paiement, et en « Annulée » quand la transaction a été annulée avant sa remise en banque. Ces statuts ont l'effet que le cœur Thelia leur donne sur le stock : une commande payée qui passe en remboursée remet ses produits en stock, y compris quand ce sont des gestes commerciaux qui atteignent le total. Une commande dont un autre paiement est encore encaissé (paiement en double) n'est pas passée en remboursée.

### Verrou et instances multiples

Un remboursement, une mise à jour de l'historique ou une modification de la transaction (la remise en banque après
préparation, par exemple) verrouille la commande le temps des appels à PayZen : la modification attend la fin du
remboursement (15 secondes au plus, puis elle échoue : le module hôte qui a demandé la capture décide alors du statut de
la commande, et la capture se rejoue une fois le remboursement terminé), les deux autres refusent. Le verrou passe par le composant `lock` de
Symfony : avec le magasin par défaut (`LOCK_DSN=semaphore` ou `flock`), il ne vaut que pour un serveur. Une boutique
servie par plusieurs serveurs doit configurer un magasin partagé (`LOCK_DSN=redis://…` ou `pdo`).

Chaque paiement porte en `metadata` un marqueur de la boutique (empreinte de l'URL du site et de l'identifiant
PayZen). Ce que la plateforme liste (`Order/Get`) sans ce marqueur n'est rattaché à une commande que s'il s'agit de
sa transaction connue, ou d'un remboursement de celle-ci ; ce qu'elle notifie sans marqueur est accepté pour un
paiement (il précède le marqueur) et, pour un remboursement, seulement s'il porte sur la transaction de la
commande : deux boutiques sur un même contrat, ou deux environnements dans l'espace TEST, produisent les mêmes
références de commande. Une notification pour une commande payée avec un autre module est ignorée.

Le marqueur change avec l'URL du site : ne pas modifier l'URL de la boutique tant que des paiements sont en cours, leurs
notifications seraient ignorées. Le bouton « Mettre l'historique à jour » de la fiche commande, proposé avec ou sans
historique, relit la liste des transactions de la commande chez PayZen (`Order/Get`) et enregistre ce qu'elle contient,
remboursements et annulations faits depuis le Back Office PayZen compris. La commande passe « remboursée » quand chaque
remboursement est confirmé et qu'il ne reste rien à rembourser ; une annulation faite depuis le Back Office PayZen
apparaît dans l'historique et laisse le statut de la commande à la boutique.

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

With the SmartForm, the card registration is not offered: PayZen leaves Apple Pay and Google Pay out of a form that
asks for a registration. A customer who already registered a card keeps paying in one click.

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

## Cancel or refund from the back-office

On the page of a paid order, the administrator can give the customer back all or part of what they paid. The module
lets PayZen choose the operation:

- a transaction not captured yet is cancelled, in full;
- a captured transaction gets a refund, in full or in part. Several partial refunds are possible, up to the amount
  paid.

The order moves to the refunded status once the platform confirmed every refund and nothing is left to refund, to the
cancelled status when the transaction was cancelled, and stays as it is after a partial or a pending refund. Each refund shows in the transaction history, with a negative
amount.

### Transaction refund event

Event name: `PayzenEmbedded::TRANSACTION_REFUND_EVENT`

The `\PayzenEmbedded\Event\TransactionRefundEvent` action event takes:

- the ID ($orderId) of the order,
- the amount ($amount) to refund, in the smallest unit of the currency (1250 for 12.50 EUR),
- an optional reason ($comment), written on the refund in the PayZen back-office,
- the optional ID ($adminId) of the administrator, kept on the history row,
- the optional amount ($expectedRefundedAmount) refunded so far as the caller saw it, in the smallest unit: the
  refund is refused when the platform knows another figure, so that a refund the caller did not see is never asked
  for again.

Once dispatched, `getOutcome()` tells what PayZen did, one of `\PayzenEmbedded\LyraClient\RefundOutcome`:
`Cancelled`, `Refunded`, `PartiallyRefunded` or `Pending`. An amount out of range or a refusal from the platform
raises a `TheliaProcessException`; a request left unanswered raises a `RefundOutcomeUnknownException`, and the next
attempt starts by reading the platform again.

### Status and stock

The module sets the order refunded once the platform confirmed refunds that cover the payment, and cancelled when the transaction was cancelled before its capture. These statuses have the stock effect the Thelia core gives them: a paid order moving to refunded puts its products back in stock, gestures of goodwill that add up to the total included. An order another payment of which is still held (a double payment) is not set refunded.

### Lock and multiple instances

A refund, a history refresh or a transaction update (the capture after picking, say) locks the order while the
platform is called: the update waits for a running refund (15 seconds at most, then it fails: the host module that
asked for the capture decides on the order status, and the capture is asked for again once the refund is over), the
two others refuse. The lock goes through Symfony's
`lock` component: with the
default store (`LOCK_DSN=semaphore` or `flock`) it holds one server. A shop served by several servers needs a shared
store (`LOCK_DSN=redis://…` or `pdo`).

Every payment carries a shop marker in its `metadata` (a fingerprint of the site URL and of the PayZen shop id).
What the platform lists (`Order/Get`) without that marker is tied to an order only when it is its known transaction,
or a refund of it; what it notifies without one is accepted for a payment (it precedes the marker) and, for a
refund, only when it gives money back on the order's own transaction: two shops on one contract, or two
environments in the TEST space, produce the same order references. A notification for an order paid with another
module is ignored.

The marker changes with the site URL: do not change the shop URL while payments are in progress, their notifications
would be ignored. The "Refresh history" button of the order page, offered with or without a history, reads the
platform's list of the order's transactions (`Order/Get`) and records what it holds, refunds and cancellations made from
the PayZen back-office included. The order moves to the refunded status once every refund is confirmed and nothing is
left to refund; a cancellation made from the PayZen back-office shows in the history and leaves the order status to the
shop.

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
