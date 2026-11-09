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

### La rangée d'onglets du téléphone, et ce qu'elle emportait

Quand le rail a pris la navigation, la rangée d'onglets en tête de page
(`.module-tabs`) et celle des sous-onglets (`.module-subtabs`, qui héberge
aussi `.param-subtabs` des Paramètres) ont été effacées **au-dessus de
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
