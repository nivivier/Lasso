# Décisions techniques

Le « pourquoi » des choix structurants de Lasso, et surtout des impasses déjà
explorées. Ce fichier existe pour désencombrer le code : un commentaire doit
énoncer **l'invariante à respecter maintenant**, pas raconter comment on en est
arrivé là. Le récit vit ici, où il ne vieillit pas au milieu du code.

Convention : dans le code, une ligne courte suffit, avec un renvoi ici quand le
contexte compte — `// voir docs/DECISIONS.md § Migrations SQLite`.

---

## Base de données

### Migrations SQLite — ne jamais renommer une table pour la recréer

**Invariante.** Pour recréer une table avec un schéma modifié : créer la
nouvelle sous un nom temporaire (`x_new`), y copier les données, `DROP TABLE x`,
puis `ALTER TABLE x_new RENAME TO x`.

**Pourquoi.** L'approche intuitive — `ALTER TABLE x RENAME TO x_old`, recréer
`x`, copier, supprimer `x_old` — produit des clés étrangères cassées. Vérifié
empiriquement sur SQLite 3.53 : `PRAGMA foreign_keys = OFF` **ne suffit pas** à
empêcher SQLite de réécrire les clauses `REFERENCES` des *autres* tables pour
les faire pointer vers `x_old`. Une fois `x_old` supprimée, ces tables portent
une référence vers une table inexistante. Un `DROP` seul, lui, ne déclenche
aucune réécriture ailleurs. Voir `migration_21` pour un cas réel.

**Filet.** `tests/migrations_test.php` exécute `PRAGMA foreign_key_check` après
toute la chaîne : une migration qui retomberait dans le piège échoue en CI.

### Horodatages : UTC en base, heure locale à l'affichage (2026-11)

Deux horloges cohabitaient. SQLite écrit en UTC : `datetime('now')` est le
défaut de trente-sept colonnes horodatées (`cree_le`, `importe_le`,
`envoye_le`…). PHP écrivait en heure locale (`date()`), et le journal, ajouté
plus tard, avec `datetime('now', 'localtime')` — le fuseau du SYSTÈME de
l'hébergeur, qui n'est pas forcément celui de l'application. Relue par
`strtotime()`, une valeur UTC passait pour de l'heure locale : la date de
création d'un envoi groupé, d'une structure, d'un import s'affichait avec une
heure de retard, deux en été. Le poste de développement, dont PHP était réglé
en UTC, ne le montrait pas : tout y était faux de la même façon.

La convention retenue suit la majorité : **la base est en UTC**. Recréer
trente-sept colonnes pour changer leur défaut aurait été disproportionné ;
il suffisait que PHP écrive comme SQLite. `horodatage_maintenant()` écrit
(`gmdate`), `horodatage()` / `horodatage_ts()` lisent et convertissent vers
`APP_FUSEAU` (Europe/Zurich, fixé par `lib/config.php` au lieu d'être laissé
à `php.ini`). Une valeur qui porte son décalage (`date('c')`, utilisé pour
`email_envoye_le`) se lit telle quelle. Les DATES sans heure (échéance,
`dernier_contact_le`) ne sont pas concernées : un jour n'a pas de fuseau.

Les rares valeurs déjà écrites en heure locale par PHP (dernière connexion,
dernière sauvegarde téléchargée, date de clôture) ne sont pas converties :
les deux premières se réécrivent d'elles-mêmes au prochain usage, la troisième
n'affiche qu'un jour. `tests/horodatage_test.php` interdit d'écrire un
horodatage avec `date()` et de relire une colonne horodatée par `strtotime()`
seul.

### Portée de l'idempotence des migrations

Une étape doit pouvoir être rejouée **à sa propre version**, pas à n'importe
quelle époque. Remettre `user_version` à 0 sur une base complète pour tout
rejouer échoue — et c'est normal : une étape ancienne s'exécuterait contre un
schéma bien plus récent, où les colonnes qu'elle manipule ont été renommées ou
supprimées par des étapes ultérieures (`debiteur_id` devenu `structure_id`).
Cet état n'existe pas : une base est à une version donnée et n'avance que vers
l'avant. Le test vérifie donc le rejeu sur une base à jour — le chemin
réellement emprunté, `db()` appelant `run_migrations()` à chaque requête.

### Découpage de `lib/db.php` (2026-08)

`db.php` mêlait connexion, schéma (58 tables), 68 migrations et valeurs par
défaut sur 2 449 lignes. Séparé en `db.php` (connexion, `param()`, sauvegarde),
`db/schema.php` et `db/migrations.php`. `db()` reste l'unique porte d'entrée ;
les deux fichiers extraits ne sont jamais requis directement.

Piège rencontré pendant l'extraction, signalé immédiatement par les tests :
`__DIR__` change de valeur quand un fichier se déplace. Le seul `require`
relatif de la chaîne (`migration_8` → `compta.php`) a dû remonter d'un cran.

### Découpage de `lib/db/migrations.php` (2026-11)

3 656 lignes : `migrations.php` ne garde que `run_migrations()` (la table
`$steps`) et `migrate_taux()`, et charge `lib/db/migrations/etapes_*.php`, des
tranches de vingt-cinq migrations rangées **par numéro** — le fichier d'origine
ne l'était plus (101 à 105 avaient été insérées au-dessus de 100). Déplacement
textuel, vérifié ligne à ligne ; les deux `require` relatifs vers `compta.php`
remontent d'un cran de plus. `tests/decoupage_test.php` tient la porte comme
pour `helpers.php` et `booking.php`.

### Clôture d'exercice : le verrou est dans la base (2026-11)

`compta_cloture_annee` (paramètre) dit jusqu'à quelle année les écritures sont
figées. Le verrou est posé par des **déclencheurs SQLite** (`migration_104`,
SQL dans `compta_cloture_declencheurs_sql()`), pas par les routes : une dizaine
de routes écrivent dans `ecritures`, plus les cascades (`ON DELETE SET NULL`
d'une catégorie ou d'un compte bancaire) et les scripts. Un contrôle par route
en aurait oublié une ; la base, non. Les déclencheurs lèvent
`RAISE(ABORT, 'LASSO_EXERCICE_CLOS')`, que le gestionnaire d'exceptions
d'`index.php` transforme en message et en retour à la page précédente.

Ce qui reste libre sur une écriture close : ce qui ne change aucun montant de
l'exercice — contre-partie, lien vers une facture ou une fiche, dispense de
justificatif. Un `UPDATE` qui réécrit la même valeur passe aussi (les
déclencheurs comparent `OLD` et `NEW`), sans quoi tout formulaire qui
renvoie la ligne entière buterait.

Les chemins qui parcourent large — l'import d'un relevé, le lettrage par
règles — filtrent les années closes eux-mêmes (`compta_ouvert_sql()`) : buter
sur le verrou au milieu d'un lot annulerait tout le lot.

⚠️ **Une migration qui recrée `ecritures` ou `ecritures_ventilations`** (motif
`x_new` → `RENAME`) **supprime leurs déclencheurs** avec l'ancienne table : elle
doit rejouer `compta_cloture_declencheurs_sql()`. `tests/cloture_test.php`
compte les cinq déclencheurs.

### Secrets : chiffrés en base, clé hors de la base (2026-11)

Les mots de passe SMTP étaient en clair dans `parametres` et
`mailing_expediteurs` — et donc dans chaque sauvegarde téléchargée. Ils ne
peuvent pas être hachés (le serveur de messagerie veut le mot de passe) : ils
sont chiffrés (`lib/secrets.php`, AES-256-GCM via openssl, déjà requis pour le
SMTP chiffré), `migration_106` convertit l'existant.

**Où vit la clé.** Ni dans la base (ce serait ne rien protéger), ni dans le
dépôt. `SECRETS_CLE` dans `config.local.php` si on veut la maîtriser, sinon un
fichier `<APP_DB_PATH>.cle.php` créé au premier besoin, à côté de la base : le
dossier est forcément inscriptible (SQLite y écrit son WAL), et c'est le seul
emplacement que toute installation connaît. Un `.php` qui ne fait que
`return '…'` : servi par erreur, il n'affiche rien. Créé en mode `x` pour que
deux requêtes simultanées ne tirent pas deux clés.

**Le prix, accepté.** L'archive ne contient pas la clé — c'est le but. Une
base restaurée sur un AUTRE serveur arrive donc avec des mots de passe
illisibles : `secret_dechiffrer()` rend `null` (jamais une exception),
l'envoi passe au niveau suivant de la cascade SMTP, et Paramètres → E-mails
affiche « mot de passe à ressaisir ». On perd une ressaisie, pas une donnée.

**Les n° AVS suivent (2026-11).** `employes.numero_avs` et la copie figée
`fiches.employe_avs`, convertis par `migration_108` après une copie de la base
— chiffrer seulement l'employé aurait laissé chaque fiche livrer le numéro.
Écriture par `avs_stocker()`, lecture par `avs_lire()`/`avs_affiche()`. Le
prix : SQL ne voit plus le numéro, l'import de fiches apparie l'employé en PHP
(`avs_trouver_employe()`), et aucune recherche ne portera jamais sur ce champ.
`tests/secrets_test.php` § 10 interdit l'affichage, la recherche et l'écriture
en clair.

**La clé voyage dans l'archive chiffrée, et seulement là.** Perdre les n° AVS
d'une base restaurée ailleurs n'est pas « une ressaisie » comme un mot de
passe : il y en a un par employé et un par fiche. Une archive protégée par une
phrase secrète porte donc `CLE_SECRETS.txt` (chiffré par la même phrase), et
la restauration l'AJOUTE au fichier de clés avant de rejouer les migrations.
Le fichier accepte une liste (`return ['…', '…'];`) : la première chiffre,
toutes déchiffrent. Ajouter et jamais remplacer, parce que la copie « avant
restauration » posée sur le serveur reste chiffrée avec l'ancienne clé — la
remplacer rendrait illisible la seule porte de retour. Une archive sans phrase
ne porte pas la clé : ce serait remettre le numéro en clair à côté de sa
serrure.

**Les jetons ne sont pas chiffrés** (`desinscription_secret`,
`mailing_traiter_token`, `maj_token`, `evenements_*_token`) : ils authentifient
des requêtes entrantes et figurent dans des liens déjà envoyés. Les rendre
illisibles après une restauration casserait les liens de désinscription en
circulation. Ils restent dans la base, donc dans la sauvegarde.

### Sauvegarde : phrase secrète facultative, jamais conservée (2026-11)

Le ZIP chiffré AES-256 (`ZipArchive::setEncryptionName`) se rouvre avec 7-Zip
ou Keka, et dans Lasso ; le TAR.GZ et la base seule n'ont pas d'équivalent
qu'un outil courant sache rouvrir, d'où « ZIP seulement ». Facultative, parce
qu'une phrase perdue rend la sauvegarde inutilisable : pour une association de
deux utilisateurs, c'est un risque réel, à choisir en connaissance de cause.
Saisie deux fois pour la même raison. La route passe de GET à POST : une
phrase n'a rien à faire dans une URL (historique, journaux du serveur), et la
route écrivait déjà la date du téléchargement — un GET qui écrit.

### Sauvegardes : une page à part, et non un type de données (2026-11)

La sauvegarde complète était un « type » du menu d'Exporter, et sa
restauration un « type » du menu d'Importer. Ni l'une ni l'autre n'en est un :
pas d'année, pas de format au choix, et restaurer REMPLACE là où tous les
imports ajoutent. Il fallait que la page d'export masque l'année, affiche trois
blocs et une carte de copies pour ce seul type, et que celle d'import colore
son bouton en rouge pour lui seul. Ce sont aussi deux droits différents :
l'export se lit avec le cœur, tout ce qui touche la base entière est réservé à
l'administration. `?p=sauvegardes` réunit télécharger, le rappel et la
restauration d'une sauvegarde, puis les copies du serveur — rangées par
objet, chacun avec sa restauration ; Exporter et Importer ne
gardent que des données. Pas de redirection depuis l'ancien lien
`?p=export&type=sauvegarde` : le type inconnu est simplement ignoré.

Deux mots, tenus sur toute la page et ses boutons : la **sauvegarde** est
l'archive complète qu'on télécharge, la **copie** la base seule gardée sur le
serveur. Ils se restaurent tous les deux, et le même geste porte le même
signal : un bouton rouge, qu'il vienne d'une copie ou d'une archive. Le bouton
d'une copie s'intitule « Annuler la restauration » seulement si c'est la plus
récente et qu'une restauration l'a posée : une copie plus récente (une
clôture, un import) ferait annuler davantage que la restauration.

### Journal d'activité : relevé par le dispatcher, sans le contenu (2026-11)

La table `historique` du carnet d'adresses est écrite route par route ; elle
couvrait les structures et les lieux, et rien d'autre. Le journal
(`journal_activite`, `lib/journal.php`) est armé **une fois**, dans
`index.php`, pour toute requête POST d'un compte connecté : une route ajoutée
demain est journalisée sans qu'on y pense. L'issue est lue sur la réponse,
dans une fonction d'arrêt (`register_shutdown_function`) : redirection vers
`err=`/`refuse=`, code ≥ 400 ou message d'erreur en session → « refusé ».

Il ne garde **jamais** le formulaire : les mêmes POST portent des mots de
passe, des salaires, des n° AVS. Seulement un identifiant (`id`, `fiche_id`…,
liste fermée `JOURNAL_CIBLES`) et un sous-geste qui a la forme d'un mot-clé.
Le détail d'une modification (avant → après) n'y est donc pas : pour une
fiche, la copie figée y pourvoit ; pour le reste, la sauvegarde.

---

### Postes salariaux : des lignes configurables, sans réécrire le passé

Le décompte avait quinze lignes en dur dans `calculer_fiche()` (AVS, AC, A.mat,
LAA, LPP, impôt à la source, puis neuf charges patronales). Cela tenait tant
qu'il n'y avait qu'un employeur genevois ; un autre canton, une autre caisse, et
la liste change. Les lignes vivent désormais dans `postes_salariaux`.

**Le point dur n'est pas le calcul, c'est l'historique.** Une fiche de mars 2024
doit rester lisible telle qu'elle a été remise à l'employé, même si le poste a
été renommé, désactivé ou supprimé depuis. D'où la table `fiche_postes` : à
l'enregistrement, chaque fiche fige sa propre copie des lignes — code, **libellé**,
taux, montant, rubrique du certificat, groupe comptable. Tout ce qui *lit* une
fiche lit cette copie : l'écran, l'impression, l'e-mail, le certificat de salaire,
les agrégats analytiques. Rien ne lit `postes_salariaux`, qui ne sert qu'à
calculer les fiches **à venir**. C'est ce qui permet de modifier la grille sans
craindre de réécrire quoi que ce soit.

Conséquence assumée : un changement de taux ne se propage pas. Le recalcul est un
geste séparé et explicite (`?p=fiches_recalculer`), avec aperçu avant/après, le
nombre de fiches touchées annoncé, les fiches déjà payées décochées par défaut,
et une sauvegarde automatique de la base juste avant l'écriture.

Une seule page les porte (`?p=postes`), et pas deux : séparer « les lignes » de
« leurs taux » obligeait à faire l'aller-retour pour une question aussi simple que
« combien prélève cette ligne cette année ? », et laissait deux endroits
responsables du même objet. Le taux appartenant à une année, la page porte un
sélecteur d'année. Elle se lit d'abord ; le crayon ouvre l'édition d'UNE ligne,
seul endroit d'où se modifient son taux, ses paliers d'âge, son option « masquer à
0 » et sa suppression — un seul enregistrement pour toute la ligne, via l'attribut
`form=` qui rattache les champs des autres colonnes au formulaire de la ligne.
Seul l'interrupteur « actif » reste actionnable en lecture, comme pour un axe
analytique.

`mode` n'est volontairement **pas** un langage de formules — quatre
comportements connus (`taux`, `taux_employe`, `laa_seuil`, `bareme_age`), parce
qu'une ligne de salaire suisse n'est jamais une expression arbitraire, et qu'un
mini-langage aurait été impossible à valider et à figer.

Trois points où la bascule aurait pu changer des montants, et ce qui les tient :

- **Les totaux restent la somme des montants arrondis**, poste par poste, jamais
  recalculés depuis la copie figée. Sur les 38 fiches réelles, 16 suivent une
  règle d'arrondi plus ancienne (arrondi de la somme non arrondie) et diffèrent
  d'un centime de la somme de leurs propres lignes. Recalculer le total depuis
  les lignes aurait silencieusement déplacé ces centimes.
- **Les colonnes `ded_*` / `emp_*` de `fiches` restent**, alimentées comme avant.
  Elles ne sont plus la source de vérité mais un cache dénormalisé : tout le SQL
  d'agrégation (analytique, exports, imports) s'appuie dessus, et les retirer
  aurait coûté beaucoup pour ne rien gagner. Un poste ajouté par l'employeur n'a
  pas de colonne : il ne vit que dans `fiche_postes`, ce qui suffit.
- **`poste_taux` fait foi pour les taux**, `taux_par_annee` ne reste qu'un repli
  pour une année ou un poste jamais configuré là. `migration_80` a recopié l'un
  dans l'autre, donc la bascule n'a rien déplacé : les taux résolus sur neuf
  années sont identiques à ceux d'avant, aux deux réglages du salaire coordonné
  près (à 0, donc sans effet).

Vérifications faites au moment de la bascule, à reproduire si l'on y retouche :
53 rendus de fiches et de certificats identiques à l'octet près, 24 rendus de
certificats et de pages analytiques idem, et les 38 fiches recalculées par le
nouveau moteur donnant exactement les mêmes montants que l'ancien.

### Le SQLite d'un hébergement mutualisé n'est pas celui du poste de travail

Deux fois, du SQL parfaitement valide en local a échoué en production, et les
deux fois la cause était la même : la version de SQLite de l'hébergeur, plus
ancienne que celle du Mac de développement.

- **`ALTER TABLE … DROP COLUMN`** exige SQLite ≥ 3.35 (2021). En production :
  « near "DROP": syntax error ». D'où le `try/catch` de `migration_63` puis de
  `migration_84` : le retrait d'une colonne est un confort, jamais une
  condition. Sans ce filet, l'exception empêchait `PRAGMA user_version`
  d'avancer, et **toute** requête HTTP plantait sur `db()`.
- **Un sous-select dans une clause `JOIN … ON` ne peut pas y référencer l'alias
  d'une autre table jointe.** L'aperçu de l'export SUISA (v2.6.3) choisissait le
  contact d'une structure ainsi ; en production : « no such column: d.id », à la
  préparation de la requête, donc avant même de l'exécuter. Référencer la
  PREMIÈRE table du `FROM` fonctionne, elle (le même export résout ainsi la
  structure organisatrice depuis `evenements`) — c'est bien l'alias d'une
  jointure qui n'est pas visible là.

La leçon n'est pas « écrire du SQL plus pauvre », c'est **choisir où placer la
logique**. Quand une règle de sélection devient une acrobatie SQL, elle vit
mieux en PHP : `structures_contact_reference()` (lib/booking.php) résout le
contact d'un lot de structures en trois requêtes à listes `IN`, portables
partout, et produit un export identique au caractère près à la version SQL.

Les tests ne rattrapent pas cette classe de bogue : ils tournent sur le SQLite
du poste de travail. Seul le déploiement la révèle — d'où l'intérêt de garder
ces deux cas écrits ici.

## Sécurité

### L'environnement ne doit jamais dépendre de la requête

**Invariante.** `APP_ENV` se résout dans cet ordre : `define()` explicite, puis
variable d'environnement du serveur, puis `PHP_SAPI` (`cli`/`cli-server` →
`dev`), puis **`prod` par défaut**.

**Pourquoi.** La détection précédente lisait `$_SERVER['SERVER_NAME']`. Avec
`UseCanonicalName Off` — défaut de beaucoup d'hébergements mutualisés — cette
valeur n'est pas de la configuration : c'est l'en-tête `Host` envoyé par le
client. Une requête portant `Host: localhost` faisait donc basculer
l'application en `dev`, c'est-à-dire `display_errors` actif, redirection HTTPS
désactivée et e-mails détournés vers un fichier journal.

Le défaut penche désormais du côté sûr : une configuration oubliée masque les
erreurs au lieu de les exposer.

### Protection du dossier de données, en trois couches

Les trois sont volontairement redondantes, parce qu'aucune ne couvre tous les
cas :

1. `data/.htaccess` versionné — `.gitignore` utilise `/data/*` et non `/data/`,
   car git refuse de réinclure un fichier dont un dossier parent est exclu ;
   sans cette forme, le `!/data/.htaccess` n'aurait aucun effet ;
2. `RedirectMatch 404 ^/data/` dans le `.htaccess` racine — ne dépend d'aucun
   fichier du dossier lui-même ;
3. `proteger_dossier_donnees()` écrit le `.htaccess` au `mkdir()` — seule
   couche efficace quand `APP_DB_PATH` désigne un dossier créé à la volée.

Aucune ne fonctionne sous Nginx, qui ignore les `.htaccess` : en production, la
base doit être **hors racine web**. Le dossier contient aussi les sauvegardes
`*.sqlite.bak` (la base entière) et le journal des e-mails.

Ces copies automatiques sont limitées aux `SAUVEGARDES_AUTO_MAX` plus récentes
(`sauvegardes_auto_purger()`, appelée par `sauvegarder_base()`). Jusqu'en
novembre 2026, elles s'accumulaient sans limite : une par mise à jour, import,
fusion ou recalcul, chacune avec toute la base.

### Scripts de maintenance : deux verrous, parce que `$argv` peut venir de l'URL

**Invariante.** Tout fichier de `scripts/` commence par
`if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }`, avant tout
chargement et tout usage de ses arguments. `scripts/` et `vendor/` portent en
plus un `.htaccess` « Require all denied ». `tests/exposition_web_test.php`
vérifie les deux.

**Pourquoi.** L'audit de novembre 2026 a trouvé trois scripts sans garde dans
un dossier ouvert au web. Sous PHP-CGI avec `register_argc_argv` actif, la
chaîne de requête remplit `$argv` : `…/scripts/doublons.php?--appliquer` aurait
fusionné les structures de la base de production. Le `.htaccess` seul ne
suffit pas, puisque Nginx l'ignore ; la garde seule laisserait servir des
fichiers que personne n'a à ouvrir depuis un navigateur.

### Mise à jour par archive : tout ou rien, et un manifeste

**Invariante.** `maj_installer_arbre()` copie de côté tout fichier qu'elle va
écraser ou supprimer **avant** d'en toucher un seul. Au premier échec, elle
défait ce qu'elle a fait, puis relance l'exception. Elle ne supprime que les
fichiers listés dans le manifeste de l'installation précédente
(`maj_manifeste.json`) et absents de la nouvelle archive, et jamais un chemin
de `maj_chemin_protege()`.

**Pourquoi.** L'ancienne `maj_copier_arbre()` recopiait l'archive par-dessus
l'application. Une coupure en route laissait un mélange de deux versions,
alors que seule la base avait été sauvegardée. Et un fichier supprimé en amont
restait sur le serveur, toujours exécutable.

Pourquoi un manifeste plutôt qu'une comparaison avec le disque : le disque
contient aussi des fichiers que l'archive ne connaît pas et qu'il faut garder
(configuration locale, fichiers posés à la main, restes d'un déploiement par
git). Seul ce qu'une mise à jour a installé peut être retiré par la suivante.
La première mise à jour par archive ne supprime donc rien.

La sauvegarde préalable de la base passe par `sauvegarder_base()`
(`VACUUM INTO`) et non plus par `copy()`. En mode WAL, le fichier principal
seul peut manquer des transactions encore dans le journal. Si cette sauvegarde
échoue, on n'installe rien.

### CSP : comment `'unsafe-inline'` a quitté `script-src`

**L'ordre des opérations était tout le problème.** Un nonce ne couvre **que**
les balises `<script>` ; or dès qu'un nonce est présent, le navigateur
**ignore** `'unsafe-inline'`. Les 85 attributs de gestionnaire que portaient
alors les vues (`onclick`, `onsubmit`, `onchange` — dont les confirmations de
suppression) auraient donc tous cessé de fonctionner le jour où le nonce serait
posé, et sans rien signaler. Ajouter le nonce d'abord aurait cassé
l'application sans rien sécuriser.

Les 85 attributs ont donc été réécrits **d'abord**, en écouteurs délégués
(`data-confirm`, `data-print`, `data-preview`… dans `assets/app.js`), puis le
nonce est venu : `csp_nonce()` tire une valeur par requête, mémoïsée pour que
l'en-tête et les vues voient la même, et `script-src` vaut aujourd'hui
`'self' 'nonce-…'`. Un script injecté par une faille XSS ne peut pas deviner la
valeur, donc le navigateur refuse de l'exécuter.

**Ce que ce changement rend possible, et que `tests/csp_test.php` interdit** :
les deux fautes qui en découlent sont silencieuses à l'écriture — une balise
`<script>` inline sans nonce ne s'exécute plus, un attribut de gestionnaire non
plus. Le test les cherche dans le source des vues, pour que l'erreur se voie à
l'écriture et non en production.

`style-src` garde `'unsafe-inline'` : les attributs `style=` restent utilisés
(couleur d'accent d'un module, largeur d'une barre) et un nonce ne s'y applique
pas davantage. Le risque est sans commune mesure avec celui d'un script.

Les autres directives (`object-src 'none'`, `frame-ancestors 'self'`,
`base-uri 'self'`, `form-action 'self'`) sont en place. ⚠️ `object-src 'none'`
interdit `<object>`/`<embed>` : l'aperçu d'un justificatif passe par `<img>` et
`<iframe>` (voir plus haut).

### Afficher un justificatif dans la page, sans le servir dans l'origine

`lib/fichiers.php` envoyait tout en `Content-Disposition: attachment`, et le
commentaire disait pourquoi : un PDF ou une image ouverts dans l'onglet
s'exécuteraient dans l'origine de l'application. Lire le montant d'une facture
reçue supposait donc de la télécharger, de l'ouvrir dans un autre logiciel et de
revenir — pour le geste le plus fréquent de `?p=piece`.

L'aperçu lève l'objection avec trois verrous, et il faut les trois : le type est
reconnu **par le contenu** à l'enregistrement (`finfo`) puis redit en clair avec
`X-Content-Type-Options: nosniff` (un HTML déguisé ne peut pas être interprété
comme du HTML) ; seule une liste courte part en ligne, `FICHIERS_PRIVES_APERCU`,
plus courte que celle du dépôt (ni `.docx`, ni HEIC — ce qu'un navigateur ne
rend pas de lui-même n'a rien à faire dans un aperçu) ; et la réponse porte
`Content-Security-Policy: sandbox`, qui place le document dans une origine
**opaque**, sans script : il ne peut ni lire le cookie de session, ni parler à
la page qui l'encadre. C'est, en une en-tête, ce qu'un hébergeur obtient avec un
sous-domaine séparé.

**Le PDF fait exception, et c'est mesuré.** Sous `CSP: sandbox`, Chrome affiche
l'habillage de sa visionneuse mais ne peint jamais la page — un cadre gris, sans
message — et, en navigation directe, propose le téléchargement au lieu
d'ouvrir. Un PDF part donc en ligne sans cette en-tête. Ce qui le protège
alors : les deux premiers verrous, plus le fait qu'un PDF n'est pas rendu par la
page mais par la visionneuse du navigateur, dans son propre processus — le
script éventuellement embarqué n'y atteint ni le DOM de l'application ni son
cookie.

Deux pièges du côté de la vue (`views/piece.php`) :

- **pas d'attribut `sandbox=` sur le cadre.** Chrome refuse d'afficher un PDF
  dans un cadre marqué `sandbox` : il le traite comme un téléchargement, puis le
  bloque (`ERR_BLOCKED_BY_CLIENT`). L'isolement vient de l'en-tête, qui a en
  prime l'avantage de protéger aussi l'ouverture directe de l'URL ;
- **le fragment `#view=FitH&navpanes=0`** n'est pas cosmétique : sans lui,
  Chrome ouvre à 62 % avec le volet des vignettes déplié, et il ne reste presque
  rien du document dans le cadre.

`object-src 'none'` reste intact : l'aperçu passe par `<img>` et `<iframe>`,
jamais par `<object>`/`<embed>`.

### Polices servies depuis le dépôt

Inter était chargée depuis `fonts.googleapis.com`. Trois raisons de l'avoir
rapatriée dans `assets/fonts/` : la règle du projet (pas de CDN), une requête
bloquante vers un tiers avant le premier rendu, et surtout le fait que chaque
affichage de page transmettait l'adresse IP de l'utilisateur à Google sans
nécessité, dans une application qui traite des salaires nominatifs.

Un seul fichier suffit : Inter v20 est une police **variable**, le même `woff2`
couvre tout l'axe de graisse. L'URL du `<link rel="preload">` doit correspondre
au caractère près à celle du `@font-face`, sinon le fichier est téléchargé deux
fois.

---

## Performance

### La feuille de style en seize fichiers (2026-11)

`assets/app.css` faisait 7 600 lignes d'un seul tenant. Il est découpé à ses
intertitres en `assets/css/01-base.css` … `16-accessibilite.css`, **sans
qu'aucune règle ne bouge** : les fichiers mis bout à bout redonnent l'ancien
texte, dans l'ordre de la cascade. `CSS_FICHIERS` (lib/helpers.php) en tient
la liste ; `css_liens_html()` pose les `<link>` (page, impression, export) et
`css_source()` rend la feuille entière à ce qui l'embarque (un e-mail) ou la
lit (les tests). `tests/css_test.php` vérifie que le dossier et la liste
disent la même chose.

Le prix : seize requêtes au premier chargement au lieu d'une. Il est faible —
les fichiers sont mis en cache un an (`immutable`, versionnés par leur date
via `asset_url()`), et HTTP/2 les sert sur une seule connexion. Pas d'étape de
construction pour les recoller : le déploiement reste un `git pull`.
`assets/app.js` n'est pas découpé : c'est une suite plate de fonctions et
d'écouteurs, que l'on parcourt sans peine.

### Ce qui est mis en cache, et pourquoi

`asset_data_uri_mini()` et `param_logo_data_uri()` ne servent que dans
`views/layout.php`, donc s'exécutaient à **chaque** affichage de page : décodage
PNG, rééchantillonnage GD, réencodage et base64, pour un badge affiché à 12 px.
Mesuré à 4,8 ms par requête, ramené à 0,18 ms par un cache disque
(`cache_disque()`), invalidé par `mtime` + taille du fichier source.

Le data-URI lui-même est conservé — ce n'est pas une coquetterie : le serveur de
développement (`php -S`) n'envoie aucun en-tête de cache, ce qui faisait
clignoter le texte `alt` à chaque navigation.

### Le poids qui compte est le poids COMPRESSÉ

**Correction d'une conclusion trop rapide.** Les optimisations de balisage
ci-dessous (sprite, formulaire mutualisé) ont été annoncées comme « 995 Ko →
494 Ko ». C'est vrai en octets bruts, et trompeur : la prod envoie
`content-encoding: gzip` (`text/html` est dans le `AddOutputFilterByType` du
`.htaccess`). Mesuré sur la même page, mêmes logos :

| | brut | compressé |
|---|---|---|
| avant | 548 896 | 43 990 |
| après | 296 786 | 40 406 |

**−46 % en brut, −8 % sur le fil.** Gzip absorbait déjà la redondance qu'on
supprimait — cent formulaires identiques et 442 icônes tirées de 30 dessins,
c'est précisément ce qu'il compresse le mieux. Le gain réel de ces
optimisations est ailleurs : **52 → 32 nœuds DOM par ligne**, donc du temps
d'analyse et de la mémoire, sur quoi la compression ne peut rien.

**Conséquence pratique.** Une ligne coûte **74 octets compressés**. Toute
micro-optimisation du balisage (indentation, noms de classes, attributs) est
sans objet : supprimer *toute* l'indentation — 27 % d'une ligne en brut — ne
gagne que **3,2 %** compressé. Ne pas y passer de temps ; viser le nombre de
nœuds si un jour il faut aller plus loin.

**Le vrai gisement était ailleurs : les logos inlinés.** Deux variantes (thème
clair et sombre) encodées en base64 dans CHAQUE page pesaient 21 Ko une fois
compressées, soit **52 % du poids d'une page de liste**. Le base64 gonfle de
33 % et ne se compresse pas. `param_logo_src()` sert désormais l'URL en
production — l'hébergeur la renvoie avec `max-age=31536000, immutable` et le
nom du fichier porte une empreinte, donc un téléchargement unique puis plus
jamais — et garde le data-URI en développement, où `php -S` n'envoie aucun
en-tête de cache et où le logo clignotait. Page de 100 structures :
**40 406 → 21 428 octets compressés**.

Le badge « Lasso » du pied du rail reste inliné : c'est une réduction à 32 px
d'un fichier de 190 Ko, servir l'original serait pire.

### Poids des pages de liste : sprite d'icônes et formulaire mutualisé

**Le constat.** Une ligne de `?p=structures` pesait **4 462 octets pour 419
octets de texte visible**. Ventilation mesurée :

| | octets/ligne | part |
|---|---|---|
| formulaire caché « ajouter une étiquette » | 1 834 | 40,5 % |
| icônes SVG recopiées | 1 266 | 28 % |
| texte visible | 419 | 9 % |
| balises, classes, liens | ~940 | 22 % |

Deux tiers de la page étaient donc du contenu invisible et redondant.

**Les icônes.** Sur une page de 100 structures : 442 SVG pour **30 dessins
distincts**, chacun réécrit en entier (~300 octets). `icon()` émet désormais une
référence `<use href="#ico-…">` (~62 octets) et `render()` injecte, juste sous
`<body>`, un sprite des seules icônes réellement rendues — d'où le tampon de
sortie : la liste des icônes utilisées n'est connue qu'une fois le contenu
produit, et le `<symbol>` doit précéder les `<use>`.

Deux pièges contournés :
- les attributs de tracé (`fill`, `stroke`, `stroke-width`) sont posés en CSS sur
  `.ico`, **pas** sur les `<symbol>` : sur le symbole, ils l'emporteraient sur
  toute surcharge CSS visant l'icône (la loupe des champs de recherche est
  volontairement plus épaisse) ;
- `icon()` garde un mode « autonome », actif hors rendu de page : un corps
  d'e-mail n'aura jamais le sprite de la page.

**Le formulaire d'étiquette.** Il était rendu dans CHAQUE ligne — jeton CSRF et
liste des onze étiquettes comprises — alors qu'un seul sert à la fois. Un
exemplaire unique vit maintenant en bas de page et se déplace dans la cellule
cliquée. Le déplacer plutôt que le recréer conserve ses écouteurs, posés sur
l'élément par `lassoInitTagSuggest()`.

**Le menu « Joindre une pièce » des écritures.** Même constat sur
`?p=compta_ecritures` : le menu des types de pièce, rendu dans chaque ligne sans
justificatif, pesait ~1 570 octets par ligne — 40 % d'une page d'un mégaoctet.
Chaque ligne ne porte plus qu'un lien `data-pieces-menu` vers le formulaire de
pièce (ce qu'il fait sans script), et un `<template id="tpl-pieces-ecriture">`
unique est cloné au clic, l'identifiant de l'écriture substitué à
`__ECRITURE__`. Cloner plutôt que déplacer, cette fois : le menu n'a pas
d'écouteur à conserver, et deux lignes peuvent l'avoir ouvert l'une après
l'autre. Mesuré sur la base de démonstration : 1 050 161 → 829 961 octets.
⚠️ L'écouteur est déclaré **après** celui de `LASSO_MENUS`, qui ferme tout menu
ouvert hors duquel on a cliqué : dans l'autre ordre, il refermerait aussitôt le
menu qu'on vient de poser.

**La même chose sur `?p=compta_ecritures`, deux mois plus tard.** La colonne
Catégorie était restée en arrière : chaque ligne portait son `<form>`, son jeton
CSRF, l'identifiant de l'écriture et le champ de saisie — 1 125 octets par ligne
pour un éditeur dont **un seul sert à la fois**. La colonne Axe faisait de même
avec un `<select>` et toutes ses options, alors que le script de la page portait
déjà la liste des axes (`AXES`) et savait construire ce `<select>`
(`buildInlineSel()`).

Les deux sont devenus des singletons : l'éditeur de catégorie **se déplace** dans
la cellule ouverte et rentre chez lui ensuite, le `<select>` d'axe est bâti au
chargement. La cellule ne garde que ce qu'elle montre et dit en `data-` ce que
l'éditeur doit savoir — quelle écriture, quelle catégorie courante.

| `?p=compta_ecritures`, 236 lignes | avant | après |
|---|---|---|
| poids de la page | 1 164 623 o | 980 612 o (**−15,8 %**) |
| octets par ligne | 4 935 | 4 155 |
| `<form>` | 230 | 11 |
| `<option>` | 1 342 | 22 |
| jetons CSRF | 222 | 3 |

⚠️ Rien n'est perdu sans JavaScript : cette colonne ne s'y modifiait **déjà**
pas — le crayon est un `<button type="button">` et le champ n'avait aucun bouton
d'envoi. Une écriture jamais lettrée montre désormais un bouton qui a
exactement l'allure du champ qu'il ouvre, parce que c'est le même geste.

**Résultat**, sur `?p=structures` :

| | avant | après |
|---|---|---|
| octets par ligne | 4 462 | 1 969 |
| page de 200 lignes | 995 Ko | 494 Ko |
| nœuds DOM par ligne | ~52 | 32 |

**Ce que ça change pour le seuil client/serveur** (analyse + mise en page
mesurées en local, transfert non compris) :

| lignes | poids | analyse |
|---|---|---|
| 500 | 0,9 Mo | 94 ms |
| 1 000 | 1,9 Mo | 167 ms |
| 2 000 | 3,7 Mo | 370 ms |
| 5 000 | 9,3 Mo | 954 ms |

Le filtrage lui-même n'est jamais en cause : 30 ms à 5 000 lignes. C'est le
chargement qui décide. Le temps d'analyse suit le nombre de nœuds plus que les
octets — d'où un gain de poids de 50 % pour seulement ~15 % de temps gagné.

### Index : rien à ajouter, le coût est ailleurs

**Mesuré, pas supposé.** Sur les données réelles (plus grosses tables :
`structures` 2 965 lignes, `structure_contacts` 2 909), les requêtes des écrans
de liste coûtent entre **0,004 et 0,42 ms**. À cette échelle SQLite parcourt la
table plus vite qu'il ne consulterait un index : en ajouter n'apporterait rien
et alourdirait les écritures. `ecritures`, que l'on croyait la plus grosse
table, n'en compte que 20.

**Le seul coût notable est la recherche unifiée**, et aucun index ne peut l'aider :
elle filtre sur une expression calculée (`SANS_ACCENTS(texte)`, un rappel PHP
appelé pour chaque ligne), donc rien d'indexable. Deux pistes ont été mesurées :

| | ms (source la plus grosse) |
|---|---|
| rappel PHP `SANS_ACCENTS()` | 1,74 |
| chaîne de `replace()` natifs | 7,20 |

Le repli d'accents en `replace()` SQL, pourtant en C, est **4× plus lent** : 25
`replace()` imbriqués réallouent la chaîne entière à chaque étape, là où le
rappel PHP ne la traverse qu'une fois. L'implémentation en place était donc déjà
la bonne.

**Ce qui a été corrigé :** chaque source exécutait DEUX requêtes (un `COUNT`
puis un `SELECT`), soit deux parcours filtrés identiques. Le total vient
désormais d'un `COUNT(*) OVER ()` calculé dans la même passe — le fenêtrage
s'applique avant `LIMIT`, le total reste donc celui de l'ensemble filtré.
Mesuré : **3,40 ms → 1,72 ms** par source, et 7,0 → 4,1 ms sur une recherche
complète. Les totaux ont été confrontés un à un à un `COUNT` direct.

### Chargement de tous les fichiers à chaque requête — chantier abandonné

`index.php` charge treize fichiers (~13 700 lignes, 632 Ko) quelle que soit la
route. Le chargement à la demande a été envisagé, **puis écarté sur mesure**.

**Le chiffre.** Coût réel de ces fichiers, mesuré en processus neufs, OPcache
hors jeu — donc dans l'hypothèse la plus favorable au chantier :

| | ms par requête |
|---|---|
| démarrage PHP nu | 37,5 |
| + noyau (`config`…`modules`) | 39,5 |
| + tous les fichiers de routes | 45,7 |

Soit **6,2 ms** imputables aux fichiers de routes, et **~0 avec OPcache actif**
(le bytecode est déjà compilé en mémoire). Le gain maximal théorique est donc de
6 ms par requête, dans le seul cas où OPcache serait désactivé.

**Pourquoi c'est non.** Lasso sert un à deux utilisateurs : 6 ms sont
imperceptibles, et invisibles à côté des 37 ms de démarrage de PHP lui-même, sur
lesquels on ne peut rien. En face, le chantier fragilise le point le plus
sensible de l'application — le dispatch et son contrôle de droits — pour un gain
nul dès qu'OPcache est actif, ce qui est le cas par défaut sur PHP moderne.

**Ce qui coincerait, si la question revient.** `nav_groupes()` s'exécute sur
chaque page et appelle les compteurs de badge (`nb_factures_en_retard()`,
`nb_evenements_suisa_a_faire()`…) définis dans les fichiers de **domaine** :
seuls les `routes_*.php` sont réellement différables, pas `booking.php` ni
`evenements.php`. La bonne raison de rouvrir le dossier serait un changement
d'échelle (beaucoup d'utilisateurs simultanés), pas la recherche de ces 6 ms.

---

## Interface

### Le titre de l'onglet du navigateur se déduit de la navigation (2026-11)

Les titres étaient écrits route par route et avaient divergé : « Paramètres —
Pays » mais « Employeur » tout court, et « Mailing — Modèles » longtemps après
que le mot eut quitté l'interface. `titre_onglet()` (lib/modules.php) les
déduit désormais de `nav_groupes()` et `parametres_groupes()` : « Module —
Onglet », « Paramètres — Section ». `render()` l'applique à toute route qui
EST un onglet ; une fiche garde le titre que lui donne sa route, le nom de son
objet.

### La rangée d'onglets du téléphone, et ce qu'elle emportait

Quand le rail a pris la navigation, la rangée d'onglets en tête de page
(`.module-tabs`) et celle des sous-onglets (`.param-subtabs`, partagée avec
les Paramètres) ont été effacées **au-dessus de
800 px** seulement : sous ce seuil le rail est un tiroir hors écran, et elles
semblaient y rester la seule navigation de second niveau. C'était faux — le
tiroir déplie le rail ENTIER, volet du module compris, avec ses pages et ses
sous-pages (Paramètres et leurs trois niveaux y compris). La rangée n'était
plus qu'un doublon visible de l'ancienne interface, et elle se lisait comme
tel. Elle est donc éteinte à toutes les largeurs.

Ce qui est parti avec elle, et qu'il ne faut pas réinventer sans elle :

- **Le défilement horizontal de la bande** sur téléphone (`overflow-x: auto`
  + `scroll-snap`, masque de fondu en bord droit, et le `scrollIntoView` de
  `assets/app.js` qui ramenait l'onglet actif en vue). La leçon sur
  `scroll-padding-left`, elle, est gardée dans `docs/UI.md § 9` : elle vaut
  pour toute bande à accrochage.
- **Le filet peint en FOND de `.page-head`** (un `linear-gradient` d'un pixel
  en bas) plutôt qu'en bordure. Il existait parce qu'un enfant qui défile ne
  peut plus jamais peindre hors de sa boîte : `overflow-x: auto` force
  `overflow-y: auto` (règle de la spec — un `overflow-y: visible` posé à côté
  est ignoré), ce qui rognait le débord de −1px par lequel l'onglet actif
  « trouait » le trait pour se raccorder au contenu. Seul un fond, peint dans
  la zone que l'onglet recouvre, y arrivait.
- **La bordure basse du bandeau mise à zéro** sur téléphone, pour la même
  raison, et en largeur (pas en couleur) : `.page-head-band` est translucide,
  une bordure transparente continue de peindre le flou du fond derrière elle.

⚠️ Et un piège au retrait : `body.titre-dans-barre .page-head-band
:not(:has(.module-tabs)) .page-head { padding: 0 }` mettait un bandeau « sans
onglets » à plat, parce qu'on le croyait vide — son titre devait s'effacer en
doublon de la barre supérieure via `.page-head-titre-module`. Or **plus aucune
vue ne pose cette classe** : le bandeau porte toujours un titre, et la règle
collait « Événements » au bord gauche de l'écran. Elle a été retirée, pas
re-conditionnée.

---

## Tests

### Pourquoi un lanceur unique

Deux régressions ont vécu longtemps dans le dépôt faute de garde-fou :
`tests/facturation_test.php` s'interrompait sur une erreur fatale (la moitié de
ses assertions ne tournait plus) sans que rien ne le signale, et un correctif
d'export iCal écrit et fonctionnel est resté des semaines non commité, donc
jamais déployé.

`php tests/run.php` fait l'analyse syntaxique de tout le projet et lance les
fichiers de test **chacun dans son propre processus** — ils définissent tous une
fonction `check()` globale, les inclure ensemble déclencherait un
« Cannot redeclare ». Un sous-processus convertit en prime toute erreur fatale
en code de sortie 255, donc en échec visible. La CI lance exactement la même
commande.

### Le JavaScript exécuté dans un vrai navigateur

`javascript_test.php` compile les scripts sans les exécuter ; `pages_test.php`
ouvre les pages sans navigateur. Une erreur qui ne survient qu'à l'exécution —
une fonction renommée, un élément disparu, un script inline que la CSP bloque —
passait entre les deux : la bande pleine largeur des pages de paramètres en est
un exemple. `tests/navigateur_test.php` pilote un Chrome sans fenêtre par son
protocole de débogage (une WebSocket, écrite en PHP pur : aucune
bibliothèque), parcourt l'application en suivant ses liens — une page par
route —, y fait les gestes qui ne soumettent rien (menus, crayons, recherches)
et relève exceptions, erreurs de console et blocages CSP. Chrome est un outil
de la machine qui teste, comme Node : absent, le test le dit et passe, sauf en
CI où il échoue. Il ne voit que ce que ses gestes déclenchent : un glisser-
déposer, un envoi de formulaire en arrière-plan restent hors de sa portée.

### Les listes que le CSS doit connaître, mais ne peut pas lire

`STRUCTURE_STATUTS` (lib/booking.php) est la liste de référence des statuts
d'une structure, et presque tout en découle : le sélecteur, les entonnoirs, la
modification groupée bouclent dessus. Mais **quatre endroits la réécrivent**,
parce qu'ils ne peuvent pas la lire — le masque du tableau, la couleur du
segment actif, la case à cocher des mini-cartes, et le nom du tracé dans
`icone_table()`. Les trois premiers sont du CSS : aucune boucle ne les parcourt,
aucune erreur ne les signale.

Le jour où « À vérifier » a été ajouté, les constantes PHP étaient complètes et
la couleur du tableau aussi — et le statut restait bleu dans le sélecteur,
incolore sur téléphone. Rien ne l'avait dit.

`tests/statuts_structure_test.php` compare donc, pour chaque famille de règles,
**l'ensemble que la feuille de style déclare** à la liste de référence. Il ne
tient aucune liste de ce qui devrait exister — elle dériverait comme le reste.
Un sixième statut ajouté aux seules constantes fait tomber six assertions, qui
nomment chacune l'endroit à compléter. Même principe que
`tests/permissions_test.php` § 6, qui part des routes appelant `check_csrf()`
plutôt que d'une liste tenue à la main.

### Fonctions pures extraites pour être testables

Motif déjà présent dans le projet (`permission_donne_lecture()` pure vs
`peut_lire()` qui lit la base), étendu à deux endroits :

- `route_autorisee_pour($niveaux, $modules, $methode)` — verrouille
  l'invariante centrale du dispatch : lecture pour un GET, écriture pour un
  POST, et « un seul module suffit » pour les routes partagées ;
- `facturation_pays_iso2($pays, $paysListe = null)` — la fonction était
  présentée comme pure alors qu'elle appelait `pays_liste()`, donc la base.
  C'est ce qui faisait échouer tout le fichier de tests.
