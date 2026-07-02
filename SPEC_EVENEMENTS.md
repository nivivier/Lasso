# Spécification — Module Événements

Statut : **à valider avant implémentation**. Ce document résume le besoin cadré avec
l'utilisateur ; il sert de référence pour l'implémentation à venir, pas un plan de code figé.

## 1. Objectif

Gérer les dates de concert/spectacle de l'association : suivi de la diffusion publique
(site web), suivi des déclarations SUISA, et lien vers la facturation et les salaires.
Petit volume (même esprit que les autres modules), 1–2 utilisateurs.

## 2. Nouveau module `evenements`

S'ajoute au registre `lib/modules.php` (voir `MODULES`), **sans dépendance**
(`requires: []`) — le module fonctionne seul. Les actions « créer une facture liée » ou
« lier une fiche de salaire » ne s'affichent que si les modules `facturation`/`salaires`
sont actifs, mais l'absence de ces modules ne désactive pas `evenements` (le schéma de
base est de toute façon toujours créé en entier, cf. commentaire en tête de
`lib/modules.php`).

## 3. Modèle de données (nouvelles tables, migration versionnée)

### `spectacles`
Liste réutilisable (comme `employes`/`debiteurs`), pour éviter les doublons/fautes de
frappe et pouvoir filtrer/regrouper les événements par spectacle.

| champ | notes |
|---|---|
| `id` | |
| `nom` | |
| `notes` | libre |
| `suisa_feuille_fichier` | chemin web relatif (`uploads/…`) vers la feuille SUISA pré-remplie du spectacle (PDF), vide si aucune |
| `cree_le` | |

**Upload de la feuille SUISA** : un seul fichier PDF par spectacle (pas par événement —
la feuille pré-remplie décrit l'œuvre/le programme, commun à toutes les dates du même
spectacle). Réutilise le mécanisme d'upload existant (`handle_logo_upload()` dans
`lib/helpers.php`) adapté au PDF : même limite de taille (2 Mo), même stockage dans
`uploads/` (web-servi, scripts bloqués par `.htaccess`), mais validation **mime stricte**
via `finfo_file($f['tmp_name'])` = `application/pdf` (plutôt qu'une simple lecture de
l'en-tête `%PDF-`) — même niveau de rigueur que `getimagesize()` qui valide le contenu
réel, pas juste l'extension. Le fichier est téléchargeable depuis la fiche spectacle (et
depuis chaque événement qui lui est lié, en lecture seule).

### `evenements`

| champ | notes |
|---|---|
| `id` | |
| `spectacle_id` | FK nullable → `spectacles` |
| `date` | date de l'événement |
| `statut` | `option` / `confirme` / `annule` — indépendant de la visibilité (ex. une date `public` peut passer `annule` sans changer de visibilité, cf. §4) |
| `visibilite` | `public` / `prive` / `non_repertorie` |
| `ville` | texte libre |
| `salle` | texte libre, optionnel |
| `festival` | texte libre, optionnel |
| `lien_infos` | URL « plus d'infos », optionnel — validée comme URL à la saisie (doit commencer par `http://`/`https://`) |
| `remarques` | texte libre |
| `suisa_applicable` | booléen, défaut `1` — à `0` si la SUISA ne s'applique pas à cette date |
| `suisa_envoye_a` | `suisa` / `organisateur` / vide (pas encore envoyé) |
| `suisa_envoye_le` | date d'envoi, nullable |
| `suisa_decompte_le` | date d'apparition dans un décompte SUISA, nullable |
| `cree_le` | |

### `evenement_employes`
Table de jointure many-to-many (un événement peut concerner plusieurs employés ; un
employé peut être lié à plusieurs événements).

| champ | notes |
|---|---|
| `evenement_id` | FK → `evenements` |
| `employe_id` | FK → `employes` |

Clé primaire composite `(evenement_id, employe_id)`.

### `evenement_fiches`
Table de jointure many-to-many — une fiche de salaire peut être concernée par plusieurs
événements (ex. cachet regroupant plusieurs dates), et un événement peut impliquer
plusieurs fiches.

| champ | notes |
|---|---|
| `evenement_id` | FK → `evenements` |
| `fiche_id` | FK → `fiches` |

Clé primaire composite `(evenement_id, fiche_id)`.

### `factures` (module facturation) — ajout
Ajout d'une colonne `evenement_id` (FK nullable → `evenements`), renseignée quand la
facture est créée depuis un événement. Migration idempotente sur la table existante
(même mécanisme que `email_envoye_le` sur `fiches`, cf. `migration_4`).

## 4. Statut, visibilité & affichage sur le site web

Le **statut** (`option`/`confirme`/`annule`) et la **visibilité**
(`public`/`prive`/`non_repertorie`) sont deux axes indépendants : une date peut être
`public` + `annule` (ex. concert annoncé puis annulé — on veut le signaler aux gens qui
avaient vu l'annonce, pas juste la faire disparaître).

- **`public`** : affiché sur le site avec date, ville, nom de la salle (si renseigné),
  nom du festival (si renseigné), lien « plus d'infos » (si renseigné), spectacle
  concerné, remarques. Si `statut = annule`, la date reste affichée mais marquée
  « Annulé ». Si `statut = option`, la date n'est pas encore assez sûre pour être
  publiée : traitée comme si elle n'était pas publique tant qu'elle n'est pas
  `confirme` ou `annule`.
- **`prive`** : affiché avec seulement la date et la mention « Événement privé » — aucune
  autre information (pas de ville/salle/festival/lien). Même traitement du statut
  `option`/`annule` que ci-dessus (pas affiché tant qu'en `option` ; affiché comme
  annulé si `annule`).
- **`non_repertorie`** : n'apparaît pas du tout sur le site web, quel que soit le statut
  (usage interne uniquement — planification, suivi SUISA, facturation, paie).

## 5. Suivi SUISA

- `suisa_applicable` permet de sortir un événement du suivi (ex. répétition privée,
  date à l'étranger hors périmètre) — s'il est à `0`, le statut dérivé est toujours
  « ne s'applique pas », quels que soient les autres champs.
- Envoi noté avec destinataire (`suisa` directement ou `organisateur`) + date d'envoi.
- Apparition dans un décompte notée avec sa date (`suisa_decompte_le`).
- **Délai configurable** : nouveau paramètre (ex. `suisa_delai_decompte_mois`, défaut
  `12`) dans les paramètres généraux — même esprit que le délai d'échéance par défaut des
  factures. Permet d'ajuster si le rythme de décompte SUISA change sans toucher au code.

### Statut SUISA dérivé (filtrable dans la liste des événements)

Comme le statut « en retard » des factures, entièrement **dérivé** (pas stocké) à partir
de `suisa_applicable`, `suisa_envoye_le`, `suisa_decompte_le` et du délai configurable.
Cinq valeurs, calculées par ordre de priorité :

| statut | condition |
|---|---|
| **Ne s'applique pas** | `suisa_applicable = 0` |
| **Décompte reçu** | `suisa_decompte_le` renseignée |
| **Manquant** | `suisa_envoye_le` renseignée, pas de décompte, délai dépassé |
| **Envoyé** | `suisa_envoye_le` renseignée, pas de décompte, délai non dépassé |
| **À faire** | `suisa_envoye_le` vide (et `suisa_applicable = 1`) |

L'écran liste des événements permet de **filtrer par ce statut** (menu déroulant, même
esprit que le filtre de statut sur la liste des factures), pour retrouver rapidement les
dates « à faire » ou « manquantes ».

## 6. Lien avec la facturation

Depuis la fiche d'un événement, bouton « Créer une facture liée » (visible seulement si
le module `facturation` est actif) : crée une facture en brouillon avec
`evenement_id` renseigné. Pas de pré-remplissage automatique des lignes en v1 (le
cachet/les conditions varient trop d'un événement à l'autre) — voir §8.

**Plusieurs factures par événement autorisées** : pas de contrainte d'unicité sur
`evenement_id` dans `factures` — couvre le cas d'une facture complémentaire ou d'une
facture annulée puis refaite. La fiche événement liste toutes les factures liées.

## 7. Paramètres — nouvel onglet « Événements »

Ajout d'un onglet `evenements` dans `views/_param_tabs.php` (masqué si le module
`evenements` est désactivé, même logique que les onglets `taux`/`taux_horaires` pour le
module `salaires`). Route `route_parametres_evenements()` dans `lib/routes.php`, vue
`views/parametres_evenements.php`. Contenu :

- **Délai de décompte SUISA** (`suisa_delai_decompte_mois`, défaut `12`) — cf. §5.
- **Configuration du lien avec le site web** — *à réfléchir*, réservé dans cet onglet
  mais non spécifié en détail ici. Le besoin (§4) : le site web externe doit pouvoir
  récupérer les événements `public`/`prive` à afficher. Pistes possibles à évaluer avant
  implémentation :
  - **Endpoint JSON en lecture seule** (ex. `?p=evenements_api&token=…`) protégé par un
    jeton secret stocké dans `parametres` (généré ici, à coller côté site web) — pas de
    session/cookie possible entre les deux systèmes.
  - **Export statique régénéré** (fichier JSON/ICS écrit sur disque à chaque
    modification, servi directement par le serveur web) — évite d'exposer une route PHP
    dynamique côté site public.
  - Dans les deux cas, seuls les champs autorisés par la visibilité (§4) doivent être
    exposés — jamais les champs internes (SUISA, liens facture/employés/fiches).
  - Cette section n'est **pas à coder en v1** tant que le choix n'est pas arrêté ; prévoir
    juste l'emplacement dans l'onglet des paramètres pour ne pas avoir à en créer un
    nouveau plus tard.

## 8. Lien avec les employés et les fiches de salaire

- Un événement peut être lié à plusieurs employés (`evenement_employes`) — utile pour
  savoir qui était engagé sur une date, indépendamment de la fiche de salaire déjà émise
  ou non.
- Un événement peut être lié à plusieurs fiches de salaire, et une fiche peut couvrir
  plusieurs événements (`evenement_fiches`) — cas d'un cachet regroupant une tournée.

## 9. Hors périmètre v1 (explicitement écarté ou différé)

- **Pré-remplissage automatique des lignes de facture** depuis l'événement (cachet type,
  frais standards) : à considérer plus tard si le besoin se confirme.
- **Salles/festivals comme fiches réutilisables** : champs texte libre en v1, pas de
  table dédiée (faible volume, peu de risque de doublon gênant).
- **Notifications/rappels automatiques** pour les envois SUISA en attente ou les dates
  « manquantes » : pas de tâche planifiée en v1 — détection par une liste filtrable dans
  l'écran événements (même esprit que les factures en retard).
- **Import en masse d'événements** (ex. calendrier de tournée externe) : saisie manuelle
  en v1.
- **Page publique du site web elle-même** (rendu HTML du calendrier public) : cette
  spec couvre le modèle de données et les règles d'affichage ; l'implémentation de la
  page publique (route, gabarit) reste à cadrer séparément.

## 10. Structure de code envisagée (à l'image des modules existants)

- `lib/evenements.php` — fonctions pures : statut SUISA dérivé (5 valeurs, §5), règles
  de visibilité/statut pour l'affichage public (§4).
- `lib/helpers.php` — nouvelle fonction `handle_pdf_upload()` (ou généralisation de
  `handle_logo_upload()` avec un paramètre de type de fichier accepté), pour l'upload de
  la feuille SUISA sur `spectacles`.
- `lib/routes_evenements.php` — `route_evenements_*`, inclus depuis `index.php` comme
  `routes_facturation.php`.
- `views/evenements_liste.php`, `evenements_form.php`, `evenements_voir.php`,
  `views/spectacles_liste.php`, `spectacle_form.php`.
- Migration(s) : nouvelles entrées `$steps` + `migration_N()` pour `spectacles`,
  `evenements`, `evenement_employes`, `evenement_fiches`, et l'ajout de la colonne
  `evenement_id` sur `factures`.
- Tests : `tests/evenements_test.php` (statut SUISA dérivé sur les 5 valeurs, règles de
  visibilité/statut) — même esprit que `calc_test.php`/`compta_test.php`.

## 11. Points ouverts restants

Les points cadrés lors des itérations précédentes (délai SUISA configurable et son
emplacement — onglet « Événements » des paramètres, §7 —, statut `option`/`confirme`/
`annule` séparé de la visibilité, validation URL de `lien_infos`, plusieurs factures par
événement, upload de la feuille SUISA par spectacle avec validation mime stricte, filtre
par statut SUISA à 5 valeurs) sont maintenant actés ci-dessus. Reste à trancher :

1. **Configuration du lien avec le site web** (§7) : le mécanisme exact (endpoint JSON
   protégé par jeton, export statique régénéré, ou autre) n'est pas encore choisi —
   explicitement différé, réservé dans l'onglet des paramètres mais pas à coder en v1
   tant que le choix n'est pas arrêté.
