# Lasso

Gestion administrative d'une petite structure suisse de type association
(fiches et certificats de salaire).

Application web légère de gestion des salaires pour une association (loi suisse).
Le nom et les logos de l'employeur affichés proviennent entièrement de la base de
données (Paramètres → Employeur). Gestion des employés, fiches de salaire
mensuelles, tableau de bord, certificat de salaire annuel (formulaire 11 + export
XML pour l'application « eCertificat de salaire CSI »), envoi des fiches par e-mail
(SMTP authentifié, repli sur `mail()`).

Elle inclut aussi un **module de comptabilité** (compta de caisse) : import des
relevés PostFinance (CSV), lettrage des écritures par catégorie, règles de lettrage
automatiques, comptabilité analytique par axes, et **comptes annuels** (compte de
résultat + patrimoine, comparaison pluriannuelle).

**Technologie :** PHP 8 + SQLite. Aucun framework, aucune dépendance externe.

---

## 1. Prérequis

- Un hébergement avec **PHP 8.1 ou plus** et l'extension **PDO SQLite**
  (souvent natifs sur un hébergement mutualisé).
- Pour le déploiement recommandé : **accès SSH** et **git** sur l'hébergement.
- Pas de MySQL, pas de Composer, pas de Node.

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
| `APP_ENV` | `prod` : erreurs masquées, e-mails envoyés, HTTPS forcé. `dev` : erreurs affichées, e-mails journalisés. | détecté via le nom d'hôte |
| `APP_DB_PATH` | Chemin absolu du fichier SQLite. **À placer hors de la racine web.** | `data/database.sqlite` |
| `FORCE_HTTPS` | Redirection 301 vers HTTPS + en-tête HSTS. | `true` en prod |
| `SETUP_SECRET` | Si défini, l'écran de création du 1ᵉʳ compte exige `?p=setup&key=<secret>`. | vide (désactivé) |

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
     le compte (e-mail + mot de passe d'au moins 12 caractères).

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
  sur le canal choisi, et un diagnostic `exec()`/`git` du serveur. *(La mise à jour
  en un clic depuis l'interface est prévue mais pas encore active.)*

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
7. **Paramètres → Importer** : import de fiches de salaire depuis un fichier JSON
   (correspondance par n° AVS ; les fiches déjà présentes sont ignorées, jamais
   écrasées). Bouton « Simuler » pour prévisualiser sans rien enregistrer.

Les comptes utilisateurs se gèrent dans **Paramètres → Comptes** (création,
réinitialisation de mot de passe, suppression).

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

## 6. Sauvegarde

Toutes les données tiennent dans **un seul fichier SQLite** (`APP_DB_PATH`).
Pour sauvegarder : le bouton **Paramètres → Exporter** télécharge une copie cohérente,
ou copiez directement le fichier par SFTP. À conserver régulièrement en lieu sûr.

---

## 7. Sécurité

- Mots de passe **hachés** (bcrypt, coût 12) ; minimum 12 caractères.
- **Anti-force-brute** : blocage temporaire après 5 échecs par IP sur 15 minutes.
- **Sessions** : expiration après 30 min d'inactivité et 12 h de durée de vie max ;
  cookie `HttpOnly` + `SameSite=Lax` + `Secure` en HTTPS.
- **CSRF** sur tous les formulaires.
- **HTTPS forcé** + en-tête HSTS ; en-têtes de sécurité (CSP, X-Frame-Options,
  X-Content-Type-Options, Referrer-Policy).
- **Écran d'installation** protégé par `SETUP_SECRET`.
- **Base de données hors racine web** (via `APP_DB_PATH`) ; `data/`, `lib/`,
  `uploads/` (scripts) et les `.sqlite` bloqués en accès direct par `.htaccess`.
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

Lancer les tests de calcul :

```bash
php tests/calc_test.php
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
