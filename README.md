# Lasso

Gestion administrative d'une petite structure suisse de type association
(fiches et certificats de salaire).

Application web légère de gestion des salaires pour une association (loi suisse).
Le nom et les logos de l'employeur affichés proviennent entièrement de la base de
données (Paramètres → Employeur). Gestion des employés, fiches de salaire
mensuelles, tableau de bord, certificat de salaire annuel (formulaire 11 + export
XML pour l'application « eCertificat de salaire CSI »), envoi des fiches par e-mail
(SMTP authentifié, repli sur `mail()`).

L'application est découpée en **modules activables** (Paramètres → Modules), avec
des **droits de lecture/écriture par utilisateur et par module** :

| Module | Contenu |
|--------|---------|
| **Fiches de salaire** | employés, fiches mensuelles, certificats, taux |
| **Comptabilité** | relevés PostFinance (CSV), plan comptable, lettrage, comptes annuels |
| **Comptabilité analytique** | axes et ventilations (dépend de Comptabilité) |
| **Facturation** | débiteurs, **QR-factures suisses** (PDF), relances |
| **Événements** | dates, spectacles, déclarations SUISA, exports JSON/iCal |
| **Booking** | structures, contacts, étiquettes, lieux (carte), mailing |

Une **recherche unifiée** traverse ces modules depuis le tableau de bord, en ne
montrant que ce que le compte a le droit de lire.

**Technologie :** PHP 8.3+ et SQLite, sans framework ni étape de compilation.
Trois dépendances seulement, **toutes embarquées dans le dépôt** (jamais de CDN) :
`sprain/swiss-qr-bill` et `tecnickcom/tcpdf` pour la QR-facture (dossier `vendor/`,
commité), **Leaflet** pour la carte des lieux (`assets/vendor/leaflet/`) et la police
Inter (`assets/fonts/`).

---

## 1. Prérequis

- Un hébergement avec **PHP 8.3 ou plus** et l'extension **PDO SQLite**
  (souvent natifs sur un hébergement mutualisé). Ce plancher est celui des
  dépendances de la QR-facture : il est verrouillé par `config.platform.php`
  dans `composer.json`, pour que `vendor/` reste installable partout.
- Pour le déploiement recommandé : **accès SSH** et **git** sur l'hébergement.
- Pas de MySQL, pas de Node. **Aucune commande Composer n'est nécessaire** :
  `vendor/` est commité dans le dépôt.

---

## 2. Configuration (`lib/config.local.php`)

Toute la configuration spécifique à un environnement passe par un fichier
**`lib/config.local.php`** — **non versionné** (ignoré par git). Il redéfinit les
constantes voulues ; ses valeurs l'emportent sur les défauts de `lib/config.php`.

Copiez le modèle puis adaptez-le :

```bash
cp lib/config.local.php.example lib/config.local.php
```

```php
<?php
define('APP_ENV', 'prod');                                  // 'prod' ou 'dev'
define('APP_DB_PATH', '/home/clients/xxxx/data/database.sqlite'); // HORS racine web
define('FORCE_HTTPS', true);
define('SETUP_SECRET', '<longue valeur aléatoire>');        // protège l'écran d'installation
```

| Constante | Rôle | Défaut |
|-----------|------|--------|
| `APP_ENV` | `prod` : erreurs masquées, e-mails envoyés, HTTPS forcé. `dev` : erreurs affichées, e-mails journalisés. | variable serveur `APP_ENV`, sinon `dev` en ligne de commande et sous `php -S`, sinon **`prod`** |
| `APP_DB_PATH` | Chemin absolu du fichier SQLite. **À placer hors de la racine web.** | `data/database.sqlite` |
| `FORCE_HTTPS` | Redirection 301 vers HTTPS + en-tête HSTS. | `true` en prod |
| `SETUP_SECRET` | L'écran de création du 1ᵉʳ compte exige `?p=setup&key=<secret>`. **En production, son absence bloque l'installation** (réponse 503 explicite) plutôt que de laisser l'écran ouvert. | vide — obligatoire en `prod` |

**Envoi d'e-mails (SMTP)** — beaucoup d'hébergements mutualisés désactivent `mail()`.
Le serveur d'envoi se règle de préférence dans **Paramètres → E-mails** (stocké en
base), ou via `lib/config.local.php` (`SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`,
`SMTP_USER`, `SMTP_PASS`). Tant que `SMTP_USER` est vide, l'application retombe sur
`mail()`.

**Mises à jour d'un dépôt privé** — pour que la détection de version fonctionne sans
rendre le dépôt public, définissez un jeton de lecture GitHub : `define('MAJ_TOKEN',
'<token>');` (sinon, rendez le dépôt public).

---

## 3. Premier déploiement sur l'hébergeur (git)

1. **Activer SSH** sur l'hébergement et **HTTPS** (certificat Let's Encrypt gratuit)
   depuis le panneau de gestion de votre hébergeur.
2. **Cloner le dépôt** dans le dossier servi par le domaine (ou un sous-domaine
   dédié, ex. `salaires.mondomaine.ch`) :
   ```bash
   git clone https://github.com/nivivier/Lasso.git .
   ```
3. **Créer `lib/config.local.php`** (voir §2) avec `APP_ENV=prod`, le `APP_DB_PATH`
   hors webroot, `FORCE_HTTPS`, et un `SETUP_SECRET`.
4. **Créer le dossier de données** hors racine web (celui de `APP_DB_PATH`) et le
   rendre **inscriptible** par PHP (`0770`/`0775`).
5. **Transférer les données existantes** ⚠️ : la base `data/database.sqlite` n'est
   **pas** dans le dépôt (données employés exclues du versionnement). Copiez votre
   base locale par SFTP vers le `APP_DB_PATH` choisi. Sinon l'application démarre
   sur une base vide.
6. **Logos** : le dossier `uploads/` est également hors versionnement. Soit vous
   re-uploadez les logos via Paramètres → Employeur sur la production, soit vous
   copiez `uploads/*` par SFTP.
7. **Compte administrateur** :
   - Si vous avez transféré votre base, le compte existe déjà → allez directement
     sur la page de connexion.
   - Sinon, ouvrez **`https://votre-domaine/?p=setup&key=<SETUP_SECRET>`** et créez
     le compte (e-mail + mot de passe d'au moins 8 caractères, `PASSWORD_MIN`).

---

## 4. Mises à jour (le workflow git)

- **Sur le serveur** : `git pull` (ou `./deploy.sh`, qui sauvegarde la base, fait un
  `git pull --ff-only` et vérifie la syntaxe PHP). C'est tout.
  - `lib/config.local.php`, `data/` et `uploads/` sont préservés (non versionnés).
  - Les **migrations de schéma** s'appliquent automatiquement à la première requête
    (versionnement `PRAGMA user_version`).

### Versions et canaux

- La version courante est dans le fichier **`VERSION`** (SemVer), affichée en bas de
  la barre latérale et dans **Paramètres → Mises à jour**.
- Deux **canaux** = deux branches git : **test** (`main`, où atterrit tout le travail
  courant) et **stable** (`stable`, avancée uniquement vers les états validés).
- Promotion d'une version stable : `./release.sh stable X.Y.Z` (fige `VERSION`, pose
  le tag `vX.Y.Z`, avance la branche `stable`, pousse).
- **Paramètres → Mises à jour** affiche la version installée, la version disponible
  sur le canal choisi, et un diagnostic `exec()`/`git` du serveur.
- **Mise à jour en un clic** depuis cette page (`maj_executer()`, `lib/maj.php`) :
  sauvegarde de la base, téléchargement de l'archive de la branche du canal,
  extraction, puis migrations au premier chargement. `lib/config.local.php`,
  `data/` et `uploads/` sont préservés (non versionnés).
  Deux conditions : le serveur doit savoir décompresser une archive
  (`maj_archive_possible()`), et la mise à jour web ne doit pas avoir été
  désactivée par `define('ALLOW_WEB_UPDATE', false)` dans `lib/config.local.php`.
  Sinon, la page l'indique et le déploiement se fait par `git pull`.

---

## 5. Utilisation

1. **Employés** : ajoutez chaque salarié (canton, supplément vacances, procédure de
   décompte, éventuel taux d'impôt à la source, date de naissance, N° AVS).
2. **Fiches → Nouvelle fiche** : choisissez l'employé, le mois, les prestations
   (lignes quantité × unité × taux horaire). Le décompte est calculé et **figé**.
3. Sur une fiche : **Imprimer / PDF**, **Envoyer** par e-mail à l'employé.
4. **Tableau de bord** : totaux par trimestre / semestre / année et « Salaires à
   verser ».
5. **Certificat de salaire** (page d'un employé) : récapitulatif annuel au format du
   formulaire 11, impression PDF, et **export XML** à importer dans l'application
   officielle *eCertificat de salaire CSI* pour produire les PDF certifiés.
6. **Comptabilité** : créez vos comptes bancaires, importez les relevés PostFinance
   (CSV), lettrez les écritures (catégorie du plan comptable), définissez des règles
   de lettrage automatiques, ventilez par axes analytiques, et consultez les
   **comptes annuels** (résultat + patrimoine).
7. **Facturation** : débiteurs, factures avec **zone de paiement QR suisse**
   conforme (PDF), envoi par e-mail et relances. L'IBAN créancier vient du compte
   bancaire, partagé avec la comptabilité.
8. **Événements** : dates de tournée, spectacles (un artiste peut regrouper des
   sous-spectacles), suivi des déclarations **SUISA**, et **exports publics
   JSON/iCal** protégés par jeton — de quoi alimenter un site ou un agenda externe.
9. **Booking** : structures et contacts, étiquettes, lieux géocodés sur une carte,
   campagnes de **mailing** avec désinscription.
10. **Recherche** (champ du tableau de bord ou `/`) : une seule saisie traverse
    employés, structures, contacts, factures, événements et spectacles. Plusieurs
    mots se cumulent, les accents sont ignorés.
11. **Imports** : fiches de salaire (JSON, correspondance par n° AVS — les fiches
    déjà présentes sont ignorées, jamais écrasées), écritures comptables, structures
    et agendas de tournée (CSV). Chaque import a un bouton « Simuler » qui
    prévisualise sans rien enregistrer.

Les comptes se gèrent dans **Paramètres → Comptes**. Chacun reçoit des droits de
**lecture ou écriture, module par module** ; un nouveau compte démarre **sans aucun
droit**, et il doit toujours rester au moins un administrateur. Les modules eux-mêmes
s'activent dans **Paramètres → Modules**, indépendamment de ces droits.

### Les taux

Les taux (AVS, AC, LAA, LPP, etc.) se règlent dans **Paramètres → Taux**. Ils sont
**propres à chaque année**.

- **Impôt à la source** : prélevé uniquement si la procédure « Ordinaire avec impôt
  à la source » est choisie, au taux défini sur la fiche employé.
- **LAA** : deux taux selon le total d'heures du mois (réduit si ≤ jours ÷ 7 × 8,
  sinon plein) ; le bon taux est choisi automatiquement à la création de la fiche.
- **LPP** : taux unique.

> Une fiche déjà créée **conserve les taux figés à sa création**. Modifier la grille
> n'affecte que les fiches futures — les montants passés restent exacts.

### Charges patronales (employeur)

La part employeur (AVS/AI/APG, AC, allocations familiales, maternité, LAA, frais,
CPE, LFP, LPP) se saisit dans la même page. Elle alimente le **coût total employeur**
sur chaque fiche et les **charges à verser** par destinataire (OCAS, LPP, LAA) dans
le tableau de bord.

> Les taux par défaut sont indicatifs (valeurs genevoises). **Confirmez-les avec
> votre affiliation OCAS et votre caisse LPP/LAA.**

---

### Apparence

**Paramètres → Apparence** propose trois thèmes : **clair**, **sombre**, ou
**automatique** (suit le réglage clair/sombre du système). Le choix vaut pour
toute l'installation, comme les couleurs et le fond, et n'est modifiable que par
un administrateur. Les couleurs principale et de mise en évidence restent celles
que vous avez choisies : leurs variantes sombres en sont dérivées
automatiquement, de même que la variante du logo utilisée dans la barre latérale.

Pour qui touche au CSS : toutes les couleurs passent par des tokens définis en
tête d'`assets/app.css`, et seul ce bloc est redéfini en sombre. Écrire une
couleur de fond en dur dans une règle produit un aplat clair au milieu d'une page
sombre — `php tests/run.php` le refuse.

---

## 6. Sauvegarde

Toutes les données tiennent dans **un seul fichier SQLite** (`APP_DB_PATH`).
Pour sauvegarder : le bouton **Paramètres → Exporter** télécharge une copie cohérente,
ou copiez directement le fichier par SFTP. À conserver régulièrement en lieu sûr.

---

## 7. Sécurité

- Mots de passe **hachés** (bcrypt, coût 12) ; minimum 8 caractères (`PASSWORD_MIN`).
- **Anti-force-brute** : blocage temporaire après 5 échecs sur 15 minutes, compté
  **par adresse IP et par e-mail** — sinon un attaquant changeant d'IP visait un
  même compte sans jamais être freiné.
- **Sessions** : expiration après 60 min d'inactivité et 24 h de durée de vie max
  (`SESSION_IDLE` / `SESSION_ABSOLUTE`) ; cookie `HttpOnly` + `SameSite=Lax` +
  `Secure` en HTTPS.
- **CSRF** sur tous les formulaires.
- **HTTPS forcé** + en-tête HSTS ; en-têtes de sécurité (X-Frame-Options,
  X-Content-Type-Options, Referrer-Policy).
- **CSP à nonce** : `script-src` n'accepte plus `'unsafe-inline'`. Chaque `<script>`
  inline porte un jeton aléatoire propre à la requête (`csp_nonce()`), qu'un script
  injecté ne peut pas deviner. Les comportements des vues sont déclaratifs
  (`data-confirm`, `data-print`…) et implémentés dans `assets/app.js` — un attribut
  `onclick=` réintroduirait le trou, aussi `tests/csp_test.php` en interdit-il
  l'apparition.
- **Écran d'installation** protégé par `SETUP_SECRET`.
- **Base de données hors racine web** (via `APP_DB_PATH`). En complément,
  `.htaccess` refuse l'accès direct : `data/` et `lib/` en entier, l'exécution de
  tout script dans `uploads/`, les fichiers `.sqlite`/`.log`/`.bak`/`.md`, les
  fichiers commençant par un point, ainsi que `composer.json`/`.lock` et
  `config.local.php`. Le `data/.htaccess` est versionné **et** recréé par
  l'application s'il manque, pour qu'un déploiement partiel ne laisse pas la base
  exposée.
- Uploads de logos validés (type image réel, 2 Mo max).

> Les données employés (`data/`), les logos (`uploads/`) et la config locale
> (`lib/config.local.php`) sont **exclus du dépôt git** par `.gitignore`.

---

## 8. Développement / test en local

```bash
php -S 127.0.0.1:8000
```

Puis ouvrez http://127.0.0.1:8000. Sans `lib/config.local.php`, l'environnement est
détecté comme **dev** (erreurs affichées, e-mails journalisés dans
`data/emails_envoyes.log` au lieu d'être envoyés).

Lancer l'analyse syntaxique de tout le projet **et** toute la suite de tests
(c'est la commande de l'intégration continue ; code de sortie ≠ 0 au moindre échec) :

```bash
php tests/run.php
```

> Le serveur intégré de PHP ne lit pas les `.htaccess` : en local, les dossiers
> protégés restent accessibles. Sans incidence en production sous Apache.

---

## 9. Limites connues

- L'impôt à la source utilise un **taux unique** par employé (pas de barème officiel
  par tranche) — à confirmer avec une fiducaire si nécessaire.
- Un administrateur peut réinitialiser le mot de passe d'un compte (Paramètres →
  Comptes), mais il n'y a pas de « mot de passe oublié » en libre-service par e-mail.
- Les sauvegardes ne sont pas chiffrées (le fichier exporté est en clair).
