# Journal des versions

Toutes les modifications notables de Lasso. Format inspiré de
[Keep a Changelog](https://keepachangelog.com/fr/) ; versionnage
[sémantique](https://semver.org/lang/fr/).

Les nouveautés arrivent d'abord sur le canal **test** (section « Non publié »),
puis sont promues sur le canal **stable** en figeant une version.

## [Non publié]

## [2.3.9] — 2026-08-22

### Ajouté
- **La date d'une entrée d'historique se choisit et se corrige.** Le formulaire
  d'ajout d'une note porte un champ date, pré-rempli à aujourd'hui : une note
  se rédige souvent après coup (« appelés lundi ») et se datait jusqu'ici du
  jour de la saisie. Et chaque entrée saisie à la main s'ouvre en édition sur
  place — contenu, date, bascule note ↔ prise de contact — d'un crayon qui
  apparaît au survol de la ligne.

  Seules les entrées « Note » et « Contact / mailing » sont modifiables. Les
  entrées « Modification » sont produites automatiquement par l'application et
  valent journal d'audit ; les rendre réinscriptibles leur ôterait leur raison
  d'être. « Dernier concert » se corrige depuis le champ de la fiche dont elle
  découle.

  L'heure n'est jamais demandée — l'écran ne parle que de date — mais elle est
  **préservée** : redater une entrée garde son heure d'origine, au lieu d'écrire
  un « 00:00 » que l'affichage montrerait comme un fait. À l'ajout, l'heure est
  celle du moment, ce qui garde l'ordre à l'intérieur d'une journée. Les entrées
  importées, stockées en date seule, le restent.

  `dernier_contact_le` est recalculé après chaque modification : il est
  dénormalisé depuis le maximum des entrées « mailing », donc changer la date
  **ou** le type d'une entrée le périme. Le recalcul porte sur la structure qui
  PORTE l'entrée, pas forcément celle qu'on regarde — l'historique affiché
  fusionne celui des lieux organisés.

  **L'acte de création ouvre désormais le flux** : la fiche a une date de
  création (ou d'import) que seule la table `structures` portait, et sans elle
  l'historique commençait au milieu de nulle part. Elle est synthétisée à
  l'affichage, se range à sa date, et n'est pas modifiable.

  **Chaque entrée porte une icône choisie sur ce qu'elle raconte**, non sur son
  seul type : un changement de statut prend l'icône ET la couleur du statut
  atteint, une liaison de lieu un maillon, une étiquette son étiquette. Sans
  cela, les 90 entrées « Modification » d'une fiche portaient toutes le même
  crayon gris et ne se distinguaient qu'à la lecture. Les entrées d'avant la
  migration 63 (« Statut : active/inactive », vocabulaire d'alors) sont
  traduites à la lecture — 34 sur 34 reconnues au lieu de 3.

### Modifié
- **La fiche structure sépare « Historique » et « Historique détaillé ».** La
  première garde les dates de synthèse (connu via, dernier contact, dernière
  modification, création) dans sa colonne ; la seconde, **pleine largeur sous
  la grille**, accueille l'ajout de note et le flux. Le flux était à l'étroit
  dans une colonne de 400 px : les libellés se cassaient en quatre lignes et
  l'ajout de la date a rendu la chose intenable.

  La barre d'ajout est une bande à fond distinct, alignée sur les bords de la
  carte, où date, note, « prise de contact » et le bouton tiennent sur une
  seule ligne et à la même hauteur. Les entrées se lisent une par ligne — date,
  contenu, type et auteur à droite — cinq d'emblée au lieu de deux, le reste
  derrière « Voir les N précédentes », centré.

  L'édition d'une entrée réutilise telle quelle la bascule des cartes de la
  fiche (crayon → enregistrer / annuler, mêmes boutons, même animation) : rien
  de spécifique à l'historique, ni en CSS ni en JavaScript.

### Corrigé
- **Le logo de la page de connexion restait celui du thème clair en mode
  sombre**, donc une encre foncée sur un fond foncé — quasi invisible. La page
  ne rendait qu'une variante, alors que le rail rend les deux depuis toujours
  et laisse le CSS trancher. Même mécanique désormais, avec le même repli quand
  une seule variante est configurée. Vérifié dans les quatre états du thème
  (système clair ou sombre, croisés avec un choix explicite ou aucun).

## [2.3.8] — 2026-08-21

### Modifié
- **?p=structures se charge plus vite** : la page passe de 6,99 Mo à 4,12 Mo
  (−41 %), de 254 Ko à 196 Ko une fois compressée (−23 %), et de 93 593 à
  79 599 balises dans le tableau (−15 %). Mesuré au navigateur, le temps
  d'analyse et de construction du DOM tombe de ~157 ms à ~120 ms ; le serveur,
  lui, répond en autant de temps qu'avant.

  Le corps du tableau fait 98 % de cette page, et une ligne y pesait 2288
  octets pour 157 octets de texte utile. Cinq sources de répétition ont été
  retirées, sans qu'aucune fonction disparaisse :

  - **Les icônes de statut et de marquage ne sont plus dans le balisage.**
    Rendues 5928 fois (deux par ligne), elles coûtaient 439 Ko et 11 856 nœuds
    pour deux dessins qui ne varient jamais. Elles viennent désormais d'un
    masque CSS choisi d'après ce que le balisage déclarait déjà — `data-statut`
    sur la ligne, `.flag-*` sur le bouton. La couleur suit toujours le texte,
    donc le thème sombre aussi. Le JS n'a plus d'icône à réinjecter au clic :
    il change la classe, le CSS fait le reste (1,6 Ko de moins dans `app.js`).
  - **`data-href` sur chaque ligne** recopiait à l'octet près le lien du nom
    déjà présent dans la ligne : 56 octets par ligne, 15,9 Ko compressés.
    `go()` retombe sur ce lien quand l'attribut manque ; les autres listes, qui
    le posent toujours, ne changent pas.
  - **`data-flag-table` et `data-flag-valeur`** : le premier valait toujours
    « structure », valeur sur laquelle le JS retombe déjà en son absence ; le
    second n'était jamais lu.
  - **L'infobulle « Créée ou importée le… »** de la colonne « Dernière
    modification », répétée sur 2159 lignes : 203 Ko et autant de `<span>`.
    L'italique de la colonne dit déjà qu'il s'agit d'un repli.
  - **L'indentation du gabarit entre les cellules** — 751 Ko d'espaces que le
    navigateur ne rend pas. Le gabarit reste lisible : c'est sa sortie qui est
    compactée (`compacter_cellules()`, lib/helpers.php), et seulement aux
    endroits où le HTML ne rend rien. À l'intérieur d'une cellule, les espaces
    sont réduites à une seule et non supprimées : elles y séparent deux
    étiquettes ou une icône et un nom.

## [2.3.7] — 2026-08-21

### Ajouté
- **Doublons potentiels dans « Paramètres → Incohérences »**, à côté des
  doublons exacts. Rapproche les structures dont le nom se ressemble à au moins
  le seuil choisi (80, 85, 90 ou 95 %) et dont le département / canton est
  identique — les saisies en double sous une variante, qu'un `GROUP BY` ne peut
  pas voir : « Festival Ça Va Bien S'Passer » et « Festival « Ca va bien
  s'passer » », « Go out » et « Go Out ». La comparaison ignore la casse, les
  accents et la ponctuation. Sur la base actuelle : 119 rapprochements à 85 %,
  34 à 90 %.

  Le canton n'est pas qu'un critère de pertinence, c'est ce qui rend le
  balayage réaliste : sans lui, 2965 structures font 4,4 millions de paires.
  Les fiches sans canton renseigné ne sont donc pas comparées, et l'écran le
  dit. Les doublons déjà exacts ne sont pas répétés.

  Le résultat est une liste de **paires**, jamais des groupes transitifs : si A
  ressemble à B et B à C, A et C peuvent n'avoir rien à voir, et les fondre
  proposerait une fusion que personne n'a jugée.

  Chaque paire se **fusionne** (la fiche la plus ancienne est conservée, comme
  pour les doublons exacts, avec sauvegarde automatique de la base) ou se met
  **de côté** : un rapprochement légitime — une antenne et sa maison mère, deux
  salles d'un même lieu — ne sera plus reproposé. Les paires écartées se
  relisent en bas de la carte et se remettent en jeu d'un clic. « Ignorer » ne
  touche à aucune fiche.

## [2.3.6] — 2026-08-21

### Corrigé
- **Le filtre « Dernière modification » de `?p=structures` ignorait les dates de
  création**, alors que la colonne, elle, s'y replie depuis la 2.3.3 quand rien
  n'a été modifié. Une structure importée la veille s'affichait donc à sa date
  mais ne sortait sous aucune tranche récente — elle n'était trouvable que sous
  « Jamais », avec 2160 autres. Le filtre porte désormais sur la date affichée,
  repli compris : les 2965 structures se répartissent dans les tranches (2158
  sous « Ce mois »), et « Jamais » ne garde que les lignes sans aucune date.
  Les dates sont au passage ramenées au jour avant comparaison, sans quoi une
  création de la veille tombait dans « Moins de 24 h ».
- **La carte des structures perdait le filtre par étiquette** : la vue ne
  chargeait pas la liste des étiquettes, si bien que le filtre n'avait pas de
  bouton dans son panneau — et qu'une étiquette déjà active s'y affichait sous
  son numéro brut (« 2 ») au lieu de son nom.

### Ajouté
- **Bouton « Filtres » sur `?p=fiches` et `?p=facturation_liste`** en mode
  téléphone. La mise en cartes masque le `<thead>`, et avec lui les entonnoirs
  accrochés aux en-têtes : ces deux listes étaient devenues infiltrables sur
  téléphone. Même panneau que les structures et les événements — les filtres y
  portent leur libellé, faute d'en-tête pour les nommer, et la bande des filtres
  actifs les résume dessous.

### Modifié
- **Le bouton « Filtres » indique combien de filtres sont actifs**, d'une
  pastille chiffrée et d'un fond teinté, comme un filtre nommé. Panneau fermé,
  rien ne le disait : la bande des filtres actifs vit *dans* le panneau, donc
  une liste filtrée se lisait comme une liste complète.

## [2.3.5] — 2026-08-21

### Corrigé
- **Ajouter une étiquette depuis la fiche d'une structure échouait** sur une
  erreur fatale (violation de clé étrangère) : le formulaire d'étiquette de la
  fiche et celui, mutualisé, de la liste des structures portaient le même
  identifiant HTML. Le script qui pilote celui de la liste attrapait donc celui
  de la fiche et y remettait l'identifiant de structure à vide, l'ajout partant
  alors avec un id 0. Chacun a maintenant son identifiant, et le script ne
  répond qu'aux boutons « + » qui portent un identifiant de structure.
  Régression présente depuis la 2.3.0.
- **Le défilement horizontal des tableaux restés des tableaux sur téléphone
  était supprimé** — la comptabilité en particulier, dont les colonnes ne
  tiennent pas dans un écran. La règle qui le désactive n'a de sens que pour les
  listes en cartes, mais elle n'avait jamais été cloisonnée derrière
  `.liste-cartes` : elle visait `.table-scroll`, le conteneur commun à tous les
  tableaux. Elle est simplement retirée — les listes en cartes tiennent dans la
  largeur d'elles-mêmes et ne défilent donc pas.

### Ajouté
- **?p=evenement : les structures liées s'affichent en mini-fiches** dans la
  carte « Organisation », au lieu d'une simple pastille « Nom — Ville ». Même
  vocabulaire que les fiches de `?p=structures` : nom, ville avec drapeau et
  canton, catégorie et les deux dates. Le statut y est porté par son **icône**
  — cœur, coche, croix, cercle pointillé — et non par la bordure d'une case à
  cocher comme dans la liste : il n'y a rien à cocher ici, et un carré bordé y
  aurait faussement l'air d'une case. Les étiquettes de chaque structure y
  figurent aussi, sur leur propre ligne — sans le bouton « + » de la liste : on
  étiquette une structure depuis sa fiche, pas depuis l'organisation d'un
  événement.

### Modifié
- **Le menu burger passe à gauche sur téléphone**, avec le tiroir qui s'ouvre du
  même côté et son bouton de fermeture au même endroit — la navigation est à
  gauche sur bureau, elle l'est désormais partout.

## [2.3.4] — 2026-08-20

### Corrigé
- **Les listes salaires, employés, événements et factures étaient illisibles sur
  téléphone** depuis la 2.3.2. La mise en mini-cartes de `?p=structures` ciblait
  `.list-wide`, une classe que ces quatre listes partagent : leurs lignes
  passaient en grille sans avoir la moindre classe `col-*`, en-têtes masqués et
  cellules superposées. Toute la mise en cartes est désormais cloisonnée
  derrière `.liste-cartes`, une classe que la vue pose explicitement.

### Ajouté
- **Les quatre autres listes passent aussi en mini-cartes sur téléphone**, avec
  les champs retenus pour chacune : salaires (employé, mois, brut, net,
  paiement, accusé d'envoi), employés (nom, dernière fiche), événements (date,
  spectacle, ville et salle, statut), factures (structure, numéro, échéance,
  montant, paiement). Même mécanique que les structures — les mêmes `<td>`
  replacés en grille, aucun gabarit supplémentaire — chaque liste ayant son
  propre agencement. Les colonnes masquées le sont **par exclusion** : une
  colonne ajoutée un jour au tableau sera écartée d'office de la carte, plutôt
  que d'y apparaître sans qu'on l'ait voulu. Chaque liste met en avant ce par
  quoi on la parcourt : l'employé et le mois au même corps pour les salaires,
  la date pour l'agenda des événements, le montant pour les factures.

### Modifié
- **?p=structures&vue=carte : les filtres passent derrière le bouton
  « Filtres »**, comme en mode mobile, au lieu d'occuper la barre d'outils en
  permanence. La carte n'a aucun en-tête de colonne où poser les entonnoirs, et
  le même panneau sert donc les deux cas ; l'entonnoir « Plus de filtres »
  disparaît, le panneau portant déjà les champs jauge et mois. Le champ de
  recherche retrouve du coup toute la largeur, comme sur les autres pages — il
  n'était rétréci que pour laisser la place aux filtres affichés à plat.
- **?p=evenements_liste : mêmes filtres derrière le même bouton**, en vue carte
  à toute largeur et en vue liste sur téléphone — son `<thead>` y étant masqué
  par la mise en mini-cartes, ses filtres seraient sinon devenus inatteignables.
  Le panneau montre aussi les filtres actifs en pastilles à croix de retrait.
- **Fonds de carte OpenStreetMap en thème sombre.** Les tuiles sont inversées à
  l'affichage plutôt que servies par un second fournisseur : pas d'hôte externe
  supplémentaire, pas de ligne de CSP en plus. Une rotation de teinte rattrape
  l'inversion — sans elle, la végétation vire au rose et l'eau à l'orange. Le
  filtre ne porte que sur le calque des tuiles : marqueurs, tracés et infobulles
  gardent les couleurs de l'application.
- Les **lignes de séparation par mois ou par année** (salaires, factures,
  événements, comptabilité) gardent leur retrait à gauche sur téléphone : leur
  libellé se collait au bord de l'écran. La remise à zéro du retrait de première
  cellule, qui n'a de sens que dans une liste en cartes — c'est la grille de la
  fiche qui l'y pose — est du même coup réservée à celles-ci : appliquée à tous
  les tableaux, elle collait aussi au bord la première colonne de la
  comptabilité.
- **?p=structures sur téléphone : les filets de séparation vont d'un bord à
  l'autre de l'écran**, le tableau reprenant la pleine largeur qu'il a partout
  ailleurs ; le contenu des fiches reste aligné sur le reste de la page.
- **Le nom de structure dispose de 29 px de plus.** La colonne de droite était
  réservée à 136 px quelle que soit la catégorie : « Autres » en occupait 34 et
  le nom se tronquait alors qu'il restait 60 px de libre à côté. Elle se
  dimensionne désormais sur la catégorie, avec un plancher à la largeur d'une
  date — les deux dates étant posées hors flux, elles ne participent pas au
  calcul et déborderaient sur le nom sans ce plancher.

## [2.3.3] — 2026-08-20

### Ajouté
- **?p=structure : date de création / d'import** dans la carte d'historique, à
  côté de « Dernier contact » et « Dernière modification ». `structures.cree_le`
  vaut `datetime('now')` à l'insertion : pour une fiche importée, c'est donc la
  date de l'import et non celle de la création chez la source — d'où le libellé
  « Créée / importée », qui couvre les deux cas.

### Modifié
- **?p=structures sur téléphone : la fiche est réorganisée.** Le nom puis la
  ville à gauche, la catégorie puis les deux dates à droite, les étiquettes sur
  toute la largeur. Chaque information a sa ligne : la ville et la catégorie ne
  se disputent plus la place, et le nom dispose de toute la largeur restante.
  Le canton et la catégorie descendent à 10 px, les étiquettes à 9 px.
- **Le statut passe sur la case à cocher.** L'icône de statut disparaît de la
  fiche ; c'est la **bordure de la case** qui en prend la couleur — rose, vert,
  rouge, gris, les mêmes que la colonne « Statut » du bureau. « Ne pas
  contacter » et « inactif » sont en **pointillés**, de sorte que les deux
  statuts qui disent de laisser la structure tranquille se reconnaissent sans
  lire la couleur. La bordure garde sa teinte même quand la ligne est cochée :
  sélectionner n'efface pas le statut. La cellule de statut reste dans le
  document, hors du champ visuel, pour que son libellé continue d'exister pour
  les lecteurs d'écran.
- **?p=structures sur téléphone : lignes séparées plutôt que cartes
  encadrées.** Chaque fiche n'est plus dans sa boîte ; un filet les sépare.
  L'intérieur ne change pas.
- **Rythme vertical régulier** dans ces lignes : 22 px entre le nom et les
  dates, puis entre les dates et les étiquettes. Les rangées sont désormais de
  hauteur fixe — en « auto », c'était le contenu le plus haut de la rangée, donc
  le bloc de droite, qui décidait où tombaient les dates. Ce bloc (ville,
  drapeau, canton, catégorie) est **sorti du flux** : ce qui est aligné à droite
  ne décale plus ce qui est aligné à gauche. Sa largeur reste réservée dans la
  grille, sans quoi un nom long passerait dessous au lieu de se tronquer.
- **?p=structure sur téléphone : un nom de structure long passe à la ligne** au
  lieu d'être tronqué — la fiche est la page qui doit le donner en entier. Vaut
  aussi pour un nom d'un seul tenant, sans espace.
- **?p=structures : la colonne « Dernière modification » se replie sur la date
  de création** quand aucune modification n'est enregistrée — 2160 structures
  sur 2965 sont dans ce cas et la colonne y restait vide. La date de repli est
  en **italique**, avec son libellé complet au survol, et la mini-carte mobile
  y remplace le crayon par une icône de création : une création n'est pas une
  modification, la colonne ne doit pas laisser croire le contraire. Le filtre
  d'ancienneté de la colonne continue de porter sur la seule date de
  modification, « Jamais » gardant donc son sens de « jamais modifiée ».

## [2.3.2] — 2026-08-20

### Ajouté
- **?p=structures en mini-cartes sur téléphone.** Sous 700 px de large, le
  tableau de douze colonnes se relit en une carte par structure : icône de
  statut et nom à gauche, ville puis drapeau + canton puis catégorie-feuille
  empilés à droite, les deux dates (crayon = dernière modification, enveloppe =
  dernier contact) sous le nom, les étiquettes sur leur propre ligne. Les cases
  à cocher et la barre d'actions groupées restent disponibles. Aucun gabarit
  supplémentaire : ce sont les **mêmes `<td>`** repositionnés en grille CSS —
  à 4000 lignes possibles en mode client, un second balisage aurait pesé plus
  que la page entière. Les colonnes sans place sur un téléphone (structures
  liées, contact, factures, événements) et le marquage rapide restent dans la
  vue bureau, inchangée.
- **?p=structures : bouton « Filtres » sur téléphone.** Le `<thead>` étant
  masqué en mode carte, ses entonnoirs de colonne — statut, catégorie, pays,
  département/canton, étiquettes, marquage, événements, dernier contact,
  dernière modification — se retrouvent derrière un bouton unique (entonnoir,
  sans libellé) à côté de la recherche, avec les champs jauge et mois dans le
  même panneau. Le bouton « Plus de filtres » disparaît alors, pour ne pas
  laisser deux entonnoirs côte à côte. Recherche, bouton de filtres et boutons
  d'action tiennent sur une seule ligne, et le panneau se déploie **par-dessus**
  la liste plutôt que de repousser les boutons d'action à la ligne suivante.
  Le panneau s'ouvre sur la liste des **filtres actifs**, en pastilles à croix
  de retrait — mêmes composants que le ciblage de `?p=mailing_campagne`
  (`.filtres-ciblage-actifs` / `.col-th-actif`), pour que les deux écrans qui
  filtrent des structures se lisent pareil.
- **?p=structures : colonne « Dernière modification »** (petit texte, comme
  « Dernier contact »), avec un filtre d'ancienneté sur chacune des deux
  colonnes : moins de 24 h, cette semaine, ce mois, cette année, 1 à 3 ans,
  plus de 3 ans, jamais. Les tranches sont **disjointes**, de sorte que cocher
  plusieurs cases donne bien leur union — emboîtées, « Cette année » aurait
  absorbé « Ce mois » et les cases n'auraient plus rien voulu dire. La tranche
  « Jamais » n'est pas accessoire : 73 % des dates de modification et 78 % des
  dates de contact sont vides.

### Modifié
- **`?p=mailing_campagne` : le critère « Type de lieu » disparaît**, redondant
  avec « Catégorie » — il filtrait sur `structures.sous_categorie`, exactement la
  colonne que la catégorie interroge déjà pour une sous-catégorie. Retiré du
  formulaire, de la lecture des critères et du SQL de ciblage. Sans effet sur les
  ciblages enregistrés (aucun ne s'en servait) ; la colonne `type_lieu` de
  l'import CSV, qui n'a rien à voir, est inchangée.
- **Zone de filtres unifiée entre `?p=structures` (mobile) et
  `?p=mailing_campagne`.** Là où les filtres ne sont pas accrochés aux colonnes
  d'un tableau, le libellé entre dans le bouton : toute sa surface devient
  cliquable, au lieu d'un entonnoir de 18 px posé à côté d'un texte inerte, et
  l'état actif se lit sans ouvrir le menu — fond teinté plus le nombre de
  valeurs cochées. Même disposition des deux côtés (rangée de boutons qui
  s'enroule, puis la bande des filtres actifs en dessous). Le principe reste le
  menu déroulant à cases à cocher. Les en-têtes de colonne des tableaux
  (`?p=fiches`, `?p=compta_ecritures`, `?p=evenements_liste`,
  `?p=facturation_liste`, `?p=structures` au bureau) gardent l'entonnoir seul :
  le nom de la colonne et les pastilles de valeurs actives y font déjà ce
  travail.
- La pastille de filtre actif « de groupe » (une plage de mois, un encadrement
  de jauge — les critères qui ne sont pas des cases à cocher) devient
  `filtre_pille_groupe_html()` dans `lib/helpers.php`, au lieu d'une fermeture
  locale à `views/mailing_campagne.php`. Les deux écrans la partagent.

- Le bouton « Plus de filtres » prend l'aspect actif des boutons Liste/Carte
  quand le panneau est ouvert : le panneau s'affichant en position fixe, rien
  ne signalait plus, une fois la page défilée, que des filtres supplémentaires
  étaient en jeu.

### Corrigé
- **`?p=mailing_campagne` affichait un commentaire du code en clair** dans la
  page, entre le ciblage et le message. Un commentaire `//` citait une balise
  PHP fermante : `?>` referme le bloc même à l'intérieur d'un commentaire de
  ligne, et les trois lignes suivantes partaient en HTML. Bug présent depuis
  longtemps, donc visible en production.

### Sécurité
- **?p=mailing : le déclenchement manuel de la file d'envoi exige désormais le
  droit d'écriture sur Booking.** La page est accessible en lecture seule, et
  l'URL de traitement — qui porte le jeton autorisant `?p=mailing_traiter`, seule
  protection de cette route puisqu'elle est appelée sans session par le
  planificateur — y était affichée à côté du bouton. Un compte en lecture pouvait
  donc déclencher les envois. Le jeton n'est plus seulement masqué : il n'est plus
  construit, donc absent de la page.

## [2.3.1] — 2026-08-20

### Modifié
- **Actions groupées (?p=structures)** : les sept « Modifier le/la … » sont
  regroupées derrière une entrée **Modifier…**, qui ouvre un second menu listant
  le champ à changer (statut, flag, ville, département/canton, pays, catégorie,
  connu via). La liste principale passe de douze à cinq entrées, et « Fusionner »
  perd sa précision « (2 sélections ou plus) » — la contrainte reste signalée au
  moment de valider.
- Le bouton d'validation de ces actions devient une **icône** (disquette,
  corbeille ou fusion selon l'action), avec un libellé accessible qui suit :
  « Modifier la sélection » occupait toute la largeur sur un téléphone.
- Formulaire d'ajout d'étiquette d'une ligne : champ et boutons réduits à 26 px
  de haut, à l'échelle des badges qui l'entourent.

### Corrigé
- **Tableau de bord : les tableaux débordaient de leur carte.** Le nom (employé,
  structure) est de longueur libre et ne pouvait pas rétrécir : la mise en page
  automatique élargissait le tableau au-delà du cadre. Cette colonne absorbe
  désormais la place restante et se tronque au besoin. Sur petit écran, les
  marges internes des cellules sont resserrées — elles suffisaient à elles seules
  à faire déborder de 52 px.
- **Barre d'actions groupées sur mobile** : elle était en `flex-wrap: nowrap` et
  dimensionnée à son contenu, si bien que tout se tassait sur une ligne et que le
  champ de saisie devenait inutilisable. Elle s'étale désormais sur la largeur de
  l'écran et revient à la ligne — le champ passe de quelques pixels à 85 % de la
  largeur.
- **Listes de suggestions d'étiquettes** : elles s'ouvraient toujours vers le
  bas, donc hors écran depuis la barre d'actions groupées, fixée en bas. Le sens
  d'ouverture est maintenant décidé par la place réellement disponible — ce qui
  couvre aussi une ligne de tableau en bas de page.

## [2.3.0] — 2026-08-20

### Ajouté
- **Choix du thème** dans Paramètres → Apparence : clair, sombre, ou automatique
  (suit le réglage du système). Réglage commun à l'installation, comme les
  couleurs — rendu côté serveur, donc sans scintillement ni JavaScript.
- **Mode sombre**, appliqué selon ce choix.
  Seule la palette de tokens est redéfinie : aucun composant n'a été retouché,
  ce qui suppose qu'aucune couleur de surface ne soit écrite en dur — quinze
  l'étaient encore et sont devenues des tokens. `tests/theme_test.php` interdit
  la réapparition d'une surface claire en dur, faute silencieuse par nature :
  elle ne se voit que si l'on bascule son système.
- Le logo de la barre latérale suit le thème : la variante « fond sombre »
  remplace la variante « fond clair ». Les deux sont rendues, le CSS choisit —
  seul moyen de rester juste en mode automatique, où le serveur ignore le
  réglage du système.

### Modifié
- Recherche unifiée : une seule requête par source au lieu de deux (le total
  vient d'un `COUNT(*) OVER ()` calculé dans la même passe). Mesuré 3,40 → 1,72 ms
  par source, 7,0 → 4,1 ms sur une recherche complète.
- La respiration horizontale (`--content-pad`) et la largeur du rail
  (`--rail-width`) suivent la fenêtre au lieu d'être figées : à 1024 px, 920 px
  de largeur utile au lieu de 808.

### Corrigé
- **La recherche des listes paginées ne recharge plus la page à chaque lettre.**
  Un délai de 400 ms après la dernière frappe déclenchait une navigation, souvent
  plusieurs fois pendant la saisie d'un seul mot. La recherche part désormais sur
  **Entrée ou clic sur la loupe**. Les listes courtes, elles, continuent de
  filtrer en direct sans aller-retour serveur. Concerne structures, écritures,
  factures, événements et employés.
- Au passage : le clic sur la loupe conservait mal le contexte. Le formulaire ne
  porte que `p`/`vue`/`depuis`, si bien qu'un envoi natif effaçait les filtres de
  colonnes actifs ; il est désormais intercepté comme Entrée, filtres préservés
  et pagination remise en page 1.
- ?p=structures, « Plus de filtres » : le panneau s'ouvrait ~41 px trop à gauche
  (ancré sur la boîte de la barre d'outils, sans tenir compte de son padding) et
  s'affichait dans une carte flottante inutile, posée sur une zone vide. Il
  s'aligne maintenant sur le champ de recherche et n'a plus de cadre — sauf en
  vue carte, où il recouvre réellement la carte et doit rester lisible.

### Performance
- **Logos servis par URL en production** au lieu d'être encodés en base64 dans
  chaque page : ils pesaient 21 Ko compressés par page, soit 52 % du poids d'une
  page de liste. L'hébergeur les renvoie avec un cache immuable d'un an et leur
  nom porte une empreinte — un téléchargement, puis plus jamais. Page de 100
  structures : 40,4 Ko → 21,4 Ko compressés. Le data-URI est conservé en
  développement, où le serveur intégré n'envoie aucun en-tête de cache.
- **Le seuil de bascule liste navigateur/serveur se règle dans Paramètres →
  Serveur** (onglet renommé, il s'appelait « Diagnostic du serveur »), avec le
  volume réel de chaque liste et son mode en regard. Il vivait dans une constante
  du code, donc changer d'avis demandait un déploiement.
- Seuil porté à **4000** (à l'essai) : toutes les
  listes actuelles filtrent et paginent désormais dans le navigateur, sans
  aller-retour. Sur les 2 965 structures : 207 Ko compressés, 268 ms de
  chargement, recherche en 21 ms par frappe.
- **Poids des pages de liste divisé par deux.** Une ligne de `?p=structures`
  pesait 4 462 octets pour 419 octets de texte visible. Les icônes SVG, recopiées
  en entier à chaque occurrence (442 par page pour 30 dessins distincts), passent
  par un sprite ; le formulaire d'ajout d'étiquette, rendu dans chaque ligne avec
  la liste complète des étiquettes alors qu'un seul sert à la fois, devient un
  exemplaire unique déplacé dans la ligne cliquée. Page de 200 structures :
  995 Ko → 494 Ko. Le sprite allège toutes les pages du site, pas seulement les
  listes.

### Notes
- Index de la base : rien à ajouter, mesure à l'appui (requêtes de liste entre
  0,004 et 0,42 ms sur les données réelles). Détail dans `docs/DECISIONS.md`.

## [2.2.0] — 2026-08-19

### Sécurité
- **Politique de sécurité de contenu à nonce** : `script-src` n'accepte plus
  `'unsafe-inline'`, ce qui la rendait largement inopérante contre l'injection de
  script. Chaque `<script>` inline porte désormais un jeton aléatoire par requête ;
  un script injecté, ne pouvant le deviner, est refusé par le navigateur — vérifié.
- Préalable indispensable : les **85 attributs de gestionnaire**
  (`onclick`/`onsubmit`/`onchange`) que portaient les vues ont été supprimés. Un
  nonce ne les couvre pas, ils auraient tous cessé de fonctionner. Ils sont
  remplacés par des comportements déclaratifs (`data-confirm`, `data-print`,
  `data-submit-on-change`, `data-submit-form`, `data-go-on-change`) implémentés en
  écouteurs délégués dans `assets/app.js`.
- `tests/csp_test.php` interdit la réapparition d'un `<script>` sans nonce ou d'un
  attribut de gestionnaire — les deux fautes étant silencieuses à l'écriture.

### Modifié
- README : section Sécurité complétée, et onze affirmations périmées corrigées
  (plancher PHP, longueurs de mot de passe, durées de session, portée de
  l'anti-force-brute, dépendances embarquées, commande de test, mise à jour en un
  clic annoncée à tort comme inactive).

## [2.1.1] — 2026-08-19

### Corrigé
- **`vendor/` exigeait PHP ≥ 8.4.1** alors que le projet annonçait `>= 8.0` :
  résolu sur une machine en 8.5, Composer avait tiré `symfony/intl` et
  `symfony/validator` 8.1 ainsi qu'`endroid/qr-code` 6.1. Sans effet en
  développement ni en production (toutes deux en 8.5), mais le module
  facturation était condamné sur tout hébergement plus ancien.
  `config.platform.php` est désormais figé à `8.3.0`, ce qui force la
  résolution quelle que soit la machine. `sprain/swiss-qr-bill` et TCPDF sont
  inchangés.
- Intégration continue : le workflow était inanalysable (un `:` dans un
  scalaire YAML d'une seule ligne), donc GitHub le refusait avant de démarrer
  le moindre job — la CI livrée en 2.1.0 n'avait jamais tourné. Le détail d'un
  échec est maintenant remonté en annotations, lisibles sans jeton.

## [2.1.0] — 2026-08-18

### Sécurité
- **Fuite de données entre modules sur le tableau de bord** : ses widgets ne
  testaient que `module_actif()`, jamais `peut_lire()` — un compte n'ayant accès
  qu'à un module y voyait malgré tout les fiches de salaire à payer, les factures
  émises et les comptes annuels. Le rail de navigation, lui, vérifiait bien les
  deux. Introduction de `module_accessible()` comme point de vérité unique, et
  alignement de la route comme de la vue.
- Dossier `data/` : `.htaccess` de refus désormais versionné **et** recréé par
  `db()` s'il manque ; `FilesMatch` de la racine élargi aux fichiers sensibles.
- Détection de `APP_ENV` durcie (plus de bascule en `dev` sur un en-tête
  d'hôte falsifiable).
- Politique de sécurité de contenu resserrée ; suppression de la dépendance à
  Google Fonts (police Inter désormais servie depuis le dépôt, `assets/fonts/`)
  — plus aucune requête vers un tiers au chargement d'une page.
- Anti-force-brute étendu au couple e-mail + adresse IP ; `PASSWORD_BCRYPT`
  explicite avec réencodage à la connexion ; installation refusée en production
  sans `SETUP_SECRET` ; liste blanche sur les suppressions référentielles.

### Ajouté
- **Recherche unifiée** (`?p=recherche`, champ sur le tableau de bord) :
  traverse employés, structures, **contacts**, factures, événements et
  spectacles en une seule requête. Multi-mots dans n'importe quel ordre et
  insensible aux accents (« deborde » trouve « déborde »). Chaque source est
  filtrée par `module_accessible()` avant toute requête. Raccourci `/` pour
  atteindre le champ.
- Retour contextuel depuis une fiche vers la recherche d'origine, terme
  conservé (« Recherche « hector » »).
- Intégration continue (`.github/workflows/tests.yml`) et lanceur unique
  `php tests/run.php` : analyse syntaxique de tout le projet + toute la suite.
- Tests d'autorisation des routes, d'idempotence des migrations et de la
  recherche (dont un garde-fou sur le filtrage par droits).
- `docs/DECISIONS.md` : le « pourquoi » des choix structurants et des impasses
  déjà rencontrées.

### Modifié
- **Loupe cliquable sur tous les champs de recherche** : elle soumet le
  formulaire là où il y en a un, rend le focus au champ ailleurs. Le bouton
  « Rechercher » du tableau de bord disparaît, le champ prend toute la largeur.
  Rendu centralisé dans `champ_recherche()`.
- **Paramètres** quitte le rail de navigation pour rejoindre la pastille du
  compte, en bas de la barre latérale : ce n'est pas un module, il n'avait pas
  à en avoir le poids visuel.
- L'icône du tableau de bord porte la couleur principale de l'employeur au lieu
  du gris, via la nouvelle variable `--primary-base` (stable d'un module à
  l'autre, contrairement à `--primary`).
- Export iCal : titre « Artiste (Sous-spectacle) » pour les sous-spectacles.
- `lib/db.php` scindé (`lib/db/schema.php`, `lib/db/migrations.php`) ;
  `texte_sans_accents()` déplacée dans `lib/helpers.php` et exposée à SQLite.
- Images en data-URI mises en cache ; compression et cache long sur les assets ;
  diagnostic OPcache dans Paramètres.

### Ergonomie
- **Marge de pardon de 3 px autour des cases à cocher** : un clic qui tombe
  juste à côté coche quand même, au lieu d'ouvrir la fiche et de faire perdre
  la sélection en cours.
- **Sélection d'une liste conservée** le temps de la session d'onglet : ouvrir
  une fiche puis revenir ne fait plus tout décocher.

### Corrigé
- `tests/facturation_test.php` échouait sur un `pays_liste()` indéfini.

## [2.0.6] — 2026-08-17

### Modifié
- Export iCal : le titre d'un événement rattaché à un sous-spectacle porte
  désormais le nom du spectacle-groupe (l'artiste) suivi du sous-spectacle
  entre parenthèses — ex. « Hector ou rien (Tant qu'on déborde) » au lieu de
  « Tant qu'on déborde » seul. Un spectacle sans parent reste affiché seul,
  comme avant.
- Exports publics JSON/iCal : nouveau champ `spectacle_parent` (nom du
  spectacle-groupe) quand le spectacle de l'événement en a un.

## [2.0.5] — 2026-08-13

### Ajouté
- Formulaire de création de structure (?p=structure) : champs Statut,
  Période et Étiquettes, jusqu'ici seulement disponibles après coup en
  édition. Après création, redirection vers la fiche fraîchement créée
  (plus la liste).
- ?p=structures : petit « + » par ligne pour ajouter une étiquette sans
  ouvrir la fiche.
- Nom/titre d'une ligne de liste cliquable (?p=structures/evenements_liste/
  facturation_liste/fiches) : vrai lien — un clic droit dessus offre
  désormais « Ouvrir dans un nouvel onglet »/« Copier le lien ».

### Modifié
- Longueur minimale du mot de passe : 8 caractères (au lieu de 12).

### Corrigé
- Autocomplétion du champ étiquette (?p=structures, ?p=structure) peu ou
  pas utilisable en mobile (notamment Safari iOS) — remplacée par une liste
  de suggestions entièrement en JS.

## [2.0.4] — 2026-08-13

### Ajouté
- `?p=evenements_json` : CORS (`Access-Control-Allow-Origin: *`) et cache
  HTTP public d'une heure, pour permettre à un site externe de consommer ce
  flux directement via `fetch()` côté client (sans passer par un
  intermédiaire serveur).

### Corrigé
- `?p=evenements_json`/`evenements_ical` : un `spectacle_id` invalide (texte,
  0, négatif) était silencieusement traité comme « aucun filtre » — un
  appelant externe pouvait, par simple faute de frappe, récupérer la
  totalité des événements au lieu du sous-ensemble attendu, sans s'en
  rendre compte. Rejeté explicitement (code 400) désormais.

## [2.0.3] — 2026-08-13

### Modifié
- Tableau de bord : la carte Suisa devient un mini-tableau à 2 lignes (À
  faire/Manquants) avec le nombre et des liens Voir/Exporter par statut
  (Exporter en icône seule) ; la bulle de compteur de la section Événements
  (rail + onglet) reflète désormais les envois « à faire » plutôt que les
  décomptes manquants.

### Corrigé
- Les liens Voir/Exporter Suisa du tableau de bord utilisaient l'ancien
  format de filtre (valeur scalaire), silencieusement ignoré par le filtre à
  cases à cocher de la colonne SUISA (?p=evenements_liste) — la liste
  affichée n'était donc jamais filtrée. Corrigé pour les deux statuts.

## [2.0.2] — 2026-08-13

### Modifié
- Largeur par défaut du champ de recherche des barres d'outils réduite
  (200px → 110px), pour laisser plus de place aux boutons d'action juste à
  côté.
- Mode mobile : le libellé du bouton « Écriture manuelle »
  (?p=compta_ecritures) s'affiche désormais toujours sur deux lignes.

### Corrigé
- Mode mobile : trait gris persistant sous l'onglet actif sur certaines
  pages (Factures, Événements, Structures) — la correction précédente ne
  neutralisait que la couleur de la bordure du bandeau, pas sa largeur, qui
  laissait transparaître un filet flou derrière le fond translucide.
- Mode mobile : les boutons d'action des barres d'outils n'avaient pas
  toujours exactement la même hauteur que le champ de recherche voisin.
- Mode mobile : certaines cartes empilées (ex. ?p=evenement, ?p=structure)
  se retrouvaient collées les unes aux autres sans espace une fois la mise
  en page réduite à une seule colonne.

## [2.0.1] — 2026-08-13

### Corrigé
- Un utilisateur en lecture seule sur un module voyait quand même les
  boutons d'ajout/modification/suppression et les formulaires d'édition en
  ligne sur la quasi-totalité des pages : cliquer dessus était bien
  rejeté côté serveur (aucune donnée n'a jamais pu être modifiée), mais
  l'interface montrait à tort des actions inaccessibles. Chaque contrôle
  d'écriture est désormais masqué (ou remplacé par un message clair sur les
  pages entièrement dédiées à l'édition) selon les droits réels de
  l'utilisateur sur le module concerné.

## [2.0.0] — 2026-08-12

### Modifié
- **Nouvelle navigation** : le menu latéral vertical est remplacé par un rail
  d'icônes de modules (Salaires/Comptabilité/Factures/Événements/Booking),
  chacune ouvrant un bandeau d'onglets pleine largeur pour les pages du
  module (badges de compteur, couleur d'accent propre à chaque module).
  Paramètres et Mailing conservent un second niveau de sous-onglets sur le
  même principe.
- Comptabilité : Comptes bancaires et Plan comptable deviennent des onglets
  de premier niveau (au lieu de raccourcis dans Comptes annuels).
- Filtres de liste (fiches, structures, événements, écritures, factures,
  mailing) : les anciens formulaires de filtre sont remplacés par des
  panneaux de cases à cocher multi-sélection avec pastilles amovibles.
- Listes et tableau de bord en pleine largeur, fond blanc continu jusqu'en
  bas de page, pagination centrée, échelle typographique unifiée en 6
  tailles sémantiques, mise en page responsive du tableau de bord et des
  toolbars sur mobile.
- Fond d'écran par défaut calculé automatiquement (vagues dérivées des
  couleurs employeur), sur la connexion comme sur les pages connectées ;
  une image de fond personnalisée (?p=apparence) reste prioritaire si
  configurée.

### Corrigé
- Débordement horizontal de la pagination et régressions diverses de la
  toolbar unifiée (dropdowns, filtres perdant `?depuis=`, avertissement
  « Undefined array key » sur une fiche sans lignes personnalisées).
- `nav_groupe_actif()` : `compta_comptes`, partagé entre Comptabilité et
  Factures, met désormais en évidence Comptabilité par défaut (au lieu de
  Factures) quand aucun contexte de provenance n'est précisé.

## [1.37.0] — 2026-08-05

### Modifié
- Rafraîchissement visuel (CSS uniquement, `assets/app.css`) :
  - Chevron personnalisé sur tous les `<select>` du site (remplace la flèche native de l'OS, incohérente entre navigateurs) — SVG inline, sans bibliothèque externe.
  - Transition douce à l'ouverture d'une carte en édition (crayon cliqué) et sur le survol des lignes de tableau, au lieu d'une bascule instantanée.
  - Un peu plus d'air dans les tableaux de fiche (`.kv-table` — Informations générales, Historique, etc.) ; les tableaux de liste ne sont pas concernés (densité conservée).

## [1.36.3] — 2026-08-05

### Modifié
- `?p=structures` : tri alphabétique ignorant désormais un article initial (« le/la/les/l' », apostrophe droite ou courbe) — ex. « Le 35 » se classe à « 35 », « L'Alibi » à « Alibi ».

## [1.36.2] — 2026-08-05

### Ajouté
- `?p=structure` : le champ « Dernier contact » du tableau récapitulatif de la carte Historique est désormais éditable manuellement, en plus de « Connu via » (sera écrasé par la prochaine prise de contact enregistrée automatiquement — comportement voulu, simple rattrapage).

## [1.36.1] — 2026-08-05

### Ajouté
- `?p=structure` : le champ « Connu via » du tableau récapitulatif de la carte Historique est désormais éditable (crayon dédié), via une nouvelle route `?p=structure_via`.

### Corrigé
- La carte « Informations générales » ne portait plus le champ « Connu via » depuis la 1.34.0 (déplacé vers Historique) mais l'enregistrement de cette carte réinitialisait quand même `structures.via` à vide à chaque sauvegarde (champ absent du formulaire). Champ préservé via un champ caché.

## [1.36.0] — 2026-08-05

### Ajouté
- `?p=structures` : nouvelle colonne « Contact » listant les personnes de contact liées à chaque structure ; ces noms sont désormais aussi pris en compte par la recherche générale (liste et carte).

### Corrigé
- `.tiny` : une règle CSS orpheline du même nom (10.5px, plus utilisée nulle part) entrait en conflit avec la classe introduite en 1.33.2 (10px) et gagnait la cascade — les colonnes « Structures liées »/« Dernier contact » de `?p=structures` s'affichaient donc toujours à 10.5px. Règle orpheline supprimée.

## [1.35.0] — 2026-08-05

### Ajouté
- Tableau de bord : nouvelle section « Suisa » indiquant le nombre d'envois Suisa à faire (événements au statut SUISA « à faire »), avec un bouton pour voir la liste filtrée et un bouton d'export SUISA direct.

## [1.34.0] — 2026-08-05

### Modifié
- `?p=structure` : refonte de la carte « Historique », qui affiche désormais en tête un tableau récapitulatif (Connu via / Dernier contact / Dernière modification) — le champ « Connu via » (auparavant dans « Informations générales », son champ d'édition reste inchangé) et le nombre d'entrées affichées directement passe de 3 à 2 (avec « Voir les X précédentes » pour le reste).

## [1.33.2] — 2026-08-05

### Modifié
- `?p=structures` : les colonnes « Structures liées » et « Dernier contact » passent de `.small` (12.8px) à une nouvelle classe `.tiny` (10px), encore plus discrètes.

## [1.33.1] — 2026-08-01

### Modifié
- `?p=structures` : contenu plus petit pour les colonnes « Structures liées », « Factures liées » et « Dernier contact » ; titres plus petits pour « Statut », « Structures liées » et « Dernier contact » ; ajout d'un `title`/`aria-label` sur les icônes « Factures liées » et « Événements liés » (colonnes sans libellé visible jusqu'ici).

## [1.33.0] — 2026-08-01

### Retiré
- `structures.type` (`organisation`/`particulier`) : jamais utilisé en pratique, la catégorie suffit. Retiré de la fiche structure (`?p=structure`), du bulk-edit (`?p=structures`), des formulaires de création rapide de structure (facture, carte Organisation d'un événement) et de l'export CSV SUISA (colonne « Organisateur — Type »). Migration 67.

### Corrigé
- Import de factures historiques (`importer_factures_historique()`) : la création automatique d'une structure manquante référençait encore la colonne `structures.actif`, supprimée par une migration précédente (unification du statut) — aurait provoqué une erreur SQL fatale à la prochaine utilisation.

## [1.32.2] — 2026-08-01

### Modifié
- `?p=dev` → Incohérences → « Correspondances trouvées » : la date et la structure proposée sont désormais des liens (vers l'événement et la fiche structure) ; colonne renommée « Organisateur proposé » (plus de distinction lieu/organisateur).

## [1.32.1] — 2026-08-01

### Corrigé
- Fusion de structures (`?p=structures` → « Fusionner », et l'outil « Doublons exacts » de `?p=dev`) : les factures étaient déjà réaffectées à la structure conservée, mais pas les événements liés (`evenement_structures`) — ils restaient à tort attachés à la structure supprimée. Le marquage « à facturer » (référence facture/SUISA) est préservé en cas de conflit (événement déjà lié aux deux structures fusionnées). Le nombre d'événements liés est aussi affiché dans l'écran de choix de la structure à conserver.

## [1.32.0] — 2026-08-01

### Modifié
- `?p=evenement`, carte « Organisation » : « Lieu(x) » et « Organisateur(s) » sont fusionnés en une seule liste de structures liées, sans sous-titre — plus de distinction de rôle entre les deux. Une structure peut être marquée « à facturer » (icône étoile, une seule à la fois) : c'est elle qui sert de référence au pré-remplissage facture et à l'export CSV SUISA (comportement inchangé pour ces deux fonctionnalités).
- Migration 66 : fusionne les tables `evenement_lieux`/`evenement_organisateurs` en une seule `evenement_structures` (avec la marque « à facturer ») ; `evenements.lieu_id` est retiré (`organisateur_structure_id` est conservé, désormais dérivé de la structure marquée « à facturer »).

## [1.31.0] — 2026-08-01

### Modifié
- `?p=structure` : réorganisation des colonnes pour hiérarchiser l'essentiel — colonne 1 : Statut puis Informations générales (le champ le plus consulté passe avant les cartes de relations/activité) ; colonne 2 : Structures liées, Événements, Contacts ; colonne 3 inchangée (Localisation, Historique).
- La carte « Période » est fusionnée dans « Informations générales », juste avant Remarques : les lignes Réalisation et Préparation affichent chacune indépendamment « Toute l'année » quand ses propres mois ne sont pas renseignés (au lieu d'un message global valable seulement si les 4 champs étaient vides).

### Retiré
- Route `?p=structure_periode` et `route_structure_periode()` : la sauvegarde de la période est désormais intégrée à `route_structure()`, dans le même formulaire que le reste des informations générales.

## [1.30.3] — 2026-08-01

### Corrigé
- `?p=structures` : la colonne « Événements » ne comptait que les événements directement rattachés à la structure, pas ceux de ses structures liées qu'elle organise (structure_organisateurs, sens « organise ») — un organisateur dont les événements sont portés par ses salles/festivals liés apparaissait à tort à 0.

## [1.30.2] — 2026-08-01

### Corrigé
- `?p=evenement`, carte « Organisation » : le sélecteur de lieu ne réagissait à rien (focus/frappe/clic) tant que sa requête `?p=lieux_options` n'avait pas abouti en arrière-plan, obligeant à refocaliser le champ 2-3 fois avant que l'ajout ne fonctionne — le widget de recherche (`lassoInitCatSearch()`) est désormais actif dès le premier focus, la liste se remplissant ensuite dessous.
- `?p=evenement`, carte « Organisation » : le sélecteur d'organisateur excluait les structures au statut « inactif », qui restent pourtant des organisateurs valables pour un événement déjà survenu.

## [1.30.1] — 2026-08-01

### Ajouté
- Fiche structure, carte « Informations générales » : jauge min/max éditables (déjà utilisées comme critères de filtre, mais jamais saisissables depuis la fiche jusqu'ici).

## [1.30.0] — 2026-08-01

### Retiré
- `?p=structures`, action groupée « Transformer en salle/festival d'un organisateur » (et toute la fonctionnalité associée : route, page, `structure_transformer_en_lieu()`).

## [1.29.3] — 2026-08-01

### Corrigé
- Vues carte : régression de la 1.29.2 — les villes déjà en cache sous leur ancienne clé accentuée (ex. « Genève », « Neuchâtel ») n'étaient plus retrouvées après le repli des accents dans `geocodage_cle()` et disparaissaient de la carte au lieu de fusionner avec leur doublon. Migration 65 : reclé les lignes déjà en cache avec la nouvelle formule.

### Modifié
- Vues carte : pastilles en couleur de mise en évidence (bleu) au lieu de la couleur neutre.

## [1.29.2] — 2026-08-01

### Modifié
- Vues carte (`?p=structures`/`?p=evenements&vue=carte`) : pastilles toutes de la même couleur, sans nombre affiché, différence de taille resserrée (14-22 px) entre un point simple et un point regroupant plusieurs fiches.

### Corrigé
- Géocodage (`lieux_geocodage`) : une même ville saisie avec ou sans accent sur des fiches différentes (« Chambéry »/« Chambery », « Mâcon »/« Macon »…) était géocodée séparément et affichait deux repères sur la vue carte au lieu d'un seul — la clé de cache replie désormais les accents (`texte_sans_accents()`). Filet de sécurité complémentaire dans `carte_points_grouper()` : deux points qui convergent malgré tout vers les mêmes coordonnées (ex. canton renseigné sur une fiche, vide sur une autre pour la même ville) sont fusionnés à l'affichage.

## [1.29.1] — 2026-08-01

### Modifié
- Vues carte (`?p=structures`/`?p=evenements&vue=carte`) : les points regroupant plusieurs fiches (même ville) s'affichent désormais en pastille plus grande, en couleur d'accent, avec le nombre de fiches — au lieu d'un simple repère identique quel que soit le nombre d'éléments.

## [1.29.0] — 2026-08-01

### Ajouté
- `?p=structures` : nouveau filtre « Avec événements liés » (Plus de filtres).

### Corrigé
- `?p=dev`, Incohérences → « Événements sans lieu rattaché » : la détection de correspondances ne considérait que les structures dont la sous-catégorie est marquée « booking », comme la recherche de lieu de la fiche événement (voir 1.28.1) — même correction, toutes les structures sont désormais candidates.

## [1.28.1] — 2026-08-01

### Corrigé
- Fiche événement, recherche de lieu (création et carte « Organisation ») : ne retrouvait que les structures dont la sous-catégorie est marquée « booking », excluant des lieux réels catégorisés autrement (ex. structure classée « Organisateur »). Recherche désormais non filtrée sur toutes les structures, comme pour la recherche d'organisateur.

## [1.28.0] — 2026-08-01

### Modifié
- Fiche structure, carte « Historique » : n'affiche plus que les 3 dernières entrées par défaut, avec un lien « Voir les … précédentes » pour dérouler le reste.
- Fiche structure, carte « Événements » : affiche le nom du spectacle-groupe en priorité (avec la feuille en petit dessous si distincte) plutôt que systématiquement la feuille ; retrait de la ligne de localisation de l'événement (redondante avec la page de la structure).

## [1.27.0] — 2026-08-01

### Retiré
- Distinction « catégorie organisateur » (`structure_categories.est_organisateur`, case à cocher de Paramètres → Catégories) et l'auto-groupement organisateur↔lieu qu'elle pilotait à l'import CSV (détection « Festival X (Asso Y) », étape « Regrouper » de l'assistant d'import) : chaque ligne s'importe désormais comme une structure indépendante, le rattachement se fait ensuite à la main sur la fiche (carte « Structures liées »).
- Colonne d'import CSV « Organisateur » (asso mère), devenue sans effet.

### Modifié
- `?p=structure`, carte « Structures liées » : les deux rôles « Organise » / « Organisée par » restent distincts, mais leurs deux champs de recherche affichent désormais exactement les mêmes structures (plus de filtre différent entre les deux).

## [1.26.0] — 2026-08-01

### Ajouté
- `?p=structure` : nouvelle carte « Période » sous « Informations générales » (module booking) — case « Toute l'année » par défaut, ou mois de début/fin de réalisation et de préparation. Aucune colonne dédiée pour la case : son état est déduit des 4 champs de mois (tous vides = toute l'année).

## [1.25.2] — 2026-08-01

### Corrigé
- **Urgent** : `migration_63` (statut de structure unifié, v1.25.0) plantait l'application entière sur les hébergements dont le SQLite lié à PHP est antérieur à 3.35 (`ALTER TABLE ... DROP COLUMN` non supporté — « near "DROP": syntax error », constaté en prod). La suppression des colonnes `actif`/`desinscrit` (déjà remplacées par `statut`, backfill non affecté) est maintenant best-effort : si indisponible, ces colonnes restent en base, inertes, sans bloquer le démarrage.

## [1.25.1] — 2026-08-01

### Corrigé
- Icônes seules (colonne Statut de `?p=structures`, en-têtes Factures/Événements…) : alignement vertical légèrement décalé vers le haut (icône SVG en `vertical-align: baseline` par défaut) — corrigé en `middle`.
- Badge « désinscrit » d'un contact (fiche structure) : majuscule (« Désinscrit »).

## [1.25.0] — 2026-08-01

### Ajouté
- Statut de structure unifié (« Contact privilégié », « Actif », « Ne pas contacter », « Inactif ») remplaçant les deux cases à cocher séparées « Active » et « Désinscrite du mailing ». Nouveau bouton « Contact privilégié » (cœur rose) dans le sélecteur de statut de `?p=structure`, en premier.
- `?p=structures` : colonne « Statut » (icône seule) en première position, filtre « Contacts privilégiés », action groupée « Modifier le statut ».

### Modifié
- Migration des données existantes : Active + non désinscrite → Actif ; Active + désinscrite → Ne pas contacter ; non active → Inactif (aucune fiche existante n'obtient « Contact privilégié » automatiquement).
- Le badge « Désinscrit » d'un contact (fiche structure) s'écrit désormais avec une majuscule.

## [1.24.8] — 2026-08-01

### Ajouté
- `?p=parametres_structures` : nombre de structures par catégorie/sous-catégorie, avec lien vers la liste filtrée correspondante.

### Modifié
- `?p=structures` : les colonnes « Lieux » et « Structures liées » sont fusionnées en une seule colonne « Structures liées », chaque entrée avec une icône selon le sens de la relation et un lien vers la fiche.

## [1.24.7] — 2026-08-01

### Ajouté
- `?p=structures` : statut « Ne pas contacter » (active mais désinscrite du mailing) dans le filtre Statut.
- `?p=evenements_liste&vue=carte` : lien « Voir la liste » vers les événements pas encore localisés (comme structures/lieux).

### Retiré
- `?p=structures` : filtre « Type de lieu lié » (« Plus de filtres »).

## [1.24.6] — 2026-08-01

### Ajouté
- `?p=structures` : nouvelle colonne « Structures liées » (organisateur(s) de la structure, sens inverse de la colonne « Lieux »).

### Modifié
- `?p=structures` : l'indicateur « désinscrit » (mailing) est déplacé de la colonne Nom vers la colonne Tags, avec une icône enveloppe barrée.

## [1.24.5] — 2026-08-01

### Corrigé
- Import CSV (carnet d'adresses, fiches, écritures, événements) : un fichier exporté en Windows-1252 (« CSV » sous Windows sans choix explicite d'encodage — cas fréquent) cassait silencieusement tout caractère accentué, en-têtes de colonnes comme valeurs (mb_strtolower()/json_encode() sur de l'UTF-8 invalide). Le contenu est désormais normalisé en UTF-8 à la lecture (`normaliser_encodage_utf8()`, `lire_fichier_importe()`, lib/helpers.php), avec repli sur Windows-1252 quand ce n'est pas déjà de l'UTF-8 valide.

## [1.24.4] — 2026-07-31

### Corrigé
- Padding du bas des cartes visuellement plus grand que celui du haut : les lignes répétées (`.linked-add`, `.tags-liste`, `.contact-row`) espaçaient via un `margin-bottom` qui s'ajoutait au padding de la carte quand la ligne se trouvait être la dernière — y compris quand seul un formulaire masqué (`.edit-only`, `[hidden]`) suivait, cas où un simple `:last-child` CSS ne fonctionne pas (il cible cet élément caché dans le DOM, pas la dernière ligne visible). La marge migre désormais vers le haut de l'élément suivant (sans effet sur un élément `display:none`), et un `<p>` terminant une carte (« Aucun contact. », etc.) ne porte plus sa marge par défaut du navigateur.

## [1.24.3] — 2026-07-31

### Corrigé
- Carte « Statut » (fiche structure) : les jonctions entre les 3 boutons du sélecteur segmenté n'étaient pas des lignes droites (chaque `<button>` hérite d'un `border-radius` individuel du style de bouton par défaut du site, créant une légère courbure interne à chaque coin — invisible avec le `<label>` utilisé par les autres sélecteurs segmentés). `.seg-btn` réinitialise désormais aussi `border-radius: 0`, seul le conteneur `.seg-picker` arrondissant la silhouette globale.

## [1.24.2] — 2026-07-31

### Modifié
- Carte « Statut » (fiche structure) : le champ « Ajouter une étiquette » ne s'affiche plus en permanence, mais derrière un petit « + » à la suite des étiquettes existantes (bouton `.badge`, réutilise le délégué `data-show`/`data-hide` déjà en place).

## [1.24.1] — 2026-07-31

### Ajouté
- Fiche structure, carte « Informations générales » : le champ Remarques, s'il dépasse 200 caractères, est tronqué avec un lien « voir tout » qui affiche le texte complet en un clic (sans recharger la page). Mécanisme générique (`.voir-tout-btn`, `assets/app.js`), réutilisable pour d'autres champs longs.

## [1.24.0] — 2026-07-31

### Ajouté
- ?p=structures : les badges d'étiquette (colonne Tags) reprennent la couleur choisie dans ?p=parametres_tags. La recherche texte (champ de la liste et de la vue carte) porte désormais aussi sur les étiquettes.

### Corrigé
- `?p=structures&non_localises=1` réaffichait à tort des fiches déjà géolocalisées avec succès : le `LOWER()` intégré de SQLite ne repasse en minuscule que l'ASCII (« GENÈVE » restait « genÈve »), alors que la clé de cache (`lieux_geocodage.cle`) est construite en PHP via `mb_strtolower()` (Unicode complet) — toute ville saisie avec une majuscule accentuée ne matchait donc jamais son entrée de cache. Nouvelle fonction SQLite `LOWER_UTF8()` (enregistrée dans `db()`), utilisée par `geocodage_non_localises_where()`/`geocodage_villes_manquantes()`.
- Renommer un pays (?p=parametres_pays) rendait orphelines les entrées de cache de géocodage des structures concernées (le pays fait partie de la clé) : ajout de la propagation vers `lieux_geocodage.cle`.
- Carte « Statut » (fiche structure) : bordures et ombre bleues parasites sur les 3 boutons du sélecteur segmenté (le bouton `<button>` hérite du style de bouton par défaut du site, contrairement au `<label>` utilisé par les autres sélecteurs segmentés) — `.seg-btn` réinitialise désormais explicitement `border`/`box-shadow`.

## [1.23.1] — 2026-07-31

### Modifié
- Fiche structure, carte « Statut » : le bouton à bascule cyclé devient un sélecteur segmenté horizontal (3 icônes, même style que le champ Type de « Informations générales ») — on clique directement l'état voulu au lieu de cycler. `route_structure_statut()` reçoit désormais l'état ciblé (`etat`) plutôt que de calculer le suivant.
- ?p=parametres_tags : le crayon révèle maintenant les champs éditables (nom + couleur) et les actions Enregistrer (mis en évidence, en grand), Supprimer (petite poubelle rouge) et Annuler (croix, restaure les valeurs d'origine) — au lieu d'un formulaire toujours visible.

## [1.23.0] — 2026-07-31

### Modifié
- Fiche structure, carte « Statut » : les cases « Structure active »/« Désinscrite du mailing » sont remplacées par un bouton à bascule (icône) en haut à droite du titre, cyclant Actif (vert) → Ne pas contacter (rouge, active mais désinscrite) → Inactive (gris, toujours désinscrite) → Actif. AJAX (comme le marquage rapide étoile/cœur), sans rechargement de page. Nouveaux helpers `structure_statut_toggle_html()` (lib/helpers.php) et `lassoInitStatutToggle()` (assets/app.js).

## [1.22.0] — 2026-07-31

### Ajouté
- Couleur d'étiquette (?p=parametres_tags) : chaque étiquette de structure peut désormais avoir sa propre couleur (sélecteur natif), avec aperçu du badge dans la liste. La couleur choisie est reprise sur les badges d'étiquettes de la fiche structure (`structure_tags.couleur`, migration_62).

## [1.21.7] — 2026-07-31

### Corrigé
- Fiche structure (?p=structure), carte « Informations générales » : enregistrer renvoyait vers la liste des structures au lieu de rester sur la fiche (`redirect('structures')` inconditionnel après une modification).

### Modifié
- Renommage du titre (fiche structure) : le bouton Enregistrer utilise désormais la couleur de mise en évidence, comme les autres boutons Enregistrer de la fiche (il était resté en style « ghost »).

## [1.21.6] — 2026-07-31

### Modifié
- Fiche structure (?p=structure) : le titre de l'onglet affiche le nom de la structure au lieu de « Modifier la structure ».

## [1.21.5] — 2026-07-31

### Modifié
- Carte « Événements » de la fiche structure : chaque ligne devient une puce de date façon calendrier (jour + mois abrégé, année en dessous), avec le spectacle et, en dessous, la ville et la structure liée (icône blocs/bâtiment). Nouvel helper `mois_abrege()` (`lib/helpers.php`).

## [1.21.4] — 2026-07-31

### Modifié
- Carte « Événements » de la fiche structure : inclut désormais aussi les événements des structures liées (organise/organisée par), avec le nom de la structure et une petite icône (blocs/bâtiment) indiquant le sens. Un événement lié à la fois à la structure et à une (ou plusieurs) structure(s) liée(s) n'apparaît qu'une seule fois.

## [1.21.3] — 2026-07-31

### Modifié
- Carte Contacts (fiche structure) : « Aucun contact. » ne s'affiche plus à tort quand seuls des contacts de structures liées existent ; le nom de la structure liée porte désormais une petite icône (blocs/bâtiment, même convention que « Structures liées ») indiquant le sens du lien.



### Corrigé
- `?p=dev` plantait (« no such table: lieux ») : la détection des grandes régions (`GRANDE_REGION_TABLES`) référençait encore la table `lieux`, supprimée en v1.21.0 (fusion lieux→structures). Nettoyage complémentaire d'une valeur par défaut similaire dans `geocodage_villes_manquantes()`.

### Modifié
- Fiche structure (?p=structure), carte « Structures liées » : en lecture, les libellés « Organise »/« Organisée par » disparaissent au profit d'une icône devant chaque nom (blocs pour « organise », bâtiment pour « organisé par ») ; le message « Aucun(e) … lié(e) » ne s'affiche plus quand l'autre sens a au moins un lien.
- La carte « Événements » de la fiche structure devient une carte séparée (elle était auparavant fondue dans « Structures liées ») ; icône et puces retirées, texte en taille standard.
- Carte « Contacts » de la fiche structure : affiche désormais aussi les contacts des structures liées (organise/organisée par), à la suite des contacts propres, avec lien vers leur fiche.

## [1.21.1] — 2026-07-31

### Modifié
- Menu latéral simplifié : « Factures » rejoint la section Comptabilité, « Structures » rejoint la section Événements (entre Événements et Spectacles). Les sections dédiées « Facturation » et « Booking » disparaissent (leur seul contenu restant, Mailing, est déjà masqué).

## [1.21.0] — 2026-07-30

### Modifié
- **Fusion des lieux dans les structures** : le module booking ne distingue plus « lieu » et « structure » comme deux tables séparées — un lieu était, dans l'immense majorité des cas, une pure duplication d'une structure déjà existante (même nom, même ville). Une structure peut désormais être marquée comme un type de lieu bookable (`structure_categories.est_booking` : Salle, Festival, Théâtre, MJC, Médiathèque, SMAC, Café-concert, Saison culturelle, etc.) directement via sa sous-catégorie, sans fiche dupliquée.
- Le lien « est organisé par » devient un lien many-to-many **structure à structure** (`structure_organisateurs`), remplaçant l'ancien lien lieu→structure. Fiche structure (?p=structure) : la carte « Lieux liés » devient **« Structures liées »** et fonctionne dans les deux sens (« organise » / « est organisée par »), chaque sens avec sa propre recherche et création à la volée.
- Le champ « dernier concert (ou diffusion) » (`dernier_concert_le`), déjà existant, est maintenant recalculé automatiquement à partir des événements liés à une structure (création, modification de date), en plus de la saisie manuelle.
- Import CSV, doublons, Dev (rattachement automatique événements↔lieux, mise à jour des dates) : adaptés au nouveau modèle.

### Supprimé
- Tables `lieux`, `structure_lieux`, `lieu_categories` (migrations 59 à 61 — les données ont été fusionnées dans `structures` au préalable ; sauvegarde automatique de la base avant suppression). Menu « Lieux », page Paramètres > Lieux (catégories).

## [1.20.1] — 2026-07-30

### Modifié
- Fiche structure (?p=structure), carte Contacts : les badges (administration/booking/désinscrit) s'affichent désormais juste après le nom du contact plutôt qu'en fin de ligne.
- Fiche structure, carte « Lieux liés » : fond légèrement teinté (couleur de mise en évidence), transparent et flouté, pour la distinguer visuellement des autres cartes (`.card-lieux-liees`, `assets/app.css`).

## [1.20.0] — 2026-07-30

### Ajouté
- **Fiche structure (?p=structure) réorganisée en 3 colonnes de cartes indépendantes** : colonne 1 (Lieux liés puis Informations générales), colonne 2 (Statut puis Contacts), colonne 3 (Localisation puis Historique) — chaque colonne a sa propre hauteur, sans forcer l'alignement des cartes en ligne (`.card-columns`/`.card-col`, `assets/app.css`).
- **Carte « Localisation » dédiée sur la fiche structure** (adresse postale complète : rue, NPA, localité, département/canton, région, pays), même apparence que la carte « Localisation » de la fiche événement — nouvelle route `route_structure_localisation()`. Les Coordonnées ne sont plus enregistrées par `route_structure()` que si le module booking est inactif ou inaccessible en lecture (auquel cas la fiche garde un seul cadre, sans carte séparée).
- Icône crayon → croix (annuler) pendant l'édition des sections « Lieux liés » et « Contacts » ; icône dédiée (unlink) pour délier un lieu, plutôt qu'une croix générique ; icône (link) sur le bouton « Lier ».

### Corrigé
- **Mini-carte de localisation invisible une fois une ville géocodée** (`?p=structure`/`?p=evenement`, carte « Localisation ») : la carte Leaflet, positionnée en absolu, ne donnait aucune hauteur à son cadre parent — elle restait présente dans le DOM mais à hauteur nulle. Hauteur minimale ajoutée sur `.card.card-flush` dès qu'une mini-carte y est présente.

## [1.19.1] — 2026-07-30

### Ajouté
- **Carte lecture/édition sur la fiche structure** (?p=structure) : Catégorie, Type, Connu via, Coordonnées, Site web et Remarques s'affichent désormais en lecture par défaut, avec un bouton crayon (→ enregistrer/annuler) pour les modifier — même mécanique que les cadres de la fiche événement. Cette mécanique (`.card-editable`/`.card-disp`/`.card-edit`) est désormais partagée dans `assets/app.js` plutôt que dupliquée par page.

## [1.19.0] — 2026-07-30

### Ajouté
- **Effets « clair » et « flouté » pour l'image de fond** (Paramètres → Apparence, cases à cocher combinables) : adoucit/éclaircit et/ou floute l'image pour préserver la lisibilité du contenu par-dessus. Implémenté via un `::before` dédié (jamais un filter/backdrop-filter sur `<body>` lui-même — cassait déjà les éléments `position:fixed` par le passé, voir commentaire historique dans app.css).
- **Bouton « Supprimer l'image de fond »** (Paramètres → Apparence) : revient au fond par défaut de l'application ; n'apparaît que si une image personnalisée est active. Route dédiée (`route_apparence_fond_supprimer()`), n'affecte ni les couleurs ni les effets clair/flouté.

## [1.18.2] — 2026-07-30

### Modifié
- **Bandeau d'en-tête (`.page-head-band`)** : fond blanc plein remplacé par le même fond translucide flouté que le bandeau du mode carte (`.blur-glass`), sur toutes les pages qui l'utilisent (Fiches de salaire, Écritures, Factures, Paramètres…) — laisse transparaître l'image de fond de l'application (Apparence) au lieu de la masquer complètement.

## [1.18.1] — 2026-07-30

### Corrigé
- **Image de fond personnalisée invisible sur les pages de l'application** (n'apparaissait que dans l'aperçu de Paramètres → Apparence) : le chemin était résolu par rapport à `assets/` (`assets/uploads/…`, inexistant) au lieu de la racine du site. En cause : une URL relative portée par une variable CSS (`--fond-url`) se résout par rapport à la feuille de style où elle est *utilisée* (`assets/app.css`), pas où elle est déclarée. Remplacé par une règle `body.has-sidebar{background-image:…}` posée directement dans le `<style>` inline de la page (`couleurs_css_vars()`), où les chemins relatifs se résolvent normalement par rapport à la page — comme partout ailleurs (logos, etc.).

## [1.18.0] — 2026-07-30

### Ajouté
- **Choix de l'image de fond** (Paramètres → Apparence) : une image personnalisée (PNG/JPG/GIF/WebP, 2 Mo max) peut désormais remplacer le fond `assets/fond.jpg` par défaut, sur toutes les pages de l'application hors connexion. Même traitement que les logos employeur (upload validé par `getimagesize()`, stocké dans `uploads/`, ancien fichier supprimé au remplacement) ; `param_fond()` (lib/helpers.php) et `--fond-url` (variable CSS injectée par `couleurs_css_vars()`) fournissent le chemin actif partout où il est utilisé.

## [1.17.5] — 2026-07-30

### Ajouté
- **Carte « Informations » teintée selon le statut** (?p=evenement) : fond très légèrement teinté (vert pour confirmé, gris pour annulé, ambre pour option — mêmes couleurs que l'icône de statut), à un pourcentage assez faible pour rester discret sur toute la carte. Même correctif de spécificité CSS que `.card-flush` (sélecteur à deux classes, sinon écrasé par `.card`).

## [1.17.4] — 2026-07-30

### Modifié
- **Carte « Localisation » (?p=evenement)** : l'en-tête flottant (ville/canton/région + crayon) a désormais le même padding (26px) que les autres cadres de la page, au lieu d'un padding plus serré — la réserve de hauteur pour le formulaire d'édition et le message « ville non géocodée » est ajustée en conséquence.

## [1.17.3] — 2026-07-30

### Modifié
- **Fiche événement (édition), derniers réglages d'affichage** : la date (carte Informations) et le nom de ville (carte Localisation) reprennent le style du titre de page (dégradé de couleur, gras, 32px) plutôt qu'une taille ad hoc. Édition de la carte Informations : un champ par ligne au lieu de plusieurs colonnes. Statut et audience affichent désormais leur texte à côté de l'icône (plus icône seule). Carte SUISA : la case « s'applique » est en haut à droite du cadre (plus dans le formulaire) ; « Envoyée à » et « Date d'envoi » sur une ligne, « Date du décompte » sur la suivante.

### Modifié
- **Fiche événement (édition), suite des finitions** : boutons raccourcis en un mot (« Créer », « Ajouter », « Lier » — ce dernier avec une icône de lien) ; carte SUISA renommée en « SUISA » et sa case à cocher en « s'applique ». Carte « Informations » sans titre de cadre : la date (grande, en gras, colorée) et le spectacle (gras) en tiennent lieu, suivis d'un tableau Statut/Audience/Salle à afficher/Festival à afficher/Lien/Remarques ; le crayon/enregistrer/annuler flottent désormais en haut à droite de la carte (superposés) plutôt que dans une ligne d'en-tête dédiée, pour que la date démarre tout en haut sans vide au-dessus. La carte « Localisation » est désormais juste après « Informations » (avant « Organisation ») ; le nom de la ville y est mis en valeur (même taille que la date) et son en-tête flottant colle enfin aux bords de la carte (correctif : `.card-flush` était écrasé par le `padding: 26px` de `.card`, même spécificité CSS, faute d'un sélecteur à deux classes). En édition de la localisation, ville et département/canton sur une ligne, région et pays sur la suivante. Le titre de page (« date : spectacle, ville ») est retiré (redondant avec la carte Informations) ; le bouton Supprimer rejoint le lien de retour sur la même ligne.

## [1.17.1] — 2026-07-30

### Modifié
- **Fiche événement (édition), finitions** : les boutons « enregistrer »/« annuler » remplacent désormais le crayon au même endroit (en haut à droite du cadre), en icônes seules (disquette/croix). La carte « Localisation » n'a plus de titre ni de marge interne : la carte géographique occupe tout le cadre, surmontée d'un en-tête flottant translucide flouté (ville, drapeau, canton, région — même style que le bandeau du mode carte, désormais factorisé dans `.blur-glass`). Dans la carte « Informations », « Type d'audience » devient « Audience » et son statut « Public »/« Privé »/« Non répertorié » comme le statut de l'événement (« Confirmé »/« Annulé »/« Option ») s'affichent en icône seule (statut coloré : vert/gris/ambre).

## [1.17.0] — 2026-07-30

### Ajouté
- **Refonte de la fiche événement (édition)** : titre remplacé par « date : spectacle, ville » ; les informations (date, spectacle, statut, type d'audience, salle/festival à afficher, lien, remarques), l'organisation (lieux et organisateurs) et la localisation (ville, département/canton, région, pays + carte) sont désormais regroupées dans 3 cadres en lecture seule par défaut (crayon → édition → enregistrer/annuler), affichés côte à côte sur une grille de 3 (comme le second rang suivi SUISA / comptabilité analytique / factures liées) ; les cadres Employés et le reste restent inchangés.
- **Lieux et organisateurs multiples par événement** : un événement peut désormais être rattaché à plusieurs lieux et plusieurs organisateurs (cadre « Organisation », puces ajoutables/retirables, un seul enregistrement groupé) — auparavant limité à un seul de chaque. Les anciennes colonnes `lieu_id`/`organisateur_structure_id` sont conservées en miroir du premier lien (aucun autre écran à adapter : fiche lieu/structure, pré-remplissage facture, export SUISA continuent de fonctionner tels quels).

## [1.16.1] — 2026-07-30

### Corrigé
- **Numéros de fiche non cliquables** dans les tableaux « Doublons exacts » et « Doublons de lieux soupçonnés » (onglet Incohérences) : les `#id` (conservée/supprimée(s)) sont désormais des liens vers la fiche structure/lieu correspondante.

## [1.16.0] — 2026-07-30

### Ajouté
- **Résumé en tête de l'onglet Incohérences** : nombre d'éléments détectés par outil (doublons exacts, doublons de lieux soupçonnés, grandes régions à déduire, événements sans lieu), chacun avec un lien direct vers sa section.
- **Doublons de lieux soupçonnés** (nouvel outil) : repère les lieux d'une même structure partageant le même nom et la même ville mais classés sous un type différent (ex. « Salle » vs « Association ») — souvent la même entité mal classée deux fois. Distinct des doublons exacts (qui exigent un type identique), avec sa propre sélection par case à cocher et fusion (réutilise `doublons_fusionner_lieux()`).

## [1.15.2] — 2026-07-30

### Corrigé
- **Recherche perdue au retour** (lieux, structures, événements, factures) : la recherche texte et la page de pagination n'étaient jamais mémorisées (par choix, comme pour tout filtre éphémère), donc perdues en cliquant sur une fiche puis « Retour » — contrairement aux autres filtres (type, ville, statut…), repris automatiquement via la session. Le lien vers chaque fiche transporte désormais la recherche et la page actives (nouvelle fonction `suffixe_retour_liste()`), restituées par le lien de retour contextuel.

## [1.15.1] — 2026-07-30

### Ajouté
- **Sélecteur unifié sur la page Exporter** : un menu déroulant « Type de données à exporter » (même esprit que celui déjà présent sur la page Importer) remplace l'empilement de cartes — une seule carte, le contenu du type choisi s'affiche en dessous, le champ Année mutualisé à droite du sélecteur de type. Nouvel export « Événements — CSV (SUISA + organisateur) », qui réutilise l'export déjà disponible depuis la liste des événements, toujours sans filtre (hormis l'année).

## [1.15.0] — 2026-07-30

### Ajouté
- **Rattacher les événements à un lieu** (Paramètres → Données → Incohérences) : nouvel outil de rattrapage qui rapproche chaque événement sans lieu rattaché avec un lieu existant (même ville/département/canton/pays, nom normalisé), propose de créer une structure+lieu quand rien ne correspond (une seule paire par salle même si plusieurs événements la partagent), et n'applique jamais une correspondance ambiguë (plusieurs lieux candidats) — ces cas restent listés avec un lien direct vers la fiche événement à traiter à la main.
- **Cases à cocher sur tout l'onglet Incohérences** : les 5 outils (doublons, dates CSV, grandes régions, rattachement lieu, création structure+lieu) n'appliquent désormais le changement qu'aux lignes cochées, plus jamais à tout ce qui a été détecté par défaut.
- **Regroupement « Données »** : les onglets Importer/Exporter/Incohérences (ex-« Dev ») sont désormais réunis sous un seul onglet Paramètres → Données, avec ces 3 sections en sous-onglets.

## [1.14.0] — 2026-07-29

### Corrigé
- **Géolocalisation des homonymes** (vue carte lieux/structures/événements) : le cache de géocodage n'était indexé que par (ville, pays), donc deux villes de même nom dans des départements différents (ex. plusieurs « Bonneville » en France) partageaient la même entrée — la première interrogée l'emportait pour toutes, plaçant certains lieux au mauvais endroit sur la carte. Le département/canton fait désormais partie de la requête envoyée à Nominatim et de la clé de cache. Vérifié en conditions réelles : sans département, « Bonneville, France » renvoie un hameau de la Somme ; avec le département (« Bonneville, Haute-Savoie, France »), la bonne ville est renvoyée.
- **Conséquence attendue** : le format de clé change, donc le cache existant est vidé par la migration (aucune perte de données sur les lieux/structures/événements eux-mêmes, uniquement le cache de coordonnées) — les cartes redemanderont un géocodage au fil des visites.

## [1.13.2] — 2026-07-29

### Ajouté
- **Liens de copie JSON/iCal sur la page Spectacles** : mêmes boutons de copie (déjà présents sur chaque spectacle) désormais aussi en en-tête de page, pour l'export/synchronisation de la liste complète des événements (tous spectacles confondus).

### Modifié
- **Bandeau de la vue carte** (lieux/structures/événements) : opacité du fond réduite (`.85` → `.50`) pour laisser plus voir la carte sous le bandeau.

## [1.13.1] — 2026-07-29

### Corrigé
- **Recherche inopérante en vue carte** (lieux, structures, événements) : le champ de recherche était câblé sur `lassoListeClient()` (filtrage JS d'un tableau) dès que `modeClient` était vrai — toujours le cas en vue carte, qui ne contient pourtant aucun tableau à filtrer, donc `lassoListeClient()` ne faisait rien silencieusement. La vue carte utilise désormais toujours `lassoRechercheServeur()` (aller-retour serveur, déjà branché sur `recherche_sql()` côté carte pour les 3 entités), quelle que soit la taille du jeu de données.

## [1.13.0] — 2026-07-29

### Ajouté
- **Position/zoom de la carte mémorisés** (lieux, structures, événements) : en cliquant un marqueur puis en revenant en arrière depuis la fiche, la carte retrouve désormais la même position et le même niveau de zoom, au lieu de se recentrer sur l'ensemble des points à chaque fois. Mémorisé en `sessionStorage` (par page, pas au-delà de l'onglet). Logique factorisée dans `lassoInitCarteLieux()` (`assets/app.js`), reprise par les 3 vues carte (aucune duplication).

## [1.12.3] — 2026-07-29

### Corrigé
- **Notification « Filtre : ... non localisée(s) »** (lieux/structures) n'avait aucune couleur (classe `flash` seule) alors que toutes les autres notifications de l'app combinent `flash` avec `ok`/`err`/`warn`. Reprend désormais le style `warn` déjà utilisé pour les autres notifications informatives (ex. « Modification annulée »).

## [1.12.2] — 2026-07-29

### Ajouté
- **Aperçu de l'import CSV événements complet** : la table de résultats (simulation et import réel) affiche désormais toutes les colonnes lues (département/canton, pays, festival, spectacle, statut CSV brut, détails, lien) au lieu de seulement date/ville/lieu — plus facile à vérifier avant de confirmer un import.

### Sécurité
- Le lien affiché dans l'aperçu n'est rendu cliquable que s'il passe la même validation `http(s)://` déjà appliquée à l'import réel — sinon affiché en texte brut échappé (« invalide »), pour éviter qu'un CSV malveillant (ex. `javascript:`) ne produise un lien cliquable dans l'aperçu.

## [1.12.1] — 2026-07-29

### Corrigé
- **Filtres lieux/structures/événements pouvaient faire basculer silencieusement de liste à carte (ou l'inverse)** : le formulaire de filtres n'incluait le champ caché `vue` que pour la carte, jamais pour la liste. Comme `filtre_persistant()` retombe sur la dernière valeur mémorisée en session quand le paramètre est absent, changer un filtre en vue liste alors que la session avait mémorisé « carte » (lors d'une visite précédente) faisait basculer vers la carte de façon inattendue. `vue` est désormais toujours explicite dans les deux sens, sur les 3 pages (lieux, structures, événements). Les champs de filtre eux-mêmes étaient déjà identiques entre liste et carte (formulaire et requête SQL partagés) — vérifié, aucun changement nécessaire de ce côté.

## [1.12.0] — 2026-07-29

### Ajouté
- **Structures : lien « Voir la liste » sur le bandeau « villes non localisées »** de la vue carte (`?p=structures&vue=carte`), même comportement que celui déjà en place pour les lieux — mène à `?p=structures` filtré sur `non_localises=1`, avec un bandeau « Filtre : … » et un lien « Quitter ce filtre » sur la vue liste. Filtre `non_localises` ajouté à `structures_filtres()` (absent jusqu'ici, contrairement à `lieux_filtres()`).
- Le bandeau + formulaire de géocodage par lots de la vue carte (lieux ET structures) est désormais factorisé dans `carte_banner_geocodage_html()` (`lib/helpers.php`), la bannière « Filtre : non localisés » de la vue liste dans `filtre_non_localises_flash_html()`, et le fragment SQL du filtre dans `geocodage_non_localises_where()` (`lib/geocodage.php`) — plus aucune duplication entre les deux entités.

### Corrigé
- **Lien « Quitter ce filtre »** (lieux et structures) contenait un paramètre `p=` dupliqué dans l'URL (`?p=lieux&p=lieux&...`) — sans conséquence fonctionnelle (PHP ne retient que la dernière valeur), mais corrigé au passage.

## [1.11.1] — 2026-07-29

### Ajouté
- **Import CSV événements : séparateur virgule ou point-virgule détecté automatiquement** (comme l'import structures existant) — utile pour les exports Excel francophones, qui utilisent souvent le point-virgule. Détection factorisée dans `csv_detecter_delimiteur()` (`lib/helpers.php`), reprise depuis `lib/booking.php` (ex-`structures_detecter_delimiteur()`) plutôt que dupliquée.

## [1.11.0] — 2026-07-29

### Modifié
- **Renommage complet : « region » (canton/département) devient `departement_canton`** — colonnes `evenements`/`structures`/`lieux` (migration_56, `ALTER TABLE ... RENAME COLUMN`, sûr ici : aucune FK ne référence la colonne, pas de vue/trigger), code PHP, champs de formulaire, filtres (URL et session mémorisée), en-tête CSV de l'import/export événements, mapping de colonnes de l'import structures, logs d'historique. Corrige la confusion avec `grande_region` (Romandie, Normandie…) signalée par un utilisateur et confirmée par 122 événements anciens mal renseignés (voir 1.10.3). **Changement de contrat sur l'export public JSON/iCal des événements** : la clé `region` devient `departement_canton` — à adapter côté consommateurs externes du flux le cas échéant.
- En passant : un bug de variable masquée dans `views/mailing_campagne.php` faisait que le filtre « Département / canton » du mailing affichait en réalité la liste des grandes régions du dernier pays listé, jamais la vraie liste de départements/cantons — corrigé (renommage de la variable de boucle en conflit).

## [1.10.3] — 2026-07-29

### Corrigé
- **Exemple d'import événements introuvable en prod** : `.gitignore` excluait tout fichier `*.csv` (pensé pour les décomptes/exports PII), ce qui emportait aussi `assets/exemples/evenements.csv` — jamais versionné, donc absent après déploiement. Exception ajoutée (`!/assets/exemples/*.csv`) et fichier commité.
- **Exemple et aide d'import événements obsolètes** : ne mentionnaient pas la colonne `festival` (ajoutée avec la grande région) et n'indiquaient pas que `region` doit être un **code** (canton 2 lettres pour la Suisse, numéro de département pour la France) — source probable de confusion « canton vs région » à l'import, la grande région ne pouvant se déduire que d'un code reconnu. Documentation et fichier d'exemple mis à jour en conséquence.

### Vérifié
- La logique de déduction elle-même (`grande_region_deduite()`) fonctionne correctement pour les codes attendus (canton CH, département FR) — aucune régression trouvée côté import pour les événements récents (2026). Repéré en passant : 122 événements anciens (2016-2017, antérieurs à la fonctionnalité grande région) ont un canton/département de qualité douteuse (nom de grande région stocké dans `region` au lieu d'un code) — donnée historique, non touchée (voir règle « historique figé », CLAUDE.md).

## [1.10.2] — 2026-07-29

### Corrigé
- **Survol de l'étoile de marquage rapide** : un fond bleu apparaissait au survol malgré `background: none` sur l'état de base — une règle générique `button:hover` (boutons de l'appli) s'appliquait toujours pour cette seule propriété (le CSS se résout propriété par propriété, la spécificité plus élevée de `.flag-toggle:hover` sur les autres propriétés ne suffisait pas). `background: none` ajouté explicitement à `.flag-toggle:hover`.

## [1.10.1] — 2026-07-29

### Modifié
- **Marquage rapide (flag)** : le cœur est temporairement désactivé (cycle limité à aucun/étoile — le code reste en place, en commentaire, pour une réactivation ultérieure). L'étoile non marquée est plus discrète (gris clair) ; au survol, elle s'affiche en contour (sans fond) dans la couleur de mise en évidence.
- **Filtres de `?p=lieux`/`?p=structures`** : le panneau « Plus de filtres » revient désormais à la ligne au lieu de déborder de l'écran quand il contient beaucoup de champs ; l'espace réservé sous la ligne de filtres s'ajuste à la hauteur réelle du panneau ouvert (variable selon le nombre de champs et la largeur d'écran).

## [1.10.0] — 2026-07-29

### Ajouté
- **Marquage rapide (flag) sur les lieux et structures** : une étoile grise devant le nom (`?p=lieux`, `?p=structures`, `?p=lieu`, `?p=structure`) se cycle au clic — aucun → étoile (couleur de mise en évidence) → cœur (couleur `--danger`) → aucun — sans recharger la page (AJAX, `route_lieu_flag()`/`route_structure_flag()`). Filtrable (« Plus de filtres » → Flag : tous/non marqués/étoile/cœur) et disponible dans la modification groupée des deux listes (avec annulation, comme les autres actions en masse).

## [1.9.0] — 2026-07-29

### Ajouté
- **Lieux non localisables sur la carte** : la bannière « N lieu(x) dont la ville n'est pas encore localisée » (`?p=lieux&vue=carte`) porte désormais un lien « Voir la liste » vers `?p=lieux` filtré sur ces fiches précises (typo, lieu-dit introuvable pour Nominatim…), pour les corriger à la main plutôt que de recliquer indéfiniment sur « Géocoder ».
- **Structures : filtres avancés sur le(s) lieu(x) lié(s)** — type, jauge min/max, mois d'événement/de programmation. Une structure matche si au moins un de ses lieux liés satisfait la combinaison demandée.

### Corrigé
- **Filtres de `?p=lieux` et `?p=structures` non mémorisés** entre deux visites — contrairement aux événements, ils n'utilisaient pas `filtre_persistant()` : un lien sans query string (sidebar, retour contextuel) réinitialisait tout. Tous les filtres structurés sont désormais mémorisés en session, comme pour les événements (la recherche texte reste volontairement non mémorisée).
- **Lien de retour depuis la liste des lieux** : cliquer un organisateur dans `?p=lieux` renvoyait vers la fiche du lieu de la ligne plutôt que vers la liste — `lien_retour_contextuel()` gagne des cibles génériques `lieux`/`structures` (comme `dashboard`/`compta_ecritures`), corrigées à la source dans `url_avec_retour()`.

## [1.8.0] — 2026-07-29

### Ajouté
- **Vue carte pour Structures et Événements** (`?p=structures`, `?p=evenements_liste`), même principe que la carte des lieux (1.5.12) : bascule Liste/Carte (icônes à côté du titre), mêmes filtres actifs, un marqueur par ville géolocalisée (popup listant les fiches), bouton « Géocoder » par lots pour les villes manquantes.
  - `carte_points_grouper()` (`lib/geocodage.php`) factorise le regroupement par ville géolocalisée, partagé par les 3 modules.
  - `evenements.pays` (code ISO2) converti en nom avant la clé de cache, cohérent avec structures/lieux qui stockent le nom.
  - `geocodage_villes_manquantes()`/`geocodage_traiter_lot()` généralisés (table/colonnes ou callable en paramètre) pour servir les 3 modules sans dupliquer la logique de lot.

## [1.7.2] — 2026-07-29

### Corrigé
- **Grande région du canton de Genève (GE)** : déduite à tort comme « Genève »
  (catégorie distincte) au lieu de « Romandie » — confirmé par les données
  déjà en base, où l'écrasante majorité des fiches en canton GE utilisaient
  déjà « Romandie » (1 seule fiche sur 178 structures avait « Genève »).
  Cette erreur avait gonflé le rapport du script Dev (Paramètres → Dev) de
  ~220 « écarts » qui n'en étaient pas — non appliqués, aucune donnée
  touchée.

## [1.7.1] — 2026-07-29

### Ajouté
- **Mini-carte de localisation** sur les fiches lieu, structure et événement
  (`?p=lieu`, `?p=structure`, `?p=evenement`) : un marqueur sur la ville déjà
  géolocalisée (même cache `lieux_geocodage` que la vue carte des lieux), ou
  un bouton « Géocoder cette ville » si elle n'y est pas encore.

### Corrigé
- **Tuiles de la carte toujours invisibles malgré le fix CSP de la 1.7.0** :
  l'en-tête `Referrer-Policy: same-origin` supprimait le Referer sur toute
  requête cross-origin, or la politique d'usage de Nominatim/OpenStreetMap
  l'exige pour identifier le site appelant. Remplacé par
  `strict-origin-when-cross-origin` (n'envoie que l'origine en cross-origin,
  jamais le chemin/la requête — toujours HTTPS→HTTPS uniquement).

## [1.7.0] — 2026-07-29

### Ajouté
- **Grande région déduite du département/canton** (structures, lieux,
  événements) : le champ « Région » n'a plus besoin d'être saisi à la main
  pour la France (référentiel officiel des 101 départements, déjà en base
  mais jusqu'ici inutilisé) et la Suisse (26 cantons) — déduite et imposée à
  l'enregistrement (formulaire, import), select grisé côté formulaire pour le
  signaler. Cantons bilingues (Fribourg, Valais, Berne) exclus de
  l'automatisme (la langue dépend de la commune, pas du canton) : un défaut
  est suggéré mais reste librement modifiable, jamais imposé.
  - **Événements** gagnent la grande région (nouvelle colonne), pour la même
    cohérence que structures/lieux — `evenements.pays` reste en code ISO2
    (pas de migration de format), la logique de déduction accepte les deux
    représentations.
  - **Import événements** : ajoute la colonne `festival` (existait en base,
    jamais reprise par l'import) et déduit la grande région comme les autres
    points d'entrée.
  - **Script Dev** (Paramètres → Dev) de rattrapage des fiches existantes :
    dry-run listant les écarts avec la valeur actuellement enregistrée
    (utile aussi pour repérer des incohérences de longue date, ex. régions
    françaises pré-2016 jamais migrées), sauvegarde automatique avant
    d'appliquer.

### Corrigé
- **Tuiles de la carte des lieux invisibles** (fond gris, en local comme en
  production) : l'en-tête de sécurité `Content-Security-Policy` bloquait
  toute image externe (`img-src 'self' data:`, sans exception) — les tuiles
  OpenStreetMap n'ont donc jamais pu se charger depuis la mise en place de la
  vue carte (1.5.12). `tile.openstreetmap.org` est désormais explicitement
  autorisé.

## [1.6.0] — 2026-07-29

### Modifié
- **Vue carte des lieux**, finitions suite à la 1.5.12 :
  - Bascule Liste/Carte remplacée par deux icônes (`seg-picker`, déjà utilisé
    pour les droits par module/type de structure) directement à côté du
    titre, au lieu d'onglets texte sous l'en-tête.
  - La carte occupe désormais tout l'espace disponible à droite de la
    sidebar (plein écran, sans marge) ; le bandeau d'en-tête (titre, bouton,
    filtres) flotte par-dessus avec un fond semi-transparent, le bouton
    « Géocoder » en overlay flottant plutôt que de pousser le contenu.
  - La dernière vue consultée (liste ou carte) est mémorisée en session et
    reproposée par défaut à la prochaine visite (`filtre_persistant()`, déjà
    utilisé par la taille de pagination).

## [1.5.12] — 2026-07-29

### Ajouté
- **Vue carte des lieux** (`?p=lieux`, onglets Liste/Carte, mêmes filtres) :
  affiche les salles/festivals sur une carte (Leaflet + fonds OpenStreetMap,
  bundlés localement, pas de CDN). Un marqueur par ville géolocalisée, popup
  listant les lieux de la ville avec lien vers leur fiche.
  - Géocodage automatique ville+pays via Nominatim (OSM), mis en cache en
    base (table `lieux_geocodage`, jamais réinterrogé) — jamais par lieu
    individuel, une seule fois par ville.
  - Bouton « Géocoder » qui traite les villes manquantes par lots (politique
    Nominatim : 1 requête/seconde), avec reprise automatique tant qu'il en
    reste, interruptible à tout moment en quittant la page.

## [1.5.11] — 2026-07-29

### Ajouté
- **Onglet Paramètres → Dev** (réservé aux administrateurs) : lance depuis le
  web les scripts de maintenance ponctuels, jusqu'ici CLI uniquement.
  - Doublons exacts : détection immédiate (structures/lieux/contacts/tous),
    puis fusion en un clic avec sauvegarde automatique de la base avant écriture.
  - Mise à jour des dates depuis un CSV : dépôt du fichier, simulation
    (rapport détaillé des écritures prévues), puis enregistrement avec
    sauvegarde automatique. La logique commune aux deux points d'entrée
    (web et CLI, `scripts/doublons.php` / `scripts/maj_dates_import.php`)
    vit désormais dans `lib/dev.php`.

## [1.5.11] — 2026-07-30

### Modifié
- **Exporter → sauvegarde complète** : description mise à jour. Elle ne citait
  que les salaires et la comptabilité alors que le fichier contient bien
  l'intégralité de la base (facturation, événements, booking, paramètres,
  comptes…). Elle précise désormais ce qui n'y figure pas : les logos déposés
  dans `uploads/` et la configuration du serveur.

## [1.5.10] — 2026-07-25

### Corrigé
- **Sous-catégories homonymes** : un même nom de sous-catégorie peut exister
  sous plusieurs catégories (« Lieu de création » sous Organisateur et sous
  Autres). Le décompte affiché dans les paramètres cumulait les deux (7 au lieu
  de 1), et surtout le renommage comme la réaffectation à la suppression
  touchaient les fiches de l'autre catégorie. Ces trois opérations sont
  désormais limitées à la catégorie parente concernée.

## [1.5.9] — 2026-07-25

### Ajouté
- **Script `scripts/doublons.php`** : détecte et fusionne les doublons exacts
  de structures (nom + localité), de lieux (nom + ville + type) et de contacts
  (même structure + e-mail, ou identité + téléphone). La fiche la plus ancienne
  est conservée et récupère les rattachements (contacts, étiquettes, lieux,
  événements, historique, factures, mailings) ainsi que les champs qui lui
  manquaient. Simulation par défaut, `--detail` pour lister les groupes,
  `--appliquer` pour fusionner (avec sauvegarde automatique), `--db=` pour
  essayer sur une copie, `--type=` pour se limiter à une entité.

## [1.5.8] — 2026-07-25

### Ajouté
- **Modification groupée** des structures : « Ajouter une étiquette » et
  « Retirer une étiquette ». L'ajout accepte une étiquette existante
  (suggestions) ou un nouveau nom, créé à la volée. Le résultat annonce le
  nombre de fiches réellement modifiées, et chaque fiche en garde une trace
  dans son historique.

## [1.5.7] — 2026-07-25

### Ajouté
- **Paramètres → Catégories → Étiquettes** : nouvelle page pour ajouter,
  renommer et supprimer les étiquettes de structures, avec le nombre de fiches
  qui portent chacune. Supprimer une étiquette la retire des structures
  concernées (les fiches ne sont pas touchées) ; les doublons sont refusés.

## [1.5.6] — 2026-07-25

### Ajouté
- **Filtre « Statut »** sur les listes Structures et Lieux (Actifs / Inactifs /
  Tous), avec **« Actifs » sélectionné par défaut** : les fiches désactivées
  n'encombrent plus le travail courant, un choix suffit pour les retrouver.

## [1.5.5] — 2026-07-25

### Ajouté
- **Script `scripts/maj_dates_import.php`** : met à jour depuis un CSV
  UNIQUEMENT les dates (mise à jour, dernier contact, dernier concert) sans
  toucher au reste des fiches — utile quand un ré-import complet écraserait des
  saisies manuelles. Simulation par défaut, `--appliquer` pour écrire (avec
  sauvegarde automatique), `--db=` pour essayer sur une copie. Les entrées
  d'historique correspondantes sont créées, datées du jour concerné.

## [1.5.4] — 2026-07-24

### Corrigé
- **Import — dates** : les dates exportées par Excel/LibreOffice en numéro de
  série brut (ex. `44886`) ainsi que les formats `JJ.MM.AA`, `JJ-MM-AAAA` et
  ISO sont désormais reconnus (seul `JJ/MM/AAAA` l'était). Les « dernier
  contact » et « dernier concert » importés apparaissent donc bien dans
  l'historique, à leur date réelle.
- **Info-bulles (ⓘ)** : dans un libellé de formulaire, l'icône reste sur la même
  ligne que le texte, juste après lui.

### Ajouté
- **Structures** : colonne « Tags » (étiquettes) dans la liste.

## [1.5.3] — 2026-07-24

### Corrigé
- **Info-bulles (ⓘ)** : une info-bulle placée directement dans un libellé de
  formulaire ne repart plus à la ligne — elle est ancrée en haut à droite du
  champ (correctif CSS général, plus besoin de le régler au cas par cas).

### Ajouté
- **Import → historique** : les dates importées de « dernier contact »
  (structures) et de « dernier concert / diffusion » (lieux) génèrent une entrée
  d'historique datée du jour concerné.

## [1.5.2] — 2026-07-24

### Modifié
- « Salles & festivals » renommé en **« Lieux »** (menu, titres, colonnes).
- Supprimer un lieu rattaché à une ou plusieurs structures est désormais
  possible : les liens sont retirés (et journalisés côté structure) avant la
  suppression, au lieu d'être bloqués.

## [1.5.1] — 2026-07-24

### Corrigé
- **Historique** : les changements de statut (actif/inactif, désinscription),
  l'ajout/la modification/la suppression d'un contact, les étiquettes et les
  liaisons organisateur↔lieu sont désormais journalisés (ils manquaient).
- **Historique** : les lieux tiennent aussi leur journal (renommage, statut,
  organisateur, dernier concert).

### Modifié
- **Historique** : affichage fusionné — la fiche d'une organisation montre aussi
  l'historique de ses lieux, et la fiche d'un lieu celui de son organisateur,
  chaque entrée « rapportée » étant étiquetée de sa source.

## [1.5.0] — 2026-07-24

### Ajouté
- **Régions** : les grandes régions (Normandie, Romandie, Acadie…) deviennent
  une taxonomie imbriquée sous les pays (Paramètres → Pays), au lieu d'un champ
  texte libre. Listes déroulantes dépendantes du pays dans les fiches, filtres
  groupés par pays, réaffectation à la suppression.
- **Lien Événement ↔ Lieu** : un événement peut être rattaché à un lieu de la
  base ; les fiches Lieu et Structure listent leurs événements (« Historique »),
  et les listes ?p=lieux / ?p=structures affichent une colonne « Événements ».
- **Historique typé des fiches** : chaque structure et chaque lieu tient un
  journal (table `historique`) distinguant modifications (avec le diff des
  champs), notes, contacts/mailings et derniers concerts.
- **Lieux** : actif/inactif (bloc « Statut » de la sidebar) et site web.
- **Import** : colonne « Type de lieu » mappable — une venue (ex. salle de
  location) dans une catégorie non-organisateur crée désormais un lieu du bon
  type ; recherche des lieux par type ; sauvegarde automatique de la base avant
  chaque import.

### Modifié
- **Import — fusion champ par champ** : une structure déjà présente n'est plus
  écrasée en bloc. Les champs vides sont complétés, et seuls les champs remplis
  des deux côtés avec des valeurs différentes demandent un choix (valeur
  actuelle vs importée), champ par champ.

### Corrigé
- **Import — correspondance par la ville** : deux structures homonymes de villes
  différentes ne sont plus fusionnées à tort (l'e-mail reste prioritaire).
- **Import** : les médias et l'entourage ne créent plus de lieu.
- **Paramètres** : collision de variable `$groupes` entre l'écran d'import et la
  barre d'onglets, qui plantait la revue des regroupements.

## [1.4.0] — 2026-07-24

### Ajouté
- Module **Booking** (CRM des contacts de tournée) : gestion des structures
  (salles, festivals, médias, associations…), contacts, notes, étiquettes et
  salles/festivals liés — réutilise les structures de la Facturation sans en
  dépendre.
- **Salles & festivals** : type de lieu configurable (taxonomie propre),
  région / grande région / département-canton, périodes d'événement et de
  programmation, jauge, date de dernier concert, changement d'organisateur.
- **Catégories & sous-catégories** de structures imbriquées et configurables,
  avec réaffectation des entrées lors de la suppression d'une catégorie ;
  synchronisation de la taxonomie depuis les structures existantes.
- **Import CSV** d'un carnet d'adresses : correspondance des colonnes
  mémorisée d'un import à l'autre, détection d'organisateur, regroupements,
  résolution des conflits, périodes saisies en mois (numéros ou noms FR/EN).
- Page **Importer** unifiée : un seul formulaire « type de données → fichier →
  Simuler / Importer », au lieu d'une section par type.

### Modifié
- Les « Débiteurs » deviennent des **Structures**, partagées entre la
  Facturation et le Booking.
- **Paramètres** réorganisés en navigation à deux niveaux, avec un onglet
  **Application** (Mises à jour, Apparence, Modules, Utilisateurs, Diagnostic)
  et des catégories regroupées (Pays, Structures, Lieux).

### Corrigé
- Import : une catégorie contenant en fait une sous-catégorie (ou un accent)
  était versée à tort dans « Organisateur ».
- Import : la mise à jour de masse était ignorée au-delà de ~1000 lignes
  (limite PHP `max_input_vars`) — les décisions suivent désormais un défaut
  global, seules les exceptions sont transmises.
- Import : l'étape de revue des regroupements plantait (`Undefined array key
  "lieux"`) à cause d'une collision de variable `$groupes` entre la vue et la
  barre d'onglets des Paramètres — variables du partiel `_param_tabs` préfixées.

## [1.3.1] — 2026-07-14

### Ajouté
- Événements — nouveaux statuts SUISA « À venir » (date pas encore passée)
  et « Abandonné ».
- Événements — carte « Organisateur » : lien vers un débiteur existant ou
  création rapide depuis la fiche événement.
- Débiteurs — champs téléphone et personne de contact.
- Événements — icônes de visibilité (earth / earth-lock / globe-off) dans
  la liste, à la place des badges texte.
- Événements — case « Production externe » (détache les prestations liées
  sur les fiches de salaire), icône dédiée dans la liste, et action groupée
  pour l'activer/désactiver sur plusieurs événements à la fois.
- Événements — bouton d'export SUISA (CSV), respectant les filtres actifs.
- Pagination et recherche 100% en JavaScript (sans aller-retour serveur)
  pour les listes de moins de 100 éléments (Employés, Débiteurs, Écritures,
  Factures, Événements) — au-delà, le comportement serveur existant est
  inchangé.
- Couleur de mise en évidence paramétrable (Paramètres > Employeur, bleu
  par défaut), indépendante de la couleur principale — remplace celle-ci
  sur les boutons principaux, les sommes de salaire brut, les liens et les
  tags.

### Corrigé
- Recherche instantanée : passée côté serveur pour porter sur toute la
  liste, pas seulement la page déjà chargée.
- Texte invisible des éléments nichés dans un `<h1>` (badge inactif, code
  d'axe).
- Flèches de pagination invisibles (icônes chevron-left/right manquantes).
- Tags SUISA « à faire » (jaune) et « manquant » (orange), pour mieux les
  distinguer visuellement des autres statuts.
- Filtres : le champ de recherche (sans label visible) n'était pas aligné
  avec les `<select>` voisins (qui ont un label) ; hauteurs harmonisées.
  Le déclencheur « Plus de filtres » a maintenant l'apparence d'un bouton.

### Modifié
- Labels de formulaire : taille et interligne distincts entre `.form`
  (plus lisible) et `.filters` (compact), auparavant partagés.
- Recherche : placeholder générique « Rechercher... », sans compteur, sur
  toutes les listes.

## [1.3.0] — 2026-07-13

### Ajouté
- Pagination sur les listes potentiellement longues (fiches, employés,
  écritures, factures, débiteurs, événements) : 25/50/100/200 lignes par
  page (100 par défaut), taille mémorisée par page, navigation
  précédent/suivant préservant les filtres actifs.
- Recherche instantanée (insensible casse/accents) sur Employés, Analyse
  par axe, Débiteurs et Spectacles — même mécanisme que sur les listes qui
  l'avaient déjà (Écritures, Factures, Événements).
- Filtre « année » par défaut sur « Toutes les années » (Salaires,
  Écritures, Factures, Événements), plutôt que l'année courante.

### Corrigé
- Événements — statut SUISA « À faire » : excluait mal un événement dont le
  décompte avait été reçu sans date d'envoi enregistrée (saisie manuelle
  incomplète), qui apparaissait alors dans les deux filtres à la fois.
- Événements — statut SUISA « Envoyé » : n'incluait pas les événements
  « Manquant » (décompte en retard), alors qu'un décompte en retard reste
  avant tout un événement envoyé.
- Événements — liste : le badge SUISA affiche désormais la date du
  décompte plutôt que le texte générique « Décompte reçu ».
- Badge employé inactif : texte invisible sur fond clair.
- Tableaux sans défilement horizontal sur petit écran (plusieurs pages de
  comptabilité, facturation et salaires horaires).
- Lien de retour contextuel du formulaire de fiche de salaire : perdu en
  cas d'erreur de validation réaffichant la page.
- `alt=""` des logos employeur en aperçu (paramètres) : manquait le nom de
  l'employeur.
- Largeur de colonne `.col-petit` sur les en-têtes de tableau manquante
  dans la page « Cotisations ».

### Modifié
- Accessibilité : `aria-label` ajouté aux champs d'édition en ligne et aux
  boutons icône-seule restants.
- Confirmations de suppression/annulation (`confirm()`) harmonisées sur
  tous les formulaires concernés.
- Factorisation : validation d'upload centralisée dans un helper commun
  (`lire_fichier_importe()`), génération des badges de statut centralisée
  (`badge()`), réutilisées par les modules paie/comptabilité/facturation/
  événements.
- Nettoyage de règles CSS mortes ; généralisation de `.search-label` et du
  style des champs de recherche pour fonctionner hors du conteneur
  `.filters`.

## [1.2.5] — 2026-07-12

### Corrigé
- Export public JSON/iCal filtré par `spectacle_id` : un spectacle-groupe
  (artiste) n'étant jamais assigné directement à un événement, l'URL
  d'export d'un artiste était toujours vide. Inclut désormais les
  événements de ses feuilles (sous-spectacles).

### Modifié
- Page Spectacles : suppression de la card autour du tableau.

## [1.2.4] — 2026-07-12

### Ajouté
- Salaires horaires : renommage inline (crayon).
- Import de compte bancaire : IBAN inconnu → demande le nom du compte plutôt
  que de bloquer l'import.
- Import d'écritures : étape « Simuler » (dry-run) affichant un aperçu avant
  import définitif.
- Navigation : lien de retour contextuel (revient à la page d'origine, pas
  systématiquement à la liste).
- Fiche de salaire : coûts estimés recalculés en direct pendant la saisie.
- Modification groupée : annulation de la dernière action (bulk undo).
- Spectacles : hiérarchie artiste › spectacle, tri par artiste.
- Comptabilité : export et import CAMT.053 (relevé bancaire ISO 20022).

### Corrigé
- Import CAMT.053 : `registerXPathNamespace()` ne se propageant pas aux
  nœuds enfants retournés par `xpath()`, le préfixe devait être ré-enregistré
  sur chaque nœud avant une requête XPath relative.
- Import CAMT.053 : le solde de continuation (code `PRCD`) n'était pas
  reconnu comme solde d'ouverture sur un relevé qui n'est pas le premier.
- Plusieurs corrections identifiées lors d'une revue de code du diff depuis
  1.2.3 (dates, formats, cas limites) ainsi qu'un nettoyage de code mort.
- Modification groupée « Modifier l'axe » (Écritures) : n'affichait aucun
  message de confirmation ni possibilité d'annuler, contrairement aux autres
  actions groupées — annulation désormais prise en charge pour ce cas aussi.
- Ctrl+Z/Cmd+Z (annulation d'une modification groupée) cessait de fonctionner
  dès la disparition du bandeau de confirmation (10 s), alors que l'annulation
  reste possible côté serveur pendant 5 minutes.
- Lien de retour contextuel manquant sur deux parcours croisés : facture →
  débiteur et fiche de salaire → employé (revenaient toujours à la liste
  générique plutôt qu'à la page d'origine).

### Modifié
- Nettoyage interne : `dom_el()` (helpers.php) factorise la création
  d'éléments DOM namespacés, remplaçant les closures dupliquées des
  générateurs XML eLohnausweis et camt.053. `date_valide()` unifié entre
  `evenements.php` et `compta.php`. Motif JS d'affichage/masquage des lignes
  d'ajout (`data-show`/`data-hide`) factorisé dans `assets/app.js` (compte
  comptable, spectacles, salaires horaires), avec délégation d'événements
  sur `document` (le script étant chargé dans `<head>`, avant les boutons
  concernés).
- Message « Modification annulée » (après un Ctrl+Z) affiché dans un bandeau
  orange, distinct du bandeau vert de confirmation initiale.

## [1.2.3] — 2026-07-11

### Corrigé
- Comptabilité analytique par axe : le tableau des écritures ne défilait pas
  horizontalement sur petit écran.

### Modifié
- Facturation : colonne **Statut** renommée **Paiement**, le tag « Payée »
  affiche désormais la date de paiement plutôt qu'un texte fixe.
- Tableau de bord : graphique Évolution financière agrandi de 25%
  supplémentaires ; dates du widget « Prochains événements » plus petites en
  mode mobile.
- Nettoyage interne : nouveau `assets/app.js` (chargé une fois depuis
  `layout.php`) — `lassoNorm()` remplace 8 définitions dupliquées de la
  recherche insensible aux accents/casse ; `lassoInitCatSearch()` unifie 4
  des 5 widgets de dropdown catégorie/axe cherchable. Helper
  `valeur_autorisee()` centralisant la validation whitelist du dispatcher de
  modification groupée des événements (6 occurrences remplacées).

## [1.2.2] — 2026-07-10

### Modifié
- Tableau de bord : « Prochains événements » affiché en premier.
- Menu mobile : le tiroir s'ouvre depuis la **droite** (au même endroit que le
  bouton burger, la croix de fermeture reprend exactement sa position) ; le
  logo n'est plus répété en haut du tiroir (déjà visible dans la barre du haut).
- Dégradé de la page de connexion : la teinte centrale du dégradé venait d'une
  couleur fixe non dérivée de la marque, remplacée par une couleur calculée
  (cohérente avec n'importe quelle couleur principale choisie).
- Boutons d'en-tête (Comptes bancaires, Lettrage automatique, Analyse,
  Événements, Fiches, Employés…) : libellé masqué en mobile, icône seule,
  alignement harmonisé avec le standard `page-head`.
- Image de fond renommée `test.jpg` → `fond.jpg`.

## [1.2.1] — 2026-07-10

### Ajouté
- Modification groupée (bulk change) unifiée sur les listes Écritures et Événements :
  un seul sélecteur d'action (au lieu de plusieurs formulaires séparés) ; nouvelles
  actions Événements — suppression, région, pays, SUISA (applicable, envoi, décompte).
- Liste des événements : filtre « sans spectacle », colonne **Salariés** (nombre de
  salariés liés).
- Liste des fiches de salaire : colonnes **Charges sociales**, **Impôt à la source**,
  **Charges patronales** (même apparence que la page Cotisations).
- Comptabilité analytique par axe : ligne **Total des charges (salariales + patronales)**
  en fin de tableau des charges sociales prévues.
- Infobulles (icône **i**) remplaçant plusieurs textes d'aide statiques (fiche de
  salaire, employé, Cotisations, employeur, salaires horaires, e-mails, facture,
  spectacle, taux).
- Liste des fiches de salaire : ligne de totaux en fin de tableau.
- Liste des événements : filtres **Pays** et **Salariés** (oui/non), champ de
  recherche instantané (ville, salle, festival, spectacle) — les séparateurs de
  mois sans résultat se masquent automatiquement pendant la recherche.

### Modifié
- Dégradé du menu latéral et de la page de connexion plus vibrant.
- Tableau de bord : les widgets s'empilent en pleine largeur dès que la fenêtre ne
  permet plus deux colonnes confortables, pas seulement en mode mobile strict.
- Écritures : couleur de survol des lignes alignée sur celle utilisée ailleurs dans
  l'application (dérivée de la couleur de marque, plus une couleur fixe).
- Fiche de salaire : ligne de prestation resserrée (unité/quantité/taux/axe plus
  étroits, quantité doublée et fixe) ; select d'événement lié toujours visible pour
  permettre la liaison directement depuis la fiche, plus seulement son édition.
- Page « Résumé » renommée en **Cotisations**.
- Badge de paiement : n'affiche plus que la date (« Payé le » retiré, texte redondant
  avec le tag vert).
- Nettoyage interne (CSS/PHP) : suppression de règles CSS orphelines et de
  doublons (couleurs de colonnes, focus, media queries), réutilisation des
  helpers partagés (`options_axes()`, `preselectionner_option()`, `mois_nom()`)
  à la place de code dupliqué localement.

## [1.2.0] — 2026-07-09

### Ajouté
- **Nouveau module Événements** : dates, statut (option/confirmé/annulé), audience,
  région/pays, suivi SUISA (applicabilité, envoi, décompte), export public, import
  CSV, spectacles (photo, notes).
- Liens croisés événement ↔ prestation de fiche de salaire ↔ facture (association,
  détachement, affichage croisé dans les trois sens).
- Tableau de bord : graphique SVG « Évolution financière » (recettes, dépenses,
  résultat, patrimoine) et deux colonnes de widgets indépendantes.
- Page Résumé : filtres (regroupement / année / employé) alignés horizontalement.

### Modifié
- Facturation : indépendance vis-à-vis du module Comptabilité (activable seul) ;
  événement lié affiché en sidebar de la facture.
- Fiches de salaire : séparateurs de mois/année dans la liste, option « Toutes les
  années », boutons d'en-tête regroupés.
- Style des champs de formulaire harmonisé sur l'ensemble du site.

### Corrigé
- Requêtes N+1 sur le tableau de bord comptable et sur la fiche événement (regroupées
  en requêtes préparées).
- Garde de module manquante sur la liaison facture ↔ événement.
- Import CSV événements : validation du pays contre la liste autorisée.
- Suppression d'un événement lié à une facture n'empêche plus l'enregistrement de la
  facture (clé étrangère gérée proprement).

## [1.1.0] — 2026-07-02

### Ajouté
- **Module facturation** : débiteurs, factures **QR-facture suisse** (zone de paiement
  normée + code QR, `vendor/` committé), imports JSON/CSV, marquage manuel « payée »
  avec liaison à une écriture bancaire, PDF rapproché de la vue HTML.
- **Architecture modulaire** : modules activables/désactivables indépendamment
  (Fiches de salaire, Comptabilité, Comptabilité analytique, Facturation — dépendances
  gérées, ex. l'analytique nécessite la comptabilité), sans perte de données à la
  désactivation/réactivation.
- **Mise à jour en un clic** depuis Paramètres → Mises à jour : téléchargement de
  l'archive du canal et remplacement des fichiers en PHP pur (pour les hébergements
  sans `exec()`/`git`), avec sauvegarde de la base et journal `data/maj.log`.
- **Diagnostic du serveur** (téléchargement / décompression / écriture) déterminant
  la méthode de mise à jour possible ; détection de version compatible **dépôt privé**
  (jeton `MAJ_TOKEN` optionnel) et détection du recul de version au niveau commit.
- Nouvelle page **Résumé** (résumé complet + charges totales) sur le tableau de bord.

### Modifié
- **En-têtes** des pages Fiches de salaire, Écritures, Factures et Paramètres :
  bandeau sticky pleine largeur sur fond blanc (non sticky en mobile) ; titres,
  boutons et filtres/onglets regroupés dans l'en-tête.
- Paramètres réorganisés : « Unités de temps » fusionnées dans **Salaires horaires** ;
  onglet « Taux des déductions » renommé **Taux** ; titres de section déplacés à
  l'intérieur des cartes.
- Écritures : recherche sur le texte complet (pas seulement le résumé), tableau
  scrollable horizontalement sur petit écran.
- Comptabilité analytique : masque les axes inactifs sur la page principale.
- Icônes de navigation actualisées (Tableau de bord, Débiteurs).

## [1.0.0] — 2026-06-29

Première version numérotée.

### Salaires
- Employés, **fiches de salaire** mensuelles au calcul **figé** (montants et taux
  enregistrés à la création).
- **Taux propres à chaque année** : AVS/AI/APG, AC, A.mat, LAA (réduit/plein selon
  le seuil mensuel), LPP, impôt à la source ; **charges patronales** (coût employeur).
- **Tableau de bord** : totaux par trimestre / semestre / année, salaires à verser,
  retenues et charges.
- **Certificat de salaire** annuel (formulaire 11) + **export XML** eCS CSI.
- **Envoi des fiches par e-mail** (SMTP authentifié, repli sur `mail()`).
- **Import de fiches** depuis un fichier JSON (correspondance par n° AVS, sans
  écrasement des fiches existantes).

### Comptabilité
- **Compta de caisse** : comptes bancaires, **import des relevés PostFinance** (CSV)
  avec dédoublonnage.
- **Lettrage** manuel et **règles automatiques** (avec conditions de montant,
  suggestions) ; marquage **« Ne pas lettrer »**.
- **Plan comptable hiérarchique** ; **comptabilité analytique** par axes (ventilation
  multi-axe).
- **Comptes annuels** : compte de résultat + patrimoine, **comparaison pluriannuelle**
  (« Comparer jusqu'à »), contrôle de continuité du report à nouveau.

### Paramètres & comptes
- Paramètres en onglets ; **gestion des comptes utilisateurs** (création,
  réinitialisation de mot de passe, suppression).
- **Versionnage** (fichier `VERSION`) et **canaux** stable / test (branches git).

### Sécurité
- Mots de passe bcrypt (coût 12), anti-force-brute, sessions expirantes, CSRF sur
  tous les formulaires, HTTPS forcé + en-têtes de sécurité, base de données hors
  racine web.
