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
| **Booking** | structures, contacts, tags, lieux (carte), message individuel |
| **Envois groupés** | campagnes de mailing ciblé (dépend de Booking) |

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
- **Paramètres → Mises à jour** affiche la version installée et la version
  disponible sur le canal choisi ; **Paramètres → Serveur** donne le diagnostic
  `exec()`/`git`, les versions de PHP et de SQLite, l'état d'OPcache, et le
  réglage du seuil de recherche ci-dessous.
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
   décompte, éventuel taux d'impôt à la source, date de naissance, N° AVS). La liste
   n'affiche par défaut que les employés **actifs** ; l'entonnoir de la colonne « Nom »
   montre les inactifs. Chaque employé porte une **pastille d'identité** — initiales sur
   une couleur déduite de son nom, remplaçable par une autre couleur ou par une photo
   recadrée depuis sa page.
2. **Fiches → Nouvelle fiche** : choisissez l'employé, le mois, les prestations
   (lignes quantité × unité × taux horaire). Le décompte est calculé et **figé**.
3. Sur une fiche : **Imprimer / PDF**, **Envoyer** par e-mail à l'employé, et — si la
   comptabilité est active — **lier l'écriture bancaire** qui l'a payée, comme on le fait
   pour une facture.
4. **Tableau de bord** : totaux par trimestre / semestre / année et « Salaires à
   verser ».
5. **Certificat de salaire** (page d'un employé) : récapitulatif annuel au format du
   formulaire 11, impression PDF, et **export XML** à importer dans l'application
   officielle *eCertificat de salaire CSI* pour produire les PDF certifiés.
6. **Comptabilité** : créez vos comptes bancaires, importez les relevés au format
   **camt.053** (XML ISO 20022) ou l'export **CSV PostFinance**, lettrez les écritures
   (catégorie du plan comptable), définissez des règles de lettrage automatiques,
   ventilez par axes analytiques, et consultez les **comptes annuels** (résultat +
   patrimoine). D'un relevé camt.053 sont repris la contre-partie, la communication,
   la référence QR et la nature de l'opération ; l'écriture garde la trace de la
   provenance de sa contre-partie, selon qu'elle est déclarée par le relevé ou
   déduite de son libellé.
7. **Facturation** : débiteurs, factures avec **zone de paiement QR suisse**
   conforme (PDF), envoi par e-mail et relances. L'IBAN créancier vient du compte
   bancaire, partagé avec la comptabilité.
8. **Événements** : dates de tournée, spectacles (un artiste peut regrouper des
   sous-spectacles, et chacun peut porter une **icône** recadrée sur place, qui
   le représente ensuite dans les listes — notamment les campagnes), suivi des déclarations **SUISA**, et **exports publics
   JSON/iCal** protégés par jeton — de quoi alimenter un site ou un agenda externe.
9. **Booking** : structures et contacts, tags, lieux géocodés sur une carte,
   et un bouton **Contacter** sur chaque fiche pour écrire à un contact précis —
   modèle de message, brouillon, copie cachée à l'expéditeur, et une entrée
   d'historique à l'envoi. Une structure rattachée à une autre (salle d'un festival,
   antenne d'une faîtière) propose aussi les contacts de celle qui l'organise.
   La colonne « Ville » de la liste porte un entonnoir **« Lieu »** unique où
   l'on cherche un pays, une région, un département ou une ville, et où les
   valeurs cochées se cumulent (« Suisse ou Lyon »).
   Une **campagne** est une sélection de structures à démarcher pour un ou plusieurs
   projets, entre deux dates : on la compose avec les filtres de la liste des
   structures (dont « aucun tag » et « aucune campagne », pour n'approcher que
   ce qui ne l'a jamais été), on décoche ce qu'on ne veut pas, on ajoute au
   besoin une structure en cherchant son nom, puis on contacte ligne à ligne
   depuis la campagne elle-même — la fenêtre « Contacter » s'y ouvre, ou l'on
   marque la structure comme contactée à la main quand le démarchage s'est fait
   ailleurs. Sa jauge compte les prises de contact déjà consignées pour ces
   projets — e-mail comme appel noté à la main —, et chaque ligne porte la
   réponse reçue (aucune, pas intéressé, intéressé), qu'on retrouve sur la fiche
   de la structure dans un cadre « Campagnes » — avec, en dessous, celles qui
   visent son organisateur ou les salles qu'elle organise. La liste des
   campagnes se cherche (nom, projet), se filtre (projet, année, état) et se lit
   en trois tranches — en cours, à venir, passées, les plus proches d'abord
   parmi celles à venir ; la sélection d'une campagne et son suivi affichent le
   tableau des structures, le même qu'en 9, avec ses filtres, ses tags
   modifiables sur place et sa **modification groupée** — auxquels le suivi
   ajoute la réponse reçue. Les
   **campagnes de mailing** avec désinscription, elles, envoient en masse et
   forment un sous-module à part (« Envois groupés »), activable séparément.
   Les adresses d'expédition du booking sont autant de **boîtes**, chacune avec
   son propre serveur SMTP (Paramètres → E-mails → Envois pour le booking).
10. **Sur téléphone**, les grandes listes — structures, salaires, employés,
    événements, factures — se relisent en **fiches** plutôt qu'en tableau à
    faire défiler, chacune montrant les champs qui comptent pour elle. Les
    filtres passent derrière un bouton « Filtres » à côté de la recherche, le
    même qui sert aux vues carte. Au-delà de 700 px de large, les tableaux
    complets reprennent.
11. **Tableau de bord** : une carte par sujet — prochains événements, SUISA,
    évolution financière, salaires à verser, factures émises, campagnes —
    chacune n'apparaissant que si son module est actif et lisible par le compte.
    Le bouton en haut à droite ouvre « Organiser les cartes » : leur
    ordre et celles qu'on ne veut pas voir. C'est un réglage **par compte**, pas
    un paramètre de l'association.
12. **Recherche** (champ du tableau de bord ou `/`) : une seule saisie traverse
    employés, structures, contacts, factures, événements et spectacles. Plusieurs
    mots se cumulent, les accents sont ignorés.
13. **Imports** : fiches de salaire (JSON, correspondance par n° AVS — les fiches
    déjà présentes sont ignorées, jamais écrasées), écritures comptables, structures
    et agendas de tournée (CSV). Chaque import a un bouton « Simuler » qui
    prévisualise sans rien enregistrer.

### Mot de passe oublié

L'écran de connexion porte un lien **« J'ai oublié mon mot de passe »**. On y saisit
l'adresse du compte ; un lien à **usage unique**, valable **une heure**, est envoyé par
e-mail et mène à un écran de choix du nouveau mot de passe.

- L'écran répond **la même chose que le compte existe ou non** : ce formulaire ne doit
  pas permettre de savoir quelles adresses ont un compte.
- La base ne stocke que l'**empreinte** du jeton, jamais le jeton lui-même — comme pour
  un mot de passe. Il n'apparaît pas non plus dans le journal d'e-mails de développement.
- Une nouvelle demande **annule la précédente**, et le lien utilisé ne sert plus.
- Cinq demandes par heure au maximum, par adresse IP et par compte visé. Ce compteur est
  distinct de l'anti-force-brute de la connexion : demander une réinitialisation ne peut
  pas servir à enfermer quelqu'un dehors.
- En production, définissez **`APP_URL`** dans `lib/config.local.php`
  (`define('APP_URL', 'https://salaires.exemple.ch');`). Sans elle, le lien est construit
  depuis l'en-tête `Host` de la requête, que le client contrôle.

Les comptes se gèrent dans **Paramètres → Comptes**. Chacun reçoit des droits de
**lecture ou écriture, module par module** ; un nouveau compte démarre **sans aucun
droit**, et il doit toujours rester au moins un administrateur. Les modules eux-mêmes
s'activent dans **Paramètres → Modules**, indépendamment de ces droits.

La liste se lit d'abord : identité, droits par module, dernière connexion, date de
création. Le **crayon** ouvre l'édition d'une ligne — prénom, nom, adresse e-mail,
mot de passe et droits s'y règlent d'un seul enregistrement (un mot de passe laissé
vide reste inchangé). Changer l'adresse d'un compte invalide les liens de
réinitialisation encore en attente, envoyés à l'ancienne.

### Les lignes du décompte (postes salariaux)

Tout se règle sur une seule page, **Paramètres → Taux → Lignes du décompte** : les
lignes elles-mêmes *et* le taux que chacune applique, pour l'**année choisie en haut
de page**. On peut ajouter une ligne, la renommer, la réordonner **en la glissant**,
ou l'éteindre d'un interrupteur — ce qui permet d'adapter la paie à un autre canton
ou à une autre caisse. Les lignes sont séparées en deux parties, **déductions
employé** et **charges patronales**, comme sur le décompte.

La liste s'affiche en lecture, réduite à l'essentiel : son état, son libellé, son
taux. Le **crayon** ouvre le formulaire d'une ligne : c'est là que se modifient son
taux de l'année, ses paliers d'âge s'il en a, l'option « masquer à 0 » — et là que
se trouve la suppression, à côté d'Enregistrer. Chaque poste déclare :

- sa **nature** (déduction employé ou charge patronale) ;
- son **mode de calcul** : taux de l'année, deux taux selon le seuil d'heures (LAA),
  taux propre à l'employé (impôt à la source), ou barème par tranche d'âge (LPP) ;
- sa **base** : le salaire brut ou le **salaire coordonné** ;
- la **case du certificat de salaire** qu'il alimente (9, 10.1, 12) et son
  **regroupement comptable** (OCAS…), pour que l'ajout d'une ligne n'échappe ni au
  formulaire officiel ni aux récapitulatifs.

Un poste déjà utilisé par une fiche n'est jamais supprimé, seulement désactivé :
l'historique reste lisible.

### Les taux

Les taux sont **propres à chaque année** : le sélecteur en haut de la page choisit
celle qu'on regarde et qu'on modifie. Une année jamais configurée reprend les valeurs
de la précédente jusqu'à ce qu'on en enregistre une.

- **Impôt à la source** : prélevé uniquement si la procédure « Ordinaire avec impôt
  à la source » est choisie, au taux défini sur la fiche employé.
- **LAA** : deux taux selon le total d'heures du mois (réduit si ≤ jours ÷ 7 × 8,
  sinon plein) ; le bon taux est choisi automatiquement à la création de la fiche.
- **LPP** : taux unique par défaut, ou **barème par âge** — les tranches et leurs
  taux sont saisis par l'employeur, année par année, ainsi que l'**âge de référence**
  (âge atteint dans l'année, ou âge révolu au mois de la fiche).
- **Salaire coordonné** : déduction de coordination et plafond, en **francs par an**.
  Tous deux à **0** par défaut : le salaire coordonné vaut alors le brut.

> Une fiche déjà créée **conserve ses montants ET ses taux figés à sa création**,
> ainsi que le libellé de chacune de ses lignes. Modifier la grille ou un poste
> n'affecte que les fiches futures — les montants passés restent exacts.

### Recalculer des fiches existantes

Pour appliquer un changement de taux ou de poste à des fiches **déjà enregistrées**,
il faut le demander explicitement : **Paramètres → Taux → recalcul des fiches**
(`?p=fiches_recalcul`). La page liste, pour l'année choisie, les seules fiches dont
les montants changeraient, avec l'**avant et l'après** côte à côte et le nombre de
fiches concernées. Les fiches **déjà payées ne sont pas cochées par défaut**, et la
base est **sauvegardée automatiquement** juste avant l'écriture.

### Charges patronales (employeur)

La part employeur (AVS/AI/APG, AC, allocations familiales, maternité, LAA, frais,
CPE, LFP, LPP — et tout poste ajouté) se saisit dans la même page, sous
« Charge patronale ». Elle alimente le **coût total employeur**
sur chaque fiche et les **charges à verser** par destinataire (OCAS, LPP, LAA) dans
le tableau de bord.

> Les taux par défaut sont indicatifs (valeurs genevoises). **Confirmez-les avec
> votre affiliation OCAS et votre caisse LPP/LAA.**

### Recherche dans les listes

**Paramètres → Serveur** fixe le nombre de lignes en dessous duquel une liste est
envoyée entière au navigateur : la recherche et le changement de page y sont alors
instantanés, sans aller-retour. Au-dessus, la liste est paginée par le serveur et
la recherche part sur **Entrée** (ou un clic sur la loupe).

La page affiche le volume réel de chaque liste et son mode actuel, pour que le
choix se fasse sur des chiffres. Repères mesurés sur 2 965 structures en mode
navigateur : 207 Ko transférés, 268 ms de chargement, 21 ms par frappe, mais
89 600 éléments gardés en mémoire — ce qui peut peser sur un appareil modeste.
Par défaut **4000** ; `0` force toutes les listes côté serveur, et la valeur est
plafonnée à **20 000**. Le réglage vit en base : le modifier ne demande aucun
déploiement.

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
- Le lien « J'ai oublié mon mot de passe » suppose que **l'envoi d'e-mails fonctionne**
  (adresse d'expédition renseignée dans Paramètres → E-mails, et SMTP configuré en
  production). Sans cela, l'écran répond la même chose mais aucun message ne part —
  l'échec n'est visible que dans le journal d'erreurs du serveur. Un administrateur
  peut toujours réinitialiser un mot de passe depuis Paramètres → Comptes.
- Les sauvegardes ne sont pas chiffrées (le fichier exporté est en clair).
