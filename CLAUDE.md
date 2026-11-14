# CLAUDE.md — Lasso (fiches de salaire)

Gestion des salaires pour une petite association suisse (Genève). ~10 employés,
1–2 utilisateurs, pas de concurrence. Déploiement cible : **hébergement mutualisé** (PHP + SQLite).

Ce fichier donne les règles ; le « pourquoi » et l'historique sont dans
`docs/DECISIONS.md`, les conventions d'écran dans `docs/UI.md`.

## Stack & contraintes
- **PHP 8 + SQLite (PDO)**, pas de framework, pas de build.
  Éviter les bibliothèques externes : **demander la permission avant d'en introduire une**.
  Si accordée, préférer les bibliothèques légères bundlées dans le dépôt (pas de CDN)
  plutôt que Composer/npm, sauf justification forte.
- Tout en français (UI, commentaires, messages).
- **Exceptions actées**, chacune scopée à son usage (pas un blanc-seing) :
  - **Composer** pour `sprain/swiss-qr-bill` + `tecnickcom/tcpdf` (^6.7 — la
    7.x casse la police core Helvetica) : la QR-facture suisse du module
    facturation. `vendor/` est **commité** ; la production ne lance aucune
    commande Composer (déploiement = `git pull`). Seconde utilisation de tcpdf :
    le QR code d'activation de la validation en deux étapes (`totp_qr_svg()`,
    matrice `TCPDF2DBarcode` seule, SVG dessiné par Lasso). Rien d'autre.
    ⚠️ **`config.platform.php` est figé à `8.3.0` dans `composer.json`** :
    **ne jamais lancer `composer update` sans ce verrou** ; pour relever le
    plancher, changer la valeur sciemment et vérifier une QR-facture
    (`docs/DECISIONS.md § Composer`).
  - **Cropper.js** (`assets/vendor/cropperjs/`, MIT) sur les seules pages qui
    recadrent (`?p=employe`, `?p=projets`) : vignette de 256 px envoyée en data
    URI, revalidée côté serveur comme un upload (`avatar_photo_enregistrer()` :
    `getimagesizefromstring()`, liste blanche, 2 Mo) avant `uploads/`.
  - **Leaflet** (`assets/vendor/leaflet/`) pour la vue carte du booking, fonds
    OpenStreetMap ; géocodage ville+pays par Nominatim, mis en cache en base
    (`lieux_geocodage`, jamais réinterrogé par lieu) — seule dépendance réseau.

## Lancer / tester
```bash
php -S 127.0.0.1:8000     # serveur local (env détecté = dev, via PHP_SAPI cli-server)
php tests/run.php         # analyse syntaxique de tout le code + toute la suite de tests
php scripts/base_demo.php # base de DÉMONSTRATION (data/demo.sqlite), que des données inventées
```
- **Travailler sur la base de démonstration** dès que la tâche n'exige pas les
  vraies données : tous les modules, tirage déterministe, aucune PII. On l'active
  par `APP_DB_PATH` dans `lib/config.local.php` (README §8). Le script refuse
  d'écrire sur `database.sqlite` et d'écraser un fichier sans `--ecraser`.
- Avant de conclure une tâche qui touche au code : **`php tests/run.php`** (le
  `php -l` de tout le projet + TOUS les tests, code ≠ 0 au moindre échec — la
  même commande que la CI). Jamais un fichier de tests isolé seulement.
  - `tests/pages_test.php` ouvre TOUTES les routes GET sur une base de démo
    neuve : une route qui exige un paramètre s'ajoute à son tableau
    `$parametres`. Il poste chaque formulaire, puis vérifie qu'une balise
    injectée dans chaque colonne de texte libre ressort échappée (une réponse
    JSON est exclue : le script qui la reçoit l'insère par `textContent`,
    jamais `innerHTML`).
  - `tests/navigateur_test.php` exécute le JavaScript dans un Chrome sans
    fenêtre : erreur d'exécution, blocage CSP, champ visible sans nom accessible.
  - Un fichier de tests charge `tests/_outils.php` (`check()`, compteurs) et
    n'en redéfinit pas.
- **Horodatages** (`lib/horodatage.php`) : UTC en base. PHP écrit avec
  `horodatage_maintenant()`, jamais `date()` ; on affiche avec
  `horodatage($valeur, $format)`, jamais `date(…, strtotime(…))` sur une colonne
  horodatée (`tests/horodatage_test.php`).
- **Feuille de style** : `assets/css/NN-domaine.css`, chargés dans l'ordre de
  `CSS_FICHIERS` (lib/helpers.php) — un fichier ajouté s'y inscrit. `asset_url()`
  pour tout fichier d'`assets/`. Couleurs en `light-dark(clair, sombre)` dans
  `01-base.css`, jamais de bloc « sombre » à part ; espacements `--esp-*`,
  calques `--z-*`, paliers de `@media` au même endroit (docs/UI.md § 13 ter,
  `tests/css_test.php`, qui plafonne aussi le poids des commentaires).
  ⚠️ Déplacer une règle d'un fichier à l'autre change sa place dans la
  cascade : ses `@media` doivent la suivre.

## Documentation à lire
- **`docs/UI.md`** — comment un écran modifie, supprime, réordonne, ouvre une
  fenêtre, imprime. **À lire avant d'écrire un écran, à mettre à jour dès
  qu'une convention change.** Première règle : un geste qui existe déjà se
  refait pareil.
- **`docs/NOMMAGE.md`** — noms de routes, vues, fonctions ; **avant d'ajouter
  un écran** (`tests/nommage_test.php`).
- **`docs/DECISIONS.md`** — le pourquoi et les impasses ; **avant de toucher au
  schéma, à la configuration d'environnement ou à la CSP.** Un commentaire de
  code énonce l'invariante ; le récit va dans ce fichier.
- **`docs/MARCHE.md`** — comparatif daté avec le marché et ce qui manque à
  Lasso ; à relire pour choisir le prochain chantier (sources à revérifier).

## Architecture
- **Front controller** `index.php` : config, HTTPS forcé, en-têtes de sécurité,
  session, puis dispatch `?p=<route>` via une table → fonctions `route_*()`.
- **Routes** : `lib/routes.php` garde le tronc commun (authentification, comptes,
  paramètres, apparence, e-mails, import/export, sauvegarde, tableau de bord,
  recherche). Puis un fichier par module : `routes_salaires.php`,
  `routes_compta.php`, `routes_pieces.php`, `routes_facturation.php`,
  `routes_carnet.php` (structures), `routes_personnes.php`,
  `routes_evenements.php`, `routes_booking.php`, `routes_fonds.php`,
  `routes_incoherences.php`.
- **Vues** : `render($vue, $data, $titre)` (avec layout) ou `render_bare()`
  (impression). `views/_*_body.php` = corps réutilisés (écran + impression + e-mail).
- **DB** `lib/db.php` : `db()` (PDO singleton, WAL) + `param()`. Schéma
  `lib/db/schema.php`, migrations `lib/db/migrations.php` — chargés par `db.php`,
  jamais requis directement. **Migrations versionnées** (`PRAGMA user_version`,
  `$steps` → `migration_N()`, idempotentes : `ALTER` après vérification de la
  colonne) ; une migration neuve va dans le dernier fichier de
  `lib/db/migrations/` (tranches de 25).
  ⚠️ Recréer une table : la nouvelle sous un nom temporaire (`x_new`), copie,
  `DROP TABLE x`, `ALTER TABLE x_new RENAME TO x` — **jamais** `RENAME TO x_old`
  puis recréation, qui casse les FK même avec `foreign_keys = OFF`
  (`docs/DECISIONS.md § Migrations SQLite`, exemple `migration_21`,
  `tests/migrations_test.php`).
- **Calcul** `lib/calc.php` : `calculer_fiche()`, `r2()` (arrondi 2 déc.),
  `seuil_heures()`, `laa_effectif()`, `taux_pour_annee()`, `taux_stockes()`, `TAUX_DEFAUT`.
- **Postes salariaux** (`docs/DECISIONS.md § Postes salariaux`) :
  `postes_salariaux` définit les lignes du décompte, `poste_taux` leurs taux par
  année (**fait foi** ; `taux_par_annee` n'est qu'un repli), `poste_bareme_age`
  les paliers d'âge ; tout se règle sur `?p=postes`. `calculer_fiche()` boucle
  sur `postes_actifs()` ; `poste_montant()` : modes `taux`, `taux_employe`,
  `laa_seuil`, `bareme_age`, bases `brut`, `coordonne`. **À l'enregistrement, une
  fiche FIGE ses lignes dans `fiche_postes`, libellé compris** : tout ce qui lit
  une fiche (écran, impression, e-mail, certificat via `rubrique_certificat`,
  compta via `groupe_compta`) lit cette copie, jamais `postes_salariaux`. Le
  recalcul est explicite (`?p=fiches_recalculer`, aperçu + sauvegarde auto).
  **Invariante : les totaux sont la somme des montants arrondis, jamais
  recalculés depuis la copie figée.**
- **Helpers** `lib/helpers.php` : la porte unique. Chargés par lui, **jamais
  requis directement** : `lib/helpers/session.php` (session, CSRF,
  anti-force-brute, mot de passe oublié, en-têtes, CSP), `listes.php` (filtres,
  tri, pagination), `geo.php` (pays, régions, cantons), `icones.php` (`icon()`),
  `deux_facteurs.php`. Même découpage pour `lib/booking.php`, porte de
  `lib/booking/campagnes.php`, `mailing.php`, `import.php`
  (`tests/decoupage_test.php`).
  ⚠️ Un fichier de `lib/helpers/` est à DEUX niveaux de la racine :
  `__DIR__ . '/../data'` y désigne `lib/data/`, pas `data/`.
- **Config** `lib/config.php` charge d'abord `lib/config.local.php` (non
  versionné) : `APP_ENV`, `APP_DB_PATH`, `APP_URL`, `FORCE_HTTPS`,
  `SETUP_SECRET`, `PASSWORD_MIN`, `BCRYPT_COST`, `SESSION_IDLE/ABSOLUTE`,
  `LOGIN_MAX_ATTEMPTS/WINDOW`.
- **Validation en deux étapes** `lib/helpers/deux_facteurs.php` (TOTP + codes de
  secours, facultative, activée sur `?p=mon_compte`) : entre le mot de passe et
  le code, la session porte `deux_facteurs` et **pas `uid`** — aucune page ne
  s'ouvre. ⚠️ Poser `uid` ailleurs que dans `connexion_ouvrir()` (et
  l'installation) rouvrirait la porte. Compteurs d'échecs purgés seulement
  APRÈS le code ; secret chiffré, codes de secours en empreinte, rejeu refusé
  (`totp_dernier_pas`) ; un administrateur réinitialise
  (`utilisateur_deux_facteurs_reinitialiser`) ; rien ne l'impose à tous
  (`tests/deux_facteurs_test.php`).
- **Journal d'activité** `lib/journal.php` (`?p=journal`, admin) : armé une fois
  par le dispatcher, il relève tout POST d'un compte connecté — route, cible,
  issue. ⚠️ **Jamais le contenu du formulaire** (mots de passe, salaires, AVS) :
  seulement les clés de `JOURNAL_CIBLES`/`JOURNAL_GESTES`. Les erreurs du
  serveur y vont aussi (`journal_erreurs_armer()`, `journal_exception()` ;
  une ligne par erreur distincte, avis au tableau de bord) — ⚠️ jamais la pile d'appels, et `journal_erreur()` ne
  doit jamais lever (`tests/journal_test.php`).
- **Secrets** `lib/secrets.php` : mots de passe SMTP **et n° AVS**
  (`employes.numero_avs`, `fiches.employe_avs`) CHIFFRÉS en base
  (`secret_chiffrer()` / `secret_dechiffrer()`, qui rend `null` si la clé est
  inconnue). AVS : `avs_stocker()` / `avs_lire()` / `avs_affiche()`, jamais un
  `WHERE` sur ces colonnes (`avs_trouver_employe()`). Clés : `SECRETS_CLE` et/ou
  `<APP_DB_PATH>.cle.php` (la première chiffre, toutes déchiffrent), jamais dans
  la base ; dans l'archive SEULEMENT si elle est chiffrée par phrase
  (`CLE_SECRETS.txt`, reprise à la restauration).
  ⚠️ Tout nouveau secret ou identifiant sensible passe par ces fonctions
  (`tests/secrets_test.php`).
- **Clôture d'exercice** (`lib/compta.php § Clôture`) : verrou tenu par des
  **déclencheurs SQLite** (`migration_104`), pas par les routes. ⚠️ Une
  migration qui recrée `ecritures` ou `ecritures_ventilations` doit rejouer
  `compta_cloture_declencheurs_sql()`.
- **Modules & droits** `lib/modules.php` (`docs/SPEC_PERMISSIONS.md`,
  récits dans `docs/DECISIONS.md § Dépendances et modules`) :
  - `MODULES`/`module_actif()` : activation globale, indépendante des droits.
    `requires` : `analytique`, `pieces` → `compta` ; `mailing` → `booking` ;
    `facturation`, `evenements`, `booking`, `fonds` → `carnet`. Ils s'éteignent
    avec leur parent.
    ⚠️ `carnet` possède les **structures** et les **personnes**, et lui seul
    (`?p=structures`, `?p=structure`, `?p=personnes`, `?p=personne`). Les écrans
    du CRM (contacts, notes, tags, lieux, messages, import) restent au booking.
    L'onglet **Employés** y figure derrière le droit `salaires`.
    ⚠️ `requires` active en cascade pour tous, mais ne **plafonne les droits**
    (`clamp_permissions_dependantes()`) que des sous-modules `analytique` et
    `mailing` ; les autres portent `plafonne => false`.
    ⚠️ Le bandeau de la comptabilité s'ouvre avec `compta` OU `pieces`
    (`nav_groupes()`) : seul onglet qui survit à son module parent.
  - Droits par utilisateur (`utilisateur_permissions`, absence de ligne = aucun
    accès) : `peut_lire()`/`peut_ecrire()`. `coeur` (hors `MODULES`) recouvre
    paramètres/comptes/modules/mises à jour/sauvegarde ; **écriture sur `coeur`
    = administrateur** (`est_admin()`), et il en reste toujours au moins un.
    Un nouveau compte démarre **sans aucun droit** ; seul le premier
    (`route_installation()`) reçoit tout.
  - **`module_accessible($id)`** = `module_actif()` **ET** `peut_lire()` :
    toujours les deux pour décider d'afficher quelque chose.
  - Dispatch (`index.php`) : chaque route est rattachée à son ou ses modules
    (`ajouter_routes_module()`) ; `route_autorisee()` exige la lecture pour un
    GET, l'écriture pour un POST. **Toute mutation passe par un POST protégé par
    `check_csrf()`** — jamais une route mutante en GET. **C'est le seul contrôle
    de droits** : une route ne revérifie pas les siens (`require_lecture()` /
    `require_ecriture()` ne servent qu'hors dispatcher : fragment,
    sous-action). ⚠️ Une route qui écrit sans être déclarée dans
    `ajouter_routes_module()` échappe à tout contrôle —
    `tests/permissions_test.php` (§ 6) l'interdit.
  - **Trois routes échappent au dispatcher**, hors session, chacune autorisée
    par un jeton ou une signature : `route_sauvegarde()` (POST seulement ; un GET
    renvoie à `?p=sauvegardes`), `route_mailing_traiter()` (planificateur,
    `mailing_traiter_token`), `route_desinscription()` (n'écrit **que sur un
    POST** ; le GET n'affiche qu'une confirmation).
- **Pièces comptables** `lib/pieces.php` + `lib/routes_pieces.php`
  (`docs/SPEC_PIECES.md`) : une pièce est une ENTITÉ, saisie avant l'écriture.
  **Trois états** (`piece_etat()`) : à payer, payée non rapprochée, classée.
  `ecriture_piece_etat()` est la SEULE source de « a-t-elle un justificatif ? »,
  et `ecriture_piece_etat_sql()` doit dire exactement la même chose
  (`tests/pieces_test.php`).
- **Personnes** `lib/personnes.php` (`docs/SPEC_PERSONNES.md`) : l'identité d'un
  contact, d'un employé ou d'un compte ; `personne_structures` est le lien N↔N.
  ⚠️ **Lire un contact ou un employé passe par `CONTACT_SELECT`/`CONTACT_FROM`
  et `EMPLOYE_SELECT`/`EMPLOYE_FROM`** : un `SELECT *` sur `employes` ou
  `personne_structures` rend des noms VIDES, sans erreur.
- **Fichiers privés** `lib/fichiers.php` : `data/fichiers/`, hors racine web,
  validés par le CONTENU (`finfo`), nom tiré au sort, 8 Mo. ⚠️ Ce fichier ne
  décide JAMAIS qui a le droit de lire : chaque module garde sa route de service.
- **Recherche unifiée** `lib/recherche.php` (`?p=recherche`) : seule requête qui
  traverse plusieurs modules — chaque source est filtrée par
  `module_accessible()` **avant** toute requête, et la route n'est rattachée à
  aucun module. Ajouter une entité = une entrée dans `recherche_sources()`
  (`tests/recherche_test.php`).

## Domaine (paie suisse) — à respecter
- Déductions employé : AVS/AI/APG, AC, A.mat (GE), **LAA** (deux taux : *réduit* si
  heures ≤ seuil mensuel = jours ÷ 7 × 8, sinon *plein* ; choix auto à la création),
  **LPP** (taux unique par défaut, ou barème par âge sur le salaire coordonné),
  impôt à la source (si procédure concernée).
- **CAF** : vestigiale, toujours 0 (ne pas réactiver sans demande).
- Charges patronales (`emp_*`) : AVS/AC/A.mat/AF/LAA/frais/CPE/LFP/LPP.
- **Taux propres à chaque année** (`poste_taux`). Valider avec OCAS / caisse LPP-LAA.
- Impôt à la source = **taux unique** par employé (pas de barème par tranche).

## Règles importantes (gotchas)
- **Historique figé** : une fiche stocke ses montants ET ses taux (`taux_json`) à la
  création. **Ne JAMAIS modifier les données d'une fiche/d'un employé sans demande
  explicite.** Les corrections de masse passent par un script CLI ponctuel avec
  dry-run + sauvegarde préalable de la base.
- **Affichage piloté par la base** : nom + logos de l'employeur viennent de
  `parametres` (`employeur_nom`, `employeur_logo_clair/sombre`, variantes
  `employeur_logo_mini_clair/sombre` pour favicone et rail ; `LOGO_VARIANTES`,
  `logo_petit_variante()`). **Aucune marque codée en dur** ; repli sur le nom
  employeur en texte si pas de logo.
- **Sécurité** : `check_csrf()` sur tout POST ; `e()` sur toute sortie ; requêtes
  **toujours** préparées (paramétrées). bcrypt coût 12 ; anti-force-brute ; sessions
  expirantes ; secret d'installation ; base hors webroot en prod.
  **Exports CSV** : toujours `csv_ecrire()`, jamais `fputcsv()` (formules
  neutralisées, `tests/csv_test.php`).
  **Mot de passe oublié** (routes publiques) : jeton à usage unique d'1 h,
  **seule son empreinte SHA-256 est en base**, réponse identique que le compte
  existe ou non, quota propre (`RESET_MAX_PAR_HEURE`) qui ne doit **jamais**
  alimenter le compteur d'échecs de connexion. Lien construit depuis `APP_URL`
  si définie, sinon l'en-tête `Host`.
- **E-mails** : en `dev`, journalisés dans `data/emails_envoyes.log` ; en `prod`,
  `mail()` réel (expéditeur = `employeur_email_expediteur`).
- **Uploads** logos : validés par `getimagesize()` (image réelle), 2 Mo max, stockés
  dans `uploads/` (web-servi, scripts bloqués par `.htaccess`).
- Toujours `require_once` (sinon « Cannot redeclare r2() » dans les scripts CLI).
- Tests/preview : un utilisateur de test (ex. `preview@example.test`) peut être créé ;
  **le supprimer après**. Ne pas laisser de données fabriquées (remettre à vide les
  champs de test).

## Git / déploiement
- **Exclus du versionnement** (`.gitignore`) : `data/` (PII), `uploads/*`,
  `lib/config.local.php`, `*.sqlite*`, `*.log`. Vérifier avant tout commit qu'aucune
  donnée sensible n'est suivie.
- Mise à jour prod : `git pull` (config/données/logos préservés, migrations auto).
- Détails complets dans `README.md` (§2 config, §3 déploiement, §4 mises à jour).
