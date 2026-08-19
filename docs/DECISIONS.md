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

---

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

### CSP : pourquoi `'unsafe-inline'` est encore là

Le retirer suppose de passer aux nonces, et un nonce ne couvre **que** les
balises `<script>`. Or dès qu'un nonce est présent, le navigateur **ignore**
`'unsafe-inline'` : les 85 gestionnaires inline encore présents dans les vues
(`onsubmit`, `onclick`, `onchange` — dont les confirmations de suppression)
cesseraient tous de fonctionner. Le passage au nonce impose donc de les
réécrire d'abord en `addEventListener`. Tant que ce n'est pas fait, ajouter un
nonce casserait l'application sans rien sécuriser.

Les directives qui ne dépendent pas de ce chantier (`object-src 'none'`,
`frame-ancestors 'self'`) sont en place.

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

### Ce qui est mis en cache, et pourquoi

`asset_data_uri_mini()` et `param_logo_data_uri()` ne servent que dans
`views/layout.php`, donc s'exécutaient à **chaque** affichage de page : décodage
PNG, rééchantillonnage GD, réencodage et base64, pour un badge affiché à 12 px.
Mesuré à 4,8 ms par requête, ramené à 0,18 ms par un cache disque
(`cache_disque()`), invalidé par `mtime` + taille du fichier source.

Le data-URI lui-même est conservé — ce n'est pas une coquetterie : le serveur de
développement (`php -S`) n'envoie aucun en-tête de cache, ce qui faisait
clignoter le texte `alt` à chaque navigation.

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

### Fonctions pures extraites pour être testables

Motif déjà présent dans le projet (`permission_donne_lecture()` pure vs
`peut_lire()` qui lit la base), étendu à deux endroits :

- `route_autorisee_pour($niveaux, $modules, $methode)` — verrouille
  l'invariante centrale du dispatch : lecture pour un GET, écriture pour un
  POST, et « un seul module suffit » pour les routes partagées ;
- `facturation_pays_iso2($pays, $paysListe = null)` — la fonction était
  présentée comme pure alors qu'elle appelait `pays_liste()`, donc la base.
  C'est ce qui faisait échouer tout le fichier de tests.
