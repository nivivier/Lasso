# Spécification — Module Booking (structures / CRM)

Statut : **à valider avant implémentation**. Ce document propose un cadrage du besoin ;
il sert de référence pour l'implémentation à venir, pas un plan de code figé.

## 1. Objectif

Dernier module métier de l'application : gérer le carnet d'adresses des **structures**
qui programment des concerts (salles, festivals, médias, entourage professionnel), avec
une logique **CRM** — suivi du dernier contact, historique de notes, mailing ciblé — et
la possibilité d'**importer des listes existantes** (carnets d'adresses hétérogènes,
non normalisés).

Réutilise et **renomme** le module `debiteurs` de la facturation (`organisation`,
`particulier`) en `structures`, en l'ouvrant à toute organisation utile au booking, pas
seulement celles ayant déjà une facture. Petit volume (dans le même esprit que les
autres modules), 1–2 utilisateurs.

Fichier d'exemple fourni par l'utilisateur : `Souka - Carnet d_adresses.xlsx` (1 780
lignes, colonnes non normalisées, plusieurs onglets) — analysé en détail au §9 pour
calibrer le modèle de données et l'import.

## 2. Nouveau module `booking`

S'ajoute au registre `lib/modules.php` (voir `MODULES`). **Dépendance sur aucun module**
(`requires: []`), mais **`facturation` dépend implicitement de la table `structures`**
puisqu'elle est le renommage de `debiteurs` — voir §3 : la table existe toujours même si
`booking` est désactivé (même logique que le commentaire en tête de `lib/modules.php` :
le schéma de base est de toute façon toujours créé en entier). Si `facturation` est
actif sans `booking`, les écrans de facturation continuent de fonctionner sur
`structures` exactement comme avant sur `debiteurs` (liste, formulaire, sélection sur
une facture) — seuls les écrans propres au CRM (notes, tags, mailing, import) sont
masqués.

## 3. Renommage `debiteurs` → `structures` : renommage complet (table + colonnes)

**Décision actée** : renommage intégral, pas seulement la table — les colonnes qui la
référencent aussi. `factures.debiteur_id` → `factures.structure_id`,
`evenements.organisateur_debiteur_id` → `evenements.organisateur_structure_id`. Plus de
code à toucher qu'un simple renommage de table, mais cohérence complète du vocabulaire
dans tout le schéma — pas de colonne `debiteur_id` résiduelle pointant vers une table
`structures`.

- ⚠️ **Risque déjà documenté dans `CLAUDE.md`** : recréer/renommer une table référencée
  par clé étrangère est piégeux sous SQLite (`PRAGMA foreign_keys = OFF` ne suffit pas
  à empêcher SQLite de réécrire les clauses `REFERENCES` d'autres tables — cf.
  `migration_21`). Séquence de migration proposée, à valider empiriquement puis avec
  `PRAGMA foreign_key_check` avant d'écrire la version définitive :
  1. `ALTER TABLE debiteurs RENAME TO structures` (renommage simple de la table, sans
     étape intermédiaire `x_old` — c'est spécifiquement la recréation avec schéma
     modifié en passant par un renommage temporaire qui est piégeuse, pas un renommage
     direct).
  2. Ajout des nouvelles colonnes sur `structures` via `ALTER TABLE … ADD COLUMN`
     (pattern déjà utilisé partout ailleurs dans `lib/db.php`).
  3. Pour renommer les colonnes `debiteur_id`/`organisateur_debiteur_id` dans
     `factures`/`evenements` (SQLite ne sait renommer une colonne qui est aussi une FK
     sans risque de la même classe de bug) : appliquer le pattern sûr déjà éprouvé —
     table `_new` avec le nouveau nom de colonne, copie des données, `DROP` de
     l'ancienne table, renommage de la `_new`. Vérifier `PRAGMA foreign_key_check`
     après chaque étape.
- Toutes les vues/fonctions `debiteur*` (`route_facturation_debiteurs`,
  `views/facturation_debiteur_form.php`, libellés « Débiteur » dans les menus, etc.)
  sont renommées `structure*` en cohérence — recherche/remplacement large dans
  `lib/routes_facturation.php`, `lib/facturation.php`, `views/`, `index.php`.
- Le vocabulaire change dans l'UI facturation : « Débiteur » devient « Structure » —
  cohérent car une structure de booking peut aussi être un débiteur (ex. facturer un
  festival), et inversement.

## 4. Modèle de données (nouvelles tables + colonnes, migration versionnée)

### `structures` (ex-`debiteurs`, colonnes ajoutées)

| champ | notes |
|---|---|
| *(colonnes existantes inchangées)* | `type` (`organisation`/`particulier`), `nom`, `adresse_*`, `email`, `telephone`, `personne_contact`, `notes`, `actif`, `cree_le` |
| `categorie` | **nouvelle** — `organisateur` / `media` / `autres` / `entourage`, catégorie à part entière (voir §5, décidé — pas de fusion `entourage`/`autres`). Nom délibérément différent de `type` (collision évitée) |
| `departement_canton` | **nouvelle** (renommée depuis `region`, voir CHANGELOG 1.11.0) — texte libre (ex. « 35 », « GE »), sert de critère de filtre mailing (§7) ; alimentée par l'import (§9, référentiel départements→région) |
| `site_web` | **nouvelle** — URL du site de la structure (champ dédié, voir §9 mapping `Site`) |
| `dernier_contact_le` | **nouvelle**, dérivée — voir §6, jamais éditée directement en formulaire |
| `desinscrit` | **nouvelle**, booléen — exclusion mailing (opt-out, §7) |

`personne_contact`/`telephone` (colonnes actuelles, texte libre unique) restent en v1
comme **contact principal de repli** pour compatibilité factures existantes, mais le
CRM introduit des contacts multiples structurés — voir `structure_contacts` ci-dessous.
Migration : les valeurs actuelles de `personne_contact`/`telephone` sont copiées comme
premier contact dans `structure_contacts` (script de migration, une seule fois).

### `structure_contacts`
Une structure peut avoir plusieurs interlocuteurs (le carnet fourni en montre plusieurs
par lieu — programmateur, direction, etc.).

| champ | notes |
|---|---|
| `id` | |
| `structure_id` | FK → `structures`, `ON DELETE CASCADE` |
| `prenom` / `nom` | |
| `role` | texte libre (ex. « Programmation », « Direction ») |
| `email` | |
| `telephone` | |
| `formulaire_url` | **nouvelle**, champ dédié — URL d'un formulaire de contact/soumission propre à cet interlocuteur (fréquent dans le carnet fourni, remplace parfois l'e-mail direct comme canal de contact) |
| `langue` | code court, optionnel (ex. `FR`) — utile pour un mailing multilingue futur, pas de logique v1 dessus |
| `desinscrit` | opt-out **par contact** (un contact peut se désinscrire sans désactiver toute la structure) |
| `actif` | pour un contact qui a quitté la structure sans le supprimer (garde l'historique) |
| `cree_le` | |

### `lieux` (salles / festivals)
Entité distincte de `structures` : une structure (association, tourneur, régie) peut
organiser/gérer plusieurs salles ou festivals, et à l'inverse un même festival peut
changer d'organisateur au fil des années — d'où la liaison many-to-many plutôt qu'un
simple champ sur `structures`.

| champ | notes |
|---|---|
| `id` | |
| `type` | `salle` / `festival` |
| `nom` | |
| `ville` / `departement_canton` / `pays` | optionnels, mêmes conventions que `evenements` |
| `mois_debut` / `mois_fin` | optionnels, entiers 1–12 — période de programmation pour un festival (ex. avril→septembre), sert de critère de filtre mailing (§7) ; sans objet pour une `salle` |
| `notes` | libre |
| `cree_le` | |

### `structure_lieux`
Table de jointure many-to-many.

| champ | notes |
|---|---|
| `structure_id` | FK → `structures`, `ON DELETE CASCADE` |
| `lieu_id` | FK → `lieux`, `ON DELETE CASCADE` |

Clé primaire composite `(structure_id, lieu_id)`. Pas de champ « rôle » sur le lien
(décidé) — le lien signifie simplement « cette structure est associée à ce lieu ».

### `structure_tags` + `structure_tag_liens`
Étiquettes libres et réutilisables (même esprit que `spectacles`/`axes_analytiques` :
une liste de référence plutôt qu'un champ texte libre par structure, pour fiabiliser le
filtre mailing). Couvre le besoin « structures marquées comme intéressantes pour des
premières parties », mais aussi tout autre tag futur sans modification de schéma.

| `structure_tags` | notes |
|---|---|
| `id` | |
| `nom` | unique |

| `structure_tag_liens` | notes |
|---|---|
| `structure_id` | FK → `structures`, `ON DELETE CASCADE` |
| `tag_id` | FK → `structure_tags`, `ON DELETE CASCADE` |

Clé primaire composite `(structure_id, tag_id)`. Saisie via un champ à autocomplétion
(tags existants) + création à la volée d'un nouveau tag si non trouvé.

### `structure_notes`
Historique de notes en flux chronologique (le cœur du CRM).

| champ | notes |
|---|---|
| `id` | |
| `structure_id` | FK → `structures`, `ON DELETE CASCADE` |
| `contenu` | texte libre |
| `est_contact` | booléen — coché si la note représente une prise de contact (appel, e-mail, rencontre) ; alimente `dernier_contact_le` (§6) |
| `cree_le` | horodatage, sert aussi de date d'affichage dans le flux |
| `utilisateur_id` | FK → `utilisateurs`, nullable — auteur, affiché dans le flux (utile même à 2 utilisateurs pour savoir qui a noté quoi) |

Pas de modification/suppression d'une note passée en v1 (flux = journal, cohérent avec
l'esprit « historique figé » déjà appliqué aux fiches de salaire) — seule l'ajout d'une
nouvelle note est possible ; une correction se fait en ajoutant une note suivante.

### `mailing_file_attente`
File d'attente d'une campagne, consommée par lots au rythme configuré (§7) — une
campagne ne s'envoie jamais en une seule requête HTTP.

| champ | notes |
|---|---|
| `id` | |
| `structure_id` | FK → `structures` |
| `contact_id` | FK nullable → `structure_contacts` |
| `sujet` | déjà personnalisé (variables résolues à la création de la file) |
| `corps` | déjà personnalisé |
| `statut` | `attente` / `envoye` / `echec` |
| `cree_le` | |

### `mailing_envois`
Journal définitif des mailings traités (succès ou échec) — alimente à la fois
l'historique CRM (visible dans le flux d'une structure) et `dernier_contact_le`.

| champ | notes |
|---|---|
| `id` | |
| `structure_id` | FK → `structures` |
| `contact_id` | FK nullable → `structure_contacts` (destinataire précis si connu) |
| `sujet` | |
| `destinataire_email` | copie de l'e-mail utilisé (traçable même si le contact change ensuite) |
| `succes` | booléen |
| `envoye_le` | |

### Paramètres (`parametres`, pas de nouvelle table)

- `smtp_booking_host` / `smtp_booking_port` / `smtp_booking_secure` /
  `smtp_booking_user` / `smtp_booking_pass` — profil SMTP dédié au booking, facultatif
  (repli sur le profil SMTP général si vide, §7).
- `mailing_delai_secondes` — défaut `10`.
- `mailing_max_par_jour` — défaut `200`.
- `mailing_traiter_token` — jeton pour la route publique de traitement de la file
  d'attente (§7), généré automatiquement au premier accès comme
  `evenements_export_token`, avec bouton « Régénérer ».

### Référentiel régions (optionnel, alimente `departement_canton`)
Petite table statique `departements_regions` (`code`, `departement`, `region`), reprise
de l'onglet « Régions » du fichier fourni (102 lignes, France) — sert à normaliser le
champ `departement_canton` d'une structure à partir d'un code département lors de l'import (§9),
pour que le filtre mailing par région administrative (ex. « Bretagne ») fonctionne même
si la source n'indique qu'un département ou un code postal. Peuplée une fois à la
migration, comme `TAUX_DEFAUT` pour les taux de calcul.

## 5. Catégorisation — attention à la collision de nom

`debiteurs.type` existe déjà (`organisation` / `particulier`, forme juridique pour la
facturation). La demande porte sur un **autre axe**, orthogonal : `organisateur` /
`media` / `autres` / `entourage` (nature de la structure pour le booking, quatre
catégories à part entière — décidé, pas de fusion `entourage`↔`autres`). D'où le choix
du nom **`categorie`** plutôt que de réutiliser/étendre `type` — les deux colonnes
coexistent et n'ont aucune valeur en commun. Une structure « organisation » (forme
juridique) peut être `categorie = media` (ex. une radio associative), et une structure
« particulier » peut être `categorie = entourage` (ex. un contact entourage
indépendant) : indépendance totale confirmée par les données du fichier fourni (§9 : le
carnet mélange librement salles, festivals, radios, presse, et contacts « entourage »
sous une seule liste).

## 6. CRM — dernier contact & flux de notes

- **`dernier_contact_le`** n'est jamais saisi directement : dérivé automatiquement au
  `MAX()` des dates de :
  - toute `structure_notes.cree_le` où `est_contact = 1` ;
  - tout `mailing_envois.envoye_le` réussi (`succes = 1`) pour cette structure.
  Recalculé (colonne dénormalisée, mise à jour à l'écriture — même esprit que
  `montant_total` sur `factures`) à chaque ajout de note-contact ou envoi de mailing,
  plutôt que recalculée à la volée à chaque affichage de liste (liste de structures
  potentiellement filtrée/triée par ce champ).
- **Flux** sur la fiche structure : liste chronologique inversée (plus récent en haut)
  mélangeant notes manuelles et envois de mailing (icône distincte), dans l'esprit d'un
  flux d'activité CRM classique.
- La liste des structures peut être triée/filtrée par « dernier contact » (ex. « jamais
  contactées », « pas contactées depuis plus de 12 mois ») — filtre également réutilisé
  par le mailing ciblé (§7).

## 7. Mailing personnalisé

### Filtres (constitution de la liste de destinataires)

Combinables (ET logique) :
- `categorie` (organisateur / media / autres) ;
- tag(s) (`structure_tags`) — ex. « intéressant pour premières parties » ;
- département/canton (`structures.departement_canton`) ;
- période de festival : `lieux.mois_debut`/`mois_fin` (via `structure_lieux`, `type =
  festival`) chevauchant une plage de mois donnée (ex. avril→septembre) ;
- dernier contact (jamais / avant telle date) ;
- **exclusion automatique et non contournable** des structures/contacts
  `desinscrit = 1` — appliquée en dernière étape, après tous les autres filtres, pas une
  case à décocher.

Le filtre produit une liste prévisualisable (nombre de destinataires + aperçu des
premières lignes) avant tout envoi — même esprit que les simulations d'import déjà en
place ailleurs dans l'appli.

### Personnalisation & gabarit

- Un gabarit = sujet + corps (texte, variables `{{prenom}}`, `{{nom_structure}}`
  disponibles), pas de bibliothèque de gabarits multiples en v1 (rédigé à chaque envoi,
  cf. §11 hors périmètre pour la gestion de gabarits enregistrés).
- Envoi individuel par destinataire — **pas d'un seul e-mail avec tous les destinataires
  en copie**.
- Lien de désinscription en pied de chaque e-mail : URL publique par jeton (même
  mécanisme que `evenements_json`/`evenements_ical`, §8 de `SPEC_EVENEMENTS.md`) —
  `hash_equals()` contre un jeton par contact/structure, sans connexion requise, positionne
  `desinscrit = 1` sans confirmation supplémentaire (norme habituelle des liens de
  désabonnement).
- Chaque envoi (réussi ou non) crée une ligne `mailing_envois` (§4), visible dans le
  flux CRM de la structure concernée.

### SMTP dédié, sans repli sur `mail()`

**Décision actée** : le mailing booking n'utilise **jamais** `mail()`, contrairement à
`envoyer_email()` (`lib/helpers.php`) qui y a recours en repli pour les fiches/factures
quand aucun SMTP n'est configuré. Pour le booking, l'absence de SMTP configuré est un
**blocage explicite** (message d'erreur clair dans l'écran mailing), pas un repli
silencieux — cohérent avec le besoin de maîtriser le débit d'envoi (impossible à garantir
via `mail()`, qui dépend entièrement des réglages du serveur d'hébergement).

- **Second profil SMTP indépendant**, configurable dans l'onglet Paramètres → E-mails
  (`views/emails.php`), à côté du profil existant (utilisé aujourd'hui pour
  fiches/factures) : nouveaux champs `smtp_booking_host`, `smtp_booking_port`,
  `smtp_booking_secure`, `smtp_booking_user`, `smtp_booking_pass`. **Champs facultatifs**
  — si laissés vides, le mailing booking retombe sur le profil SMTP général (même
  logique de repli champ par champ que `smtp_config()` aujourd'hui entre `parametres` et
  les constantes de `config.local.php`). Permet d'utiliser une boîte d'envoi dédiée pour
  le booking (volume/réputation distincts d'une boîte RH) sans y être obligé.
  Nouvelle fonction `smtp_config_booking(): array`, même forme que `smtp_config()`.
- **Débit d'envoi**, deux réglages dans le même onglet, stockés dans `parametres` :
  - `mailing_delai_secondes` — pause entre deux e-mails, **défaut 10**.
  - `mailing_max_par_jour` — plafond glissant sur 24h, **défaut 200**. Calculé en
    comptant les lignes `mailing_envois` avec `succes = 1` et `envoye_le` dans les
    dernières 24h à l'instant de l'envoi ; au-delà, l'envoi s'arrête et les destinataires
    restants demeurent en file (voir ci-dessous), repris automatiquement dès que le
    plafond glissant redescend.
- **Contrainte d'exécution PHP** : avec un délai de 10 s et jusqu'à 200 destinataires,
  une campagne complète prend jusqu'à ~33 minutes — **incompatible avec une seule
  requête HTTP** sur un hébergement mutualisé (limite habituelle de `max_execution_time`
  bien plus courte). L'envoi ne peut donc pas être une simple boucle synchrone dans la
  route qui traite le clic « Envoyer ». Modèle proposé, calibré sur le planificateur de
  tâches Infomaniak (hébergeur cible — [confirmé par
  l'utilisateur](https://www.infomaniak.com/fr/support/faq/2161/planifier-des-taches-sur-hebergement-web)) :
  - Créer une campagne = générer une **file d'attente** (`mailing_file_attente` : id,
    structure_id, contact_id nullable, sujet et corps déjà personnalisés, statut
    `attente`/`envoye`/`echec`, `cree_le`) plutôt que d'envoyer immédiatement.
  - **Traitement par lots via une route publique par jeton**, pas un script `cli/` : le
    planificateur Infomaniak déclenche une **URL** (pas d'exécution PHP en ligne de
    commande garantie sur cette offre), avec une granularité **minimale de 15
    minutes** (confirmé — pas de cron à la minute sur du mutualisé). Route
    `?p=mailing_traiter&token=…` (même mécanisme de jeton que
    `evenements_json`/`evenements_ical`, `hash_equals()`, sans session), configurée une
    fois dans Manager Infomaniak → Outils avancés → Planificateur de tâches, appelée
    toutes les 15 minutes.
  - À chaque appel, la route traite les destinataires en attente **en boucle avec
    `sleep(mailing_delai_secondes)` entre chaque envoi**, jusqu'à un budget de temps de
    sécurité interne (ex. rester sous ~50 s pour ne pas heurter le
    `max_execution_time` du serveur) — puis s'arrête, laissant le reste en file pour
    l'appel suivant, 15 minutes plus tard. Avec le délai par défaut (10 s), ça
    représente environ 5 e-mails par appel, soit **~20/heure** — plus lent qu'un envoi
    continu, mais cohérent avec le volume et le rythme d'une petite association (une
    campagne de quelques dizaines à ~200 destinataires s'étale sur quelques heures,
    jamais en une seule requête bloquante). Le plafond `mailing_max_par_jour` reste la
    limite dure par-dessus ce débit.
  - **Repli navigateur** si aucun cron n'est configuré (ex. période de mise en place, ou
    changement d'hébergeur) : un bouton « Traiter la file maintenant » sur l'écran de
    suivi de campagne appelle la même route manuellement — utile en secours, mais le
    cron reste le mode de fonctionnement normal.
- **Environnement dev** : même comportement que `envoyer_email()` — les envois sont
  journalisés dans `data/emails_envoyes.log` plutôt qu'expédiés réellement, y compris
  pour la file d'attente (le traitement par lot en dev « envoie » instantanément, sans
  respecter le délai, pour ne pas ralentir les tests).

## 8. Import CSV — carnets d'adresses hétérogènes

Contrairement aux imports déjà en place (`evenements`, `factures`) qui attendent des
**noms de colonnes fixes**, un carnet d'adresses externe varie fortement d'une source à
l'autre (cf. §9 : le fichier fourni a ses propres noms de colonnes, un autre carnet en
aura d'autres). L'import propose donc une **étape de correspondance des colonnes** :

1. Upload d'un fichier **CSV** (décidé — pas de support natif `.xlsx` ; un fichier Excel
   comme celui fourni en exemple doit être exporté en CSV au préalable par
   l'utilisateur, ex. depuis LibreOffice/Excel/Google Sheets).
2. Aperçu des colonnes détectées + **mapping manuel** vers les champs connus
   (`nom structure`, `categorie`, `ville`, `région`, `site web`, `email contact`,
   `prénom`, `nom`, `téléphone`, `notes`, `dernier contact`, etc.) — colonnes non
   mappées ignorées. **Mapping non mémorisé entre imports** (décidé) — à refaire à
   chaque import, y compris depuis une source déjà importée précédemment.
3. **Simulation** (comme les imports existants) : pour chaque ligne, tentative de
   correspondance avec une structure existante (e-mail exact en priorité, sinon nom
   normalisé — casse/espaces/ponctuation ignorés, même logique que le rapprochement
   spectacle de l'import événements).
   - Aucune correspondance → nouvelle structure, insérée directement sans confirmation
     individuelle (pas un « conflit »).
   - Correspondance trouvée avec des différences → mise en file de **conflits**.
4. **Résolution des conflits un par un** : écran listant chaque conflit avec les valeurs
   actuelles en base à côté des valeurs importées (diff champ par champ), et pour
   chaque ligne un choix explicite **Ignorer** / **Mettre à jour** (pas de « tout
   écraser » global — c'est justement le point demandé). Traité en une session (liste
   stockée en `$_SESSION`, comme les imports existants), appliqué en une fois à la fin.
   Nouveau pattern d'écran pour l'appli (aucun import existant n'a de résolution
   ligne-par-ligne aujourd'hui) — effort d'implémentation à prévoir en conséquence.
5. **Liste d'exclusion** (« ne pas contacter ») importable séparément ou dans le même
   flux : positionne `desinscrit = 1` par e-mail, y compris pour des contacts pas
   encore connus (créés a minima, marqués désinscrits, pour empêcher toute
   réintroduction future par un import ultérieur qui les ramènerait avec
   `desinscrit = 0`).
6. Import toujours **prévisualisable avant application** (bouton « Simuler » vs
   « Importer »), même ergonomie que les imports existants.

## 9. Analyse du fichier fourni (`Souka - Carnet d_adresses.xlsx`)

Fichier réel, non normalisé, 5 onglets — sert de cas d'usage de référence pour calibrer
le modèle ci-dessus.

- **Onglet « Données »** (1 780 lignes) : une ligne = une structure/« Lieu » + un
  contact. Colonnes pertinentes → mapping proposé :
  - `Lieu` → `structures.nom`
  - `Type` (`Booking` 768 / `Média` 920 / `Autres` 49 / `Entourage` 43) → `categorie`.
    Mapping : `Booking` → `organisateur`, `Média` → `media`, `Autres` → `autres`,
    `Entourage` → `entourage` (décidé — quatre catégories distinctes, §5).
  - `Sous-type` (`Festival`, `Salle de concert`, `Café-concert`, `SMAC`, `Radio`,
    `Journal`, `Blog`, `Tourneur`, `Label`, etc. — plus de 30 valeurs) → devient un
    **tag** (`structure_tags`), pas un champ dédié : trop hétérogène et évolutif pour
    une colonne fermée, correspond exactement au rôle des tags libres du §4. Pour les
    sous-types clairement `Festival`/`Salle de concert`, création simultanée d'un
    `lieux` lié (§4) plutôt qu'un simple tag.
  - `Ville`, `Dpt Canton`, `Adresse`, `CP`, `Région`, `Pays` → adresse + `departement_canton` de la
    structure ; `Dpt Canton` (code département) normalisé en région administrative via
    le référentiel `departements_regions` (§4) quand `Région` est vide.
  - `Site` → `structures.site_web` (nouveau champ dédié, décidé, §4).
  - `Notes` → `structures.notes`.
  - `Dernier concert ou diffusion` → intéressant mais distinct de « dernier contact » —
    proposé en `notes` plutôt qu'un champ dédié (v1), pas assez structurant pour son
    propre champ.
  - `Prénom`, `Nom`, `Role`, `Email`, `Tel`, `Langue` → `structure_contacts`.
  - `Formulaire` (URL de formulaire de contact) → `structure_contacts.formulaire_url`
    (champ dédié, décidé — donnée jugée importante, pas versée en note, §4).
  - `Dernier contact` (date) → importée comme une `structure_notes` initiale
    `est_contact = 1` à cette date (plutôt qu'un champ brut, pour rester cohérent avec
    le calcul dérivé du §6).
  - `Mise à jour` → `structures.cree_le` n'est pas le bon usage (c'est une date de mise
    à jour, pas de création) ; ignorée en v1, ou versée en note technique.
  - `Check email` (`NSP` / `Ancien` / `Bounce` / `OK`) → pas de champ dédié ; `Bounce`
    positionne un tag `email invalide` (décidé, §4 — signal utile pour éviter de mailer
    une adresse morte) ; les autres valeurs ignorées.
  - **Colonnes de campagnes historiques** (`K 2014`…`K 2019`, `H 2018 T3`…`H 2021 T4`,
    `Statut` avec symboles `<`, `<<`, `T`, `PP`, etc., décodés dans l'onglet
    « Légende ») : spécifiques à l'ancien outil de mailing de l'association, **hors
    périmètre de l'import** — trop datées et non réutilisables telles quelles. Option :
    les regrouper en une seule note libre horodatée « import historique » pour ne pas
    perdre l'information brute, sans tenter de la structurer.
- **Onglet « Ne pas contacter »** (48 lignes, `email` + `Désinscrit`) → import direct en
  liste d'exclusion (§8, point 5).
- **Onglet « Bounce »** : en-têtes d'export d'outil de mailing (Mailchimp ou
  équivalent : `Sent`/`Opens`/`Clicks`/`Subscription Date`) mais **vide** dans ce
  fichier — confirme que le suivi fin d'ouverture/clic est **hors périmètre v1** (§11),
  pas de donnée réelle à en tirer ici de toute façon.
- **Onglet « Légende »** : documentation des symboles de la colonne `Statut`/des
  colonnes de campagnes — sert uniquement à décider quoi faire des colonnes historiques
  ci-dessus, pas de contrepartie dans le modèle de données.
- **Onglet « Régions »** (102 lignes, `Code`/`Département`/`Région administrative`) →
  source directe du référentiel `departements_regions` proposé au §4.

## 10. Lien avec les modules existants

- **Facturation** : `factures.structure_id` (ex-`debiteur_id`, §3) continue de pointer
  vers `structures` — aucune régression fonctionnelle, une structure
  `categorie = organisateur` peut toujours être sélectionnée sur une facture exactement
  comme un débiteur aujourd'hui, seul le nom de la colonne change.
- **Événements** : `evenements.organisateur_structure_id` (ex-`organisateur_debiteur_id`)
  idem, inchangé fonctionnellement. Les champs `evenements.salle`/`evenements.festival`
  restent **texte libre** en v1 (déjà
  actés comme hors périmètre dans `SPEC_EVENEMENTS.md` §11) — le nouveau modèle
  `lieux`/`structure_lieux` de ce module **n'impose pas** de migration des événements
  existants. Lier `evenements.salle`/`festival` aux nouveaux `lieux` (autocomplétion,
  cohérence des noms) est une évolution naturelle possible mais différée (§11, à
  reconsidérer une fois `booking` en place et éprouvé).
- **Permissions** (`SPEC_PERMISSIONS.md`, non encore implémenté) : si ce chantier
  aboutit avant ou après `booking`, ajouter `booking` à la liste des modules à portée de
  droits (`utilisateur_permissions.module`) — aucune dépendance bloquante dans un sens
  ou l'autre, juste à ne pas oublier d'ajouter l'entrée le moment venu.

## 11. Hors périmètre v1 (explicitement écarté ou différé)

- **Gabarits de mailing enregistrés/réutilisables** — un gabarit rédigé à la volée à
  chaque envoi suffit en v1.
- **Suivi fin des ouvertures/clics** d'un mailing (taux d'ouverture, etc.) — seul le
  succès/échec technique de l'envoi est journalisé (`mailing_envois.succes`).
- **Lien structurel `evenements.salle`/`evenements.festival` → `lieux`** — les deux
  restent indépendants en v1 (§10).
- **Rôles fermés sur `structure_lieux.role`** — texte libre, pas de liste de valeurs
  gérée.
- **Import direct des colonnes de campagnes historiques** (`K…`, `H…`, `Statut`) du
  fichier Souka — non structuré, au mieux versé en note libre (§9).
- **Multi-langue réel du mailing** (gabarit par langue) — le champ `langue` du contact
  est stocké mais sans logique de sélection automatique de gabarit en v1.
- **Fusion/dédoublonnage automatique** de structures déjà en base entre elles (ex. deux
  fiches créées séparément pour la même salle) — seule la déduplication **à l'import**
  (§8) est traitée ; un nettoyage manuel de doublons existants resterait un script CLI
  ponctuel, comme les autres corrections de masse (cf. `CLAUDE.md`).

## 12. Structure de code envisagée

- `lib/booking.php` — fonctions pures : calcul de `dernier_contact_le`, correspondance
  structure/contact à l'import (normalisation de nom), filtrage des destinataires de
  mailing (réutilisé par l'aperçu et la constitution de la file d'attente — une seule
  implémentation, pas deux divergentes, même principe que le filtrage JSON/iCal des
  événements).
- `lib/routes_booking.php` — `route_structures_*`, `route_structure_notes_*`,
  `route_structure_tags_*`, `route_mailing_*` (création de campagne + suivi de file
  d'attente), `route_import_structures` (écrans authentifiés), **plus** deux routes
  publiques par jeton, sans `require_login()` : `route_desinscription` (§7) et
  `route_mailing_traiter` (traitement par lots de la file d'attente, déclenchée par le
  planificateur de tâches Infomaniak toutes les 15 minutes, §7).
- `lib/helpers.php` — nouvelle fonction `smtp_config_booking()` (miroir de
  `smtp_config()` existant) et fonction d'envoi dédiée au mailing (sans repli `mail()`,
  §7), distincte d'`envoyer_email()`.
- `views/structures_liste.php` (remplace `facturation_debiteurs.php`),
  `structure_form.php`, `structure_voir.php` (fiche avec flux de notes), `lieux_liste.php`,
  `lieu_form.php`, `mailing_form.php` (filtres + gabarit), `mailing_suivi.php` (progression
  de la file d'attente), `import_structures.php`, ajout des champs SMTP booking + débit
  dans `views/emails.php`.
- Renommage des vues/routes `debiteur*` existantes → `structure*` (§3).
- Migrations : renommage `debiteurs`→`structures` + colonnes FK associées (§3, test
  empirique requis), nouvelles tables `structure_contacts`, `lieux`, `structure_lieux`,
  `structure_tags`, `structure_tag_liens`, `structure_notes`, `mailing_file_attente`,
  `mailing_envois`, `departements_regions`.
- Tests : `tests/booking_test.php` — calcul de `dernier_contact_le`, normalisation de
  nom pour la correspondance d'import, filtrage mailing (exclusion `desinscrit`
  systématique, chevauchement de période festival, plafond `mailing_max_par_jour`),
  migration du contact unique existant vers `structure_contacts`.

## 13. Points ouverts restants

Tous les points précédemment ouverts sont désormais tranchés :
- **Cron** : confirmé disponible côté Infomaniak (hébergeur cible), via Manager →
  Outils avancés → Planificateur de tâches, déclenchant une **URL** (pas de PHP CLI
  direct sur cette offre) avec une granularité minimale de **15 minutes** — modèle de
  traitement par lots calibré en conséquence (§7). Reste, au moment de coder, à créer
  effectivement la tâche planifiée dans le Manager Infomaniak (`?p=mailing_traiter&token=…`,
  toutes les 15 min) — une étape de configuration côté hébergeur, pas de code
  supplémentaire.
- **`Formulaire`** → champ dédié `structure_contacts.formulaire_url` (§4/§9).
- **`Check email = Bounce`** → tag `email invalide` à l'import (§4/§9).

Aucun point ouvert restant à ce stade — prêt pour l'implémentation, sous réserve de
validation finale du document dans son ensemble.
