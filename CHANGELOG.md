# Journal des versions

Toutes les modifications notables de Lasso. Format inspiré de
[Keep a Changelog](https://keepachangelog.com/fr/) ; versionnage
[sémantique](https://semver.org/lang/fr/).

Les nouveautés arrivent d'abord sur le canal **test** (section « Non publié »),
puis sont promues sur le canal **stable** en figeant une version.

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
