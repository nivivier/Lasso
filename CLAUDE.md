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

## Lancer / tester
```bash
php -S 127.0.0.1:8000      # serveur local (env détecté = dev)
php tests/calc_test.php    # tests de calcul (doivent tous passer)
```
Avant de conclure une tâche qui touche au code : `php -l` sur les fichiers modifiés
+ `php tests/calc_test.php`.

## Architecture
- **Front controller** `index.php` : charge `lib/config.php`, force HTTPS, en-têtes
  de sécurité, session, puis dispatch `?p=<route>` via une table → fonctions
  `route_*()` dans `lib/routes.php`.
- **Vues** : `render($vue, $data, $titre)` (avec layout) ou `render_bare()` (impression).
  `views/layout.php` enveloppe ; `views/_*_body.php` = corps réutilisés (écran + impression + e-mail).
- **DB** `lib/db.php` : `db()` (PDO singleton, WAL). Schéma créé par `init_schema()`.
  **Migrations versionnées** via `PRAGMA user_version` + `$steps` → `migration_N()`
  (idempotentes : `ALTER` après vérif d'existence de colonne). Pour faire évoluer le
  schéma : ajouter une entrée `$steps` + une fonction `migration_N()`.
  ⚠️ Pour recréer une table avec un schéma modifié (ex. retirer une contrainte
  inline), **ne pas** faire `ALTER TABLE x RENAME TO x_old` puis recréer `x` :
  testé empiriquement (SQLite 3.53), `PRAGMA foreign_keys = OFF` **ne suffit pas**
  à empêcher SQLite de réécrire la clause `REFERENCES` des autres tables vers
  `x_old`, qui devient une FK cassée une fois `x_old` droppée (voir migration_21
  pour un exemple vérifié). À la place : créer la nouvelle table sous un nom
  temporaire (`x_new`), y copier les données, `DROP TABLE x` (une suppression,
  pas un renommage — ne déclenche aucune réécriture ailleurs), puis
  `ALTER TABLE x_new RENAME TO x`. Vérifier ensuite avec `PRAGMA foreign_key_check`.
- **Calcul** `lib/calc.php` : `calculer_fiche()`, `r2()` (arrondi 2 déc.),
  `seuil_heures()`, `laa_effectif()`, `taux_pour_annee()`, `taux_stockes()`, `TAUX_DEFAUT`.
- **Helpers** `lib/helpers.php` : `e()` (échappement), `param()` (paramètres, cachés),
  `csrf_token()/check_csrf()`, `icon()` (SVG Lucide inline), `chf()`, `pct()`,
  `param_logo()`, throttle login, etc.
- **Config** `lib/config.php` charge d'abord `lib/config.local.php` (non versionné) ;
  constantes : `APP_ENV`, `APP_DB_PATH`, `FORCE_HTTPS`, `SETUP_SECRET`,
  `PASSWORD_MIN`, `BCRYPT_COST`, `SESSION_IDLE/ABSOLUTE`, `LOGIN_MAX_ATTEMPTS/WINDOW`.

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
