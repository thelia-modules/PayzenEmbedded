# Module PayZen pour Thelia

Ce module permet d'intégrer dans votre boutique le système de paiement Payzen ou SystemPay de la société Lyra Networks.
 
## Paiement par carte bancaire

Le paiement est réalisé via le formulaire de saisie des informations de carte bancaire PayZen qui est intégré à votre boutique.
Vos clients ne sortent pas du site pour payer leurs achats.
Le formulaire de saisie est intégralement géré par PayZen, les données de carte bancaires ne sont pas stockées ou manipulées 
par le module. Une certification PCI-DSS n'est pas nécessaire.

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

Un historique de toutes les transactions PayZen effectuées par un client est disponible sur la fiche client daic le 
back-office.

## Modification des totaux de commande

Lorsque le module est configuré pour une validation manuelle des transactions, il devient possible d'ajuster à la baisse
le montant final des commandes des clients depuis la page de détail de commande dans le back-office.

L'administrateur de la boutique peut alors modifier :
- Le montant qui sera payé par le client (<= au montant initial)
- la date de remise en banque 
- le mode de validation de la transaction (automatique pour une validation immédiate, ou manuel)

### 
Le module propose un event qui permet de réaliser cette opération programmatiquement, depuis un module de picking par
exemple :

Nom de l'évènement : `PayzenEmbedded::TRANSACTION_UPDATE_EVENT`

L'action event `\PayzenEmbedded\Event\TransactionUpdateEvent` permet de définir :

- l'ID ($orderId) de la commande concernée,
- le nouveau montant ($amount) de la transaction
- la date de capture requise ($expectedCaptureDate)
- le mode de validation de la transaction ($manualValidation - true/false)

Une fois dispatché, l'event retourne à travers $paymentStatus le statut de l'opération, qui est une des constantes
LyraClientWrapper::PAYEMENT_STATUS_* :

- PAYEMENT_STATUS_PAID : la transaction est terminée, et la commande est payée.
- PAYEMENT_STATUS_NOT_PAID : la transaction est terminée, et la commande n'a pas été payée.
- PAYEMENT_STATUS_IN_PROGRESS : la transaction est en cours, et peut être modifiée si nécessaire.
- PAYEMENT_STATUS_ERROR : l'opération de modification a échoué, généralement parce que la transaction est terminée ou
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

Pour utiliser le module Payzen, vous devez tout d'abord le configurer. Pour ce faire, rendez-vous dans votre back-office,
onglet Modules, et activez le module Payzen. Cliquez ensuite sur "Configurer" sur la ligne du module, et renseignez les
informations requises, que vous trouverez dans votre outil de gestion de caisse Payzen -&gt; Paramétrage -&gt; Boutiques
-&gt; *votre boutique*

Lors de la phase de test, vous pouvez définir les adresses IP qui seront autorisées à utiliser le module en front-office, 
afin de ne pas laisser vos clients payer leur commandes avec Payzen pendant la phase de test. Une fois le module en production,
vous pouvez aussi restreindre les IP autorisées à payer avec le module avec le mode "Production restreinte".

## URL de retour

Pour que vos commandes passent automatiquement au statut payé lorsque vos clients ont payé leurs commandes, vous devez
renseigner une **URL de retour** dans votre outils de gestion de caisse Payzen.

Cette adresse est formée de la manière suivante: `http://www.votresite.com/payzen/callback`
Par exemple, pour le site `thelia.net`, l'adresse en mode test et en mode production serait: `http://www.thelia.net/payzen/callback`. 

Vous trouverez l'adresse exacte à utiliser dans votre back-office Thelia, sur la page de configuration du module Payzen.

Pour mettre en place cette URL de retour rendez-vous dans votre outil de gestion de caisse Payzen -&gt; Paramétrage -&gt;
Boutiques -&gt; *votre boutique*, et copier/collez votre URL de retour dans les champs "*URL de retour de la boutique en mode test*" 
et "*URL de retour de la boutique en mode production*".

## Intégration en front-office

L'essentiel de l'intégration est réalisée via les hooks. Le module définit une page de paiement spécifique, qui permet 
l'affichage du formulaire embarqué: `PayzenEmbedded/templates/frontOffice/default/payzen-embedded/embedded-payment-page.html`

Vous pouvez mettre cette page aux couleurs de votre template spécifique si nécessaire.
