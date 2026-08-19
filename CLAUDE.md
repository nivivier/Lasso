# CLAUDE.md — Lasso (fiches de salaire)

Gestion des salaires pour une petite association suisse (Genève). ~10 employés,
1–2 utilisateurs, pas de concurrence. Déploiement cible : **hébergement mutualisé** (PHP + SQLite).

## Stack & contraintes
- **PHP 8 + SQLite (PDO)**, pas de framework, pas de build.
  Éviter les bibliothèques externes : **demander la permission avant d'en introduire une**.
  Si accordée, préférer les bibliothèques légères bundlées dans le dépôt (pas de CDN)
  plutôt que Composer/npm, sauf justification forte.
- Tout en français (UI, commentaires, messages).
- **Exception actée** : le module facturation utilise Composer pour
  `sprain/swiss-qr-bill` + `tecnickcom/tcpdf` (^6.7 — la 7.x casse la police core
  Helvetica) afin de générer une QR-facture suisse conforme (zone de paiement
  normée + code QR), ce qui serait déraisonnable à réimplémenter en PHP pur.
  `vendor/` est **commité dans le dépôt** ; aucune commande Composer n'est
  nécessaire en production (déploiement reste `git pull`). Cette dérogation est
  scopée à ce module (PDF + QR-facture), pas un blanc-seing général.
  ⚠️ **`config.platform.php` est figé à `8.3.0` dans `composer.json`** : sans ce
  verrou, Composer résout les dépendances pour la version de PHP de la machine
  qui lance la commande. Une résolution faite sur PHP 8.5 avait tiré
  `symfony/intl`/`validator` 8.1 (qui exigent PHP ≥ 8.4.1) dans un `vendor/`
  commité, rendant le module inutilisable sous 8.4 — invisible en local et en
  prod (toutes deux en 8.5), mais fatal ailleurs, et l'intégration continue
  (PHP 8.3) l'a révélé. **Ne jamais lancer `composer update` sans ce verrou** ;
  pour relever le plancher, changer la valeur sciemment et vérifier la
  génération d'une QR-facture.
- **Exception actée** : la vue carte des lieux (module booking) utilise
  **Leaflet** (`assets/vendor/leaflet/`, bundlé dans le dépôt, pas de CDN) avec
  des fonds de carte OpenStreetMap. Géocodage ville+pays via Nominatim (OSM),
  mis en cache en base (`lieux_geocodage`, jamais réinterrogé par lieu) —
  seule dépendance réseau externe de cette fonctionnalité. Dérogation scopée à
  la vue carte, pas un blanc-seing général.

## Lancer / tester
```bash
php -S 127.0.0.1:8000   # serveur local (env détecté = dev, via PHP_SAPI cli-server)
php tests/run.php       # analyse syntaxique de tout le code + toute la suite de tests
```
Avant de conclure une tâche qui touche au code : **`php tests/run.php`**, qui fait
à la fois le `php -l` sur l'ensemble du projet et les 6 fichiers de tests. Il sort
en code ≠ 0 au moindre échec (c'est la même commande que la CI, voir
`.github/workflows/tests.yml`) — ne pas se contenter de lancer un fichier de tests
isolé, c'est ainsi qu'un fichier cassé est passé inaperçu.

## Architecture
- **Front controller** `index.php` : charge `lib/config.php`, force HTTPS, en-têtes
  de sécurité, session, puis dispatch `?p=<route>` via une table → fonctions
  `route_*()` dans `lib/routes.php`.
- **Vues** : `render($vue, $data, $titre)` (avec layout) ou `render_bare()` (impression).
  `views/layout.php` enveloppe ; `views/_*_body.php` = corps réutilisés (écran + impression + e-mail).
- **Décisions & impasses connues** : `docs/DECISIONS.md` — le « pourquoi » des choix
  structurants et des pièges déjà rencontrés (migrations SQLite, résolution de
  `APP_ENV`, CSP, cache des data-URI, tests). **À lire avant de toucher au schéma,
  à la configuration d'environnement ou à la CSP.** Convention : un commentaire dans
  le code énonce l'invariante à respecter ; le récit historique va dans ce fichier,
  pour qu'il ne vieillisse pas au milieu du code.
- **DB** `lib/db.php` : `db()` (PDO singleton, WAL) + `param()`. Schéma dans
  `lib/db/schema.php` (`init_schema()`, `seed_*()`), migrations dans
  `lib/db/migrations.php` — les deux chargés par `db.php`, jamais requis directement.
  **Migrations versionnées** via `PRAGMA user_version` + `$steps` → `migration_N()`
  (idempotentes : `ALTER` après vérif d'existence de colonne). Pour faire évoluer le
  schéma : ajouter une entrée `$steps` + une fonction `migration_N()`.
  ⚠️ Pour recréer une table avec un schéma modifié : créer la nouvelle sous un nom
  temporaire (`x_new`), y copier les données, `DROP TABLE x`, puis
  `ALTER TABLE x_new RENAME TO x` — **jamais** `RENAME TO x_old` puis recréation,
  qui laisse des FK cassées (`PRAGMA foreign_keys = OFF` n'y change rien ; le
  pourquoi est dans `docs/DECISIONS.md § Migrations SQLite`, l'exemple dans
  `migration_21`). `tests/migrations_test.php` vérifie `PRAGMA foreign_key_check`
  sur toute la chaîne, donc une rechute est détectée en CI.
- **Calcul** `lib/calc.php` : `calculer_fiche()`, `r2()` (arrondi 2 déc.),
  `seuil_heures()`, `laa_effectif()`, `taux_pour_annee()`, `taux_stockes()`, `TAUX_DEFAUT`.
- **Helpers** `lib/helpers.php` : `e()` (échappement), `param()` (paramètres, cachés),
  `csrf_token()/check_csrf()`, `icon()` (SVG Lucide inline), `chf()`, `pct()`,
  `param_logo()`, throttle login, etc.
- **Config** `lib/config.php` charge d'abord `lib/config.local.php` (non versionné) ;
  constantes : `APP_ENV`, `APP_DB_PATH`, `FORCE_HTTPS`, `SETUP_SECRET`,
  `PASSWORD_MIN`, `BCRYPT_COST`, `SESSION_IDLE/ABSOLUTE`, `LOGIN_MAX_ATTEMPTS/WINDOW`.
- **Modules & droits** `lib/modules.php` (voir `SPEC_PERMISSIONS.md`) :
  - `MODULES`/`module_actif()` : activation globale par module (salaires/compta/
    analytique/facturation/evenements), indépendante des droits ci-dessous.
  - Droits par utilisateur, table `utilisateur_permissions` (module → lecture/
    écriture, absence de ligne = aucun accès) : `peut_lire()`/`peut_ecrire()`
    (utilisateur courant), `require_lecture()`/`require_ecriture()`.
    `coeur` est un module à part (jamais dans `MODULES`) qui recouvre
    paramètres/comptes/modules/mises à jour/sauvegarde ; **écriture sur `coeur`
    = administrateur**, `est_admin()`. Il doit toujours en rester au moins un
    (garde-fou dans `enregistrer_permissions_utilisateur()` et
    `route_compte_delete()`).
  - Dispatch (`index.php`) : chaque route est associée au(x) module(s) dont
    dépend son accès (`ajouter_routes_module()`) ; `route_autorisee()` exige la
    lecture pour un GET, l'écriture pour un POST (convention stricte : toute
    mutation passe par un POST protégé par `check_csrf()`, aucune route
    n'écrit sur un GET **sauf `route_backup()`**, gardée à part car elle
    exporte toute la base). Ajouter une route mutante en GET casserait ce
    contrôle — ne pas le faire.
  - Un nouveau compte (`route_comptes()`) démarre **sans aucun droit** ; seul
    le tout premier compte (`route_setup()`) reçoit tout par défaut.
  - **`module_accessible($id)`** = `module_actif()` **ET** `peut_lire()`. Les deux
    conditions vont toujours ensemble pour décider d'afficher quelque chose :
    tester `module_actif()` seul laisse fuiter les données d'un module vers un
    compte qui n'y a pas accès.
- **Recherche unifiée** `lib/recherche.php` (`?p=recherche`, champ sur le tableau
  de bord) : traverse employés/structures/factures/événements/spectacles. Seule
  fonctionnalité qui interroge plusieurs modules dans la même requête — chaque
  source est donc filtrée par `module_accessible()` **avant** toute requête, et la
  route n'est volontairement rattachée à aucun module. Ajouter une entité = une
  entrée dans `recherche_sources()` (projeter `id/titre/sous_titre/tri/texte`) ;
  `tests/recherche_test.php` vérifie la déclaration.

## Domaine (paie suisse) — à respecter
- Déductions employé : AVS/AI/APG, AC, A.mat (GE), **LAA** (deux taux : *réduit* si
  heures ≤ seuil mensuel = jours ÷ 7 × 8, sinon *plein* ; choix auto à la création),
  **LPP** (taux unique), impôt à la source (si procédure concernée).
- **CAF** : vestigiale, toujours 0 (ne pas réactiver sans demande).
- Charges patronales (`emp_*`) : AVS/AC/A.mat/AF/LAA/frais/CPE/LFP/LPP.
- **Taux propres à chaque année** (`taux_par_annee`). Valider avec OCAS / caisse LPP-LAA.
- Impôt à la source = **taux unique** par employé (pas de barème par tranche).

## Règles importantes (gotchas)
- **Historique figé** : une fiche stocke ses montants ET ses taux (`taux_json`) à la
  création. **Ne JAMAIS modifier les données d'une fiche/d'un employé sans demande
  explicite.** Les corrections de masse passent par un script CLI ponctuel avec
  dry-run + sauvegarde préalable de la base.
- **Affichage piloté par la base** : nom + logos de l'employeur viennent de `parametres`
  (`employeur_nom`, `employeur_logo_clair/sombre`). **Aucune marque codée en dur** ;
  repli sur le nom employeur en texte si pas de logo.
- **Sécurité** : `check_csrf()` sur tout POST ; `e()` sur toute sortie ; requêtes
  **toujours** préparées (paramétrées). bcrypt coût 12 ; anti-force-brute ; sessions
  expirantes ; secret d'installation ; base hors webroot en prod.
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
