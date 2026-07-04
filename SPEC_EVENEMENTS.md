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
| `region` | optionnel, canton suisse ou département français (ex. « VD », « 25 ») |
| `pays` | optionnel, champ propre (distinct de `region` : un même code régional se recoupe entre pays, ex. « FR » = canton de Fribourg ou France — les deux champs séparés lèvent l'ambiguïté) |
| `salle` | texte libre, optionnel |
| `festival` | texte libre, optionnel |
| `lien_infos` | URL « plus d'infos », optionnel — validée comme URL à la saisie (doit commencer par `http://`/`https://`) |
| `lien_texte` | texte du bouton de lien (ex. « Réserver »), optionnel — vide à l'export = texte par défaut configurable (§7), sans effet si `lien_infos` est vide |
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
cachet/les conditions varient trop d'un événement à l'autre) — voir §11.

**Plusieurs factures par événement autorisées** : pas de contrainte d'unicité sur
`evenement_id` dans `factures` — couvre le cas d'une facture complémentaire ou d'une
facture annulée puis refaite. La fiche événement liste toutes les factures liées.

## 7. Paramètres — nouvel onglet « Événements »

Ajout d'un onglet `evenements` dans `views/_param_tabs.php` (masqué si le module
`evenements` est désactivé, même logique que les onglets `taux`/`taux_horaires` pour le
module `salaires`). Route `route_parametres_evenements()` dans `lib/routes.php`, vue
`views/parametres_evenements.php`. Contenu :

- **Délai de décompte SUISA** (`suisa_delai_decompte_mois`, défaut `12`) — cf. §5.
- **Texte du bouton de lien par défaut** (`evenements_lien_texte_defaut`, défaut « Plus
  d'informations ») — utilisé à l'export (§8) quand un événement a un `lien_infos` sans
  `lien_texte` propre (saisie manuelle ou import CSV, cf. §10).
- **Export public des événements** — l'app expose elle-même les données, réutilisables
  par le site web associatif ou tout autre système externe (pas l'inverse : ce n'est pas
  le site web qui pousse de la config vers Lasso). Voir §8 pour le détail.

## 8. Export public des événements (JSON + iCal)

Deux **routes publiques en lecture seule**, sans session (ajoutées à `$handlers` dans
`index.php` en dehors des blocs `require_login()`, sur le modèle de la vérification par
jeton déjà utilisée pour `route_setup()` — `hash_equals()` contre un secret stocké côté
serveur, jamais une simple comparaison `===`) :

- **`?p=evenements_json&token=…`** — tableau JSON de tous les événements exposables (voir
  règles de filtrage ci-dessous), pour un site web ou tout autre export.
- **`?p=evenements_ical&token=…`** — flux `text/calendar` (`.ics`), un `VEVENT` par
  événement exposable, pour import direct dans un calendrier (Google Agenda, etc.). Même
  filtrage/mêmes champs que le JSON, adaptés au format iCal (`SUMMARY`, `DTSTART`,
  `LOCATION`, `DESCRIPTION`, `URL`).

### Filtre par spectacle

Paramètre optionnel **`spectacle_id`** sur les deux routes (ex.
`?p=evenements_json&token=…&spectacle_id=3`) : ne renvoie que les événements liés à ce
spectacle. Permet d'avoir un point d'accès dédié par spectacle (ex. une page web
spécifique à une tournée qui n'affiche que son propre calendrier), sans exposer les
événements des autres spectacles. Même **jeton global** pour toutes les URLs (pas de
jeton par spectacle) — l'onglet « Événements » des paramètres (§7) affiche, en plus des
deux URLs générales, un lien « Copier l'URL » par spectacle dans la liste des
spectacles (`spectacle_id` pré-rempli, jeton déjà inclus). Régénérer le jeton global
invalide donc aussi toutes les URLs par spectacle en une fois.

### Jeton d'accès

- Nouveau paramètre `evenements_export_token` : chaîne aléatoire (ex.
  `bin2hex(random_bytes(16))`), générée automatiquement au premier accès à l'onglet des
  paramètres si absente, avec bouton « Régénérer » (invalide l'ancienne URL — utile en
  cas de fuite).
- Pas d'URL générale affichée dans les paramètres : les liens se copient depuis le
  tableau des spectacles (colonne « Synchroniser », icônes JSON/iCal — un clic copie
  l'URL du spectacle concerné dans le presse-papier, jeton déjà inclus).
- Vérification côté route : `hash_equals($tokenStocke, $_GET['token'] ?? '')` ; jeton
  absent/incorrect → `403` sans détail (pas de fuite d'info sur la validité partielle).

### Filtrage et champs exposés

Mêmes règles que l'affichage web (§4), appliquées côté serveur avant export — jamais de
filtrage côté client :

- Exclus : `visibilite = non_repertorie`, et tout événement en `statut = option` (pas
  encore assez sûr pour être publié).
- **`public`** : `date`, `ville`, `region` (si renseignée), `pays` (si renseigné),
  `salle` (si renseignée), `festival` (si renseigné), `lien_infos` + `lien_texte` (si `lien_infos` renseigné —
  `lien_texte` retombe sur le texte par défaut configurable, §7, si vide), nom du
  `spectacle` lié, `remarques`, et un indicateur `annule: true/false` (dérivé de `statut`).
- **`prive`** : uniquement `date` et un indicateur `prive: true` (le JSON/l'iCal ne
  contiennent alors ni ville, ni salle, ni festival, ni lien, ni spectacle, ni
  remarques — le site web affiche par exemple « Événement privé » à la place).
- Jamais exposés, quel que soit le champ : tout ce qui touche SUISA, les liens
  facture/employés/fiches, les remarques internes non destinées au public (les
  `remarques` d'un événement `prive` ne sont donc jamais exportées, seulement celles d'un
  événement `public`).

### Hors périmètre de cet export (v1)

- Pas de pagination/filtre par date dans l'URL (ex. `?depuis=2026-01-01`) — le volume
  d'événements reste faible ; le site web filtre lui-même côté client si besoin.
- Pas d'authentification autre que le jeton partagé (pas d'OAuth, pas de rotation
  automatique) — cohérent avec le volume et le nombre d'utilisateurs du projet.

## 9. Lien avec les employés et les fiches de salaire

- Un événement peut être lié à plusieurs employés (`evenement_employes`) — utile pour
  savoir qui était engagé sur une date, indépendamment de la fiche de salaire déjà émise
  ou non.
- Un événement peut être lié à plusieurs fiches de salaire, et une fiche peut couvrir
  plusieurs événements (`evenement_fiches`) — cas d'un cachet regroupant une tournée.

## 10. Import CSV d'un agenda de tournée

Onglet Paramètres → Importer (masqué si le module est désactivé, même page partagée
que les imports fiches/factures/écritures). Colonnes attendues (n'importe quel ordre,
seules `date`/`ville` obligatoires) : `date, ville, region, pays, lieu, details, type,
statut, lien, lien_texte` — format déjà utilisé pour l'agenda de tournée externe.

- **`date`** au format `JJ/MM/AAAA` (pas `AAAA-MM-JJ`) ; une date invalide ou non
  reconnue (ex. « TBA/2027 ») est une ligne en erreur, jamais devinée.
- **`region`** → `region`, **`pays`** → `pays` (champs propres, cf. §3 — plus de repli
  dans les remarques).
- **`lieu`** → `salle`, **`details`** → `remarques`, **`lien`** → `lien_infos` (ignoré
  s'il n'est pas une URL `http(s)://` valide). **`lien_texte`** → `lien_texte`, le texte
  du bouton de lien (ex. « Réserver ») ; ignoré si `lien` est absent/invalide. Si vide,
  l'export utilise le texte par défaut configurable (onglet Paramètres → Événements,
  « Plus d'informations » par défaut — voir `evenements_lien_texte_defaut()`).
- **`type`** est rapproché d'un spectacle existant par nom normalisé (casse/espaces/
  ponctuation ignorés, ex. « anticoncert » ↔ « Anti-concert ») ; à défaut de
  correspondance, un nouveau spectacle est créé à la volée avec le nom brut du CSV.
- **Déduplication** : un événement déjà présent à la même date/ville/salle (comparaison
  insensible à la casse) est ignoré, jamais réécrasé — même logique que l'import
  historique des factures (§ correspondante dans SPEC_FACTURATION.md).
- **Visibilité toujours `non_repertorie`** à l'import, quel que soit le contenu du CSV —
  relecture manuelle obligatoire avant de publier une date importée en masse.
- **Statut par défaut `confirme`** si la colonne `statut` du CSV est vide ou non reconnue
  (cohérent avec un agenda de tournée déjà engagé — liens de réservation, salles
  annoncées) ; `option`/`annule` reconnus si présents.
- Simulation obligatoire avant import réel (bouton « Simuler » vs « Importer »), même
  ergonomie que les imports fiches/factures existants.

## 11. Hors périmètre v1 (explicitement écarté ou différé)

- **Pré-remplissage automatique des lignes de facture** depuis l'événement (cachet type,
  frais standards) : à considérer plus tard si le besoin se confirme.
- **Salles/festivals comme fiches réutilisables** : champs texte libre en v1, pas de
  table dédiée (faible volume, peu de risque de doublon gênant).
- **Notifications/rappels automatiques** pour les envois SUISA en attente ou les dates
  « manquantes » : pas de tâche planifiée en v1 — détection par une liste filtrable dans
  l'écran événements (même esprit que les factures en retard).
- **Import en masse d'événements** (ex. calendrier de tournée externe) : saisie manuelle
  en v1.
- **Page publique du site web elle-même** (rendu HTML du calendrier) : Lasso expose les
  données (JSON/iCal, §8), mais leur mise en forme visuelle sur le site associatif est
  hors périmètre de cette app — à implémenter côté site web, en consommant l'export.

## 12. Structure de code envisagée (à l'image des modules existants)

- `lib/evenements.php` — fonctions pures : statut SUISA dérivé (5 valeurs, §5), règles
  de visibilité/statut pour l'affichage public (§4), et la fonction de filtrage/mise en
  forme partagée par les deux routes d'export (§8 → JSON et iCal doivent utiliser la
  même liste filtrée, pas deux implémentations divergentes).
- `lib/helpers.php` — nouvelle fonction `handle_pdf_upload()` (ou généralisation de
  `handle_logo_upload()` avec un paramètre de type de fichier accepté), pour l'upload de
  la feuille SUISA sur `spectacles`.
- `lib/routes_evenements.php` — `route_evenements_*` (écrans authentifiés), inclus depuis
  `index.php` comme `routes_facturation.php`, **plus** `route_evenements_json()` et
  `route_evenements_ical()` (routes publiques par jeton, sans `require_login()`, cf. §8).
- `views/evenements_liste.php`, `evenements_form.php`, `evenements_voir.php`,
  `views/spectacles_liste.php`, `spectacle_form.php`, `views/parametres_evenements.php`.
- Migration(s) : nouvelles entrées `$steps` + `migration_N()` pour `spectacles`,
  `evenements`, `evenement_employes`, `evenement_fiches`, et l'ajout de la colonne
  `evenement_id` sur `factures`.
- Tests : `tests/evenements_test.php` (statut SUISA dérivé sur les 5 valeurs, règles de
  visibilité/statut, filtrage de l'export JSON/iCal — en particulier qu'un événement
  `prive` ou `non_repertorie` ne fuite jamais un champ interdit) — même esprit que
  `calc_test.php`/`compta_test.php`.

## 13. Points ouverts restants

Les points cadrés lors des itérations précédentes (délai SUISA configurable et son
emplacement — onglet « Événements » des paramètres, §7 —, statut `option`/`confirme`/
`annule` séparé de la visibilité, validation URL de `lien_infos`, plusieurs factures par
événement, upload de la feuille SUISA par spectacle avec validation mime stricte, filtre
par statut SUISA à 5 valeurs, export public JSON/iCal par jeton §8) sont maintenant
actés ci-dessus. Reste à trancher :

1. Format exact des dates/heures dans le JSON exposé (ex. `date` seule au format
   `AAAA-MM-JJ`, ou faut-il une heure de concert distincte de la date ? — aucune heure
   n'a été demandée jusqu'ici dans le modèle de données, à confirmer avant de coder
   l'export).
2. Nom d'affichage du spectacle dans l'export : le nom brut de `spectacles.nom` suffit-il,
   ou faut-il un champ distinct (ex. `titre_public`) si le nom interne diffère du nom
   commercial affiché au public ?
