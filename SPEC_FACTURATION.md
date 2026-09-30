# Spécification — Module Facturation

Statut : **module en service**. Ce document est le besoin cadré avec l'utilisateur
avant l'implémentation, gardé pour le « pourquoi » d'un modèle de données ou d'une
règle métier — le code y renvoie. Il n'a pas suivi toutes les évolutions depuis :
**en cas de désaccord, le code fait foi**, et `README.md` décrit ce que
l'application fait aujourd'hui.

## 1. Objectif

Permettre à l'association d'émettre des factures pour :
- **contrats de cession de spectacle** (le cas le plus fréquent — plusieurs postes :
  cachet, frais de déplacement, défraiement…) ;
- **cotisations de membres** (facturation périodique, faible volume) ;
- **participation aux frais du local** ;
- **participation aux stages** ;
- location de matériel (plus rare).

Petit volume (dans l'esprit des ~10 fiches de salaire/mois), 1–2 utilisateurs, pas de
besoin de scalabilité.

## 2. Nouveau module `facturation`

S'ajoute au registre `lib/modules.php` (voir `MODULES`), avec `requires: ['compta']` car
le rapprochement automatique des paiements dépend du module comptabilité. Suit le même
schéma que `analytique` (dépendance en cascade, retrait automatique si `compta` est
désactivé).

## 3. Modèle de données (nouvelles tables, migration versionnée)

### `debiteurs`
Fiche réutilisable (comme `employes` pour les salaires) — nécessaire pour pré-remplir
l'adresse sur la QR-facture et conserver l'historique par débiteur.

> **Depuis :** cette table a fusionné avec les **structures** du module Booking
> (`factures.structure_id`) — un débiteur et une structure démarchée sont la même
> fiche, et la tenir en double obligeait à saisir deux fois la même adresse. Les
> champs ci-dessous se lisent donc sur `structures`.

| champ | notes |
|---|---|
| `id` | |
| `nom` | raison sociale ou nom complet |
| `adresse_rue`, `adresse_npa`, `adresse_localite`, `adresse_pays` | requis pour le bloc débiteur de la QR-facture (défaut pays = Suisse) |
| `email` | optionnel, pour l'envoi par e-mail |
| `notes` | libre |
| `cree_le` | |

### `factures`
| champ | notes |
|---|---|
| `id` | |
| `debiteur_id` | FK → `debiteurs` |
| `numero` | format `AAAA-NNN`, remise à zéro par année civile (ex. `2026-001`) |
| `reference_paiement` | référence structurée SCOR (ISO 11649), générée à l'émission |
| `date_emission`, `date_echeance` | échéance = émission + délai (défaut 30 j, configurable par facture et par défaut global dans les paramètres) |
| `statut` | `brouillon` / `emise` / `payee` / `annulee` — *« en retard »* est **dérivé** (échéance dépassée + non payée), pas stocké |
| `montant_total` | figé à l'émission (somme des lignes), comme les montants d'une fiche |
| `compte_bancaire_id` | FK → `comptes_bancaires` (module compta) — compte créditeur pour la QR-facture |
| `ecriture_id` | FK nullable → `ecritures`, renseignée au rapprochement |
| `envoyee_le`, `payee_le` | horodatages |
| `communication` | texte libre imprimé sur la facture (ex. objet) |

### `facture_lignes`
Même logique que `fiche_lignes` : plusieurs lignes, axe analytique **par ligne**.

| champ | notes |
|---|---|
| `id` | |
| `facture_id` | FK |
| `description` | |
| `quantite`, `prix_unitaire` | `montant = quantite × prix_unitaire`, figé |
| `axe_analytique_id` | FK nullable → `axes_analytiques`, réutilise le module analytique existant |

**Historique figé** : une fois `emise`, une facture (numéro, montants, taux/référence)
ne se modifie plus — cohérent avec la règle déjà appliquée aux fiches de salaire. Seul
un statut `annulee` est possible (pas de note de crédit en v1, voir §8).

## 4. Cycle de vie d'une facture

```
brouillon → emise → payee
              ↓
           annulee   (uniquement si pas encore payée)
```
« En retard » = affichage calculé (`emise` + `date_echeance` dépassée + `payee_le` NULL).

## 5. Numérotation

Séquentielle par année civile, format `AAAA-NNN` (ex. `2026-001`, `2026-002`). Attribuée
à l'émission (pas au brouillon), sans trou dans la séquence — même esprit que la
numérotation continue attendue en comptabilité suisse.

## 6. QR-facture suisse & PDF

**Décision actée : exception à la règle « zéro dépendance externe »** du projet — générer
une QR-facture conforme (zone de paiement normée, QR code *Swiss Payment Code*) sans
bibliothèque serait déraisonnable.

- Librairie PHP dédiée (ex. `sprain/swiss-qr-bill`, référence pour la norme suisse) +
  génération PDF (ex. TCPDF, dépendance de la lib QR-bill).
- **Composer utilisé uniquement en local** : `composer.json`/`composer.lock` +
  dossier `vendor/` **commités dans le dépôt**. Aucune commande Composer nécessaire sur
  l'hébergement mutualisé — le déploiement reste `git pull`, cohérent avec le
  fonctionnement actuel.
- La facture est produite comme un **vrai PDF téléchargeable/joignable à un e-mail**
  (et non plus HTML + `window.print()` comme les fiches) — nécessaire pour l'envoi en
  pièce jointe (réutilise le mécanisme e-mail existant : log en dev, `mail()` en prod).
- **Référence de paiement** : utiliser IBAN standard (pas de QR-IBAN) + référence
  structurée SCOR — c'est le mode en vigueur depuis la fin du régime QR-IBAN/QR-référence
  (échéance passée en novembre 2025). Ne pas implémenter l'ancien schéma QR-IBAN.
- **Point à vérifier avant codage** : `parametres` stocke déjà nom/logos de l'employeur
  mais probablement pas une adresse postale complète (rue/NPA/localité) — nécessaire
  pour le bloc créancier de la QR-facture. À ajouter si absent.

## 7. Lien avec la comptabilité

- **Rapprochement automatique** : à l'import d'un relevé bancaire (mécanisme existant de
  `lib/compta.php`), une écriture peut être associée à une facture `emise` non payée
  (par montant + indices textuels sur le débiteur/la référence, dans l'esprit de
  `suggerer_categories`). Une facture passe alors `payee` (statut + `payee_le` +
  `ecriture_id`), sans jamais modifier l'écriture existante côté catégorisation.
- Rapprochement également possible **manuellement** depuis la fiche facture ou depuis
  l'écran Écritures.

## 8. Hors périmètre v1 (explicitement écarté ou différé)

- **TVA** : association exonérée, aucun calcul/mention TVA.
- **Notes de crédit / avoirs** : pas en v1 — une facture erronée est `annulee`, une
  nouvelle facture est créée si besoin.
- **Relances automatiques par e-mail** : pas de tâche planifiée (cron) en v1. À la
  place : détection manuelle des factures en retard (liste filtrable) + génération à la
  demande d'un PDF de lettre de rappel.
- **Génération en masse / récurrence** (ex. cotisations annuelles pour tous les
  membres) : pas de moteur de récurrence en v1 ; prévoir un simple bouton « dupliquer »
  une facture existante pour une nouvelle période. *(hypothèse à confirmer si le volume
  de cotisations grossit)*.
- **Paiement partiel** : statut binaire payée/non payée, pas de suivi de solde partiel.
- Multi-devise (CHF uniquement, comme le reste de l'application).

## 9. Structure de code envisagée (à l'image du module compta)

- `lib/facturation.php` — fonctions pures : calcul des totaux, génération numéro,
  génération référence SCOR, statut dérivé, détection retard.
- `lib/routes_facturation.php` — `route_facturation_*`, inclus depuis `index.php` comme
  `routes_compta.php`.
- `views/facturation_liste.php`, `facturation_form.php`, `facturation_voir.php`,
  `facturation_debiteurs.php`, `facturation_debiteur_form.php`.
- Génération PDF : soit une route dédiée renvoyant `application/pdf`, soit un helper
  appelé aussi bien pour le téléchargement que pour la pièce jointe e-mail.
- Migration(s) : nouvelles entrées `$steps` + `migration_N()` pour `debiteurs`,
  `factures`, `facture_lignes`.
- Tests : `tests/facturation_test.php` (numérotation, totaux, référence SCOR, détection
  retard) — même esprit que `calc_test.php`/`compta_test.php`.

## 10. Points ouverts à trancher avant/pendant l'implémentation

1. Adresse postale complète de l'association dans `parametres` : à vérifier/ajouter.
2. Choix précis de la librairie Composer (ex. `sprain/swiss-qr-bill` + TCPDF) — à
   confirmer en regardant licence et maintenance active au moment de coder.
3. Algorithme de rapprochement automatique facture/écriture : règles exactes de
   correspondance (montant exact ? tolérance ? texte de la communication ?) à préciser
   lors du développement, par analogie avec `suggerer_categories`.
4. Faut-il un axe analytique par défaut suggéré selon le type de débiteur (ex.
   organisation → Tour/Label) ou saisie 100% manuelle ligne par ligne ? — à trancher à
   l'usage.
