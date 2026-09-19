# Conventions d'interface

Ce que Lasso fait *toujours* de la même façon. À lire **avant d'écrire un
écran**, et à mettre à jour **dès qu'une convention change** — un guide qui
décrit l'application d'hier coûte plus qu'il ne rapporte.

Le « pourquoi » technique (migrations, CSP, cache, performance) vit dans
`docs/DECISIONS.md`. Ici, seulement ce qui se voit et se clique.

Deux règles au-dessus des autres :

1. **Un geste qui existe déjà se refait pareil.** Avant d'inventer une
   interaction, chercher où l'application la résout déjà. Si le mécanisme
   existant ne convient pas, l'élargir plutôt que d'en poser un second à côté
   (voir `LASSO_MENUS`, assets/app.js : un seul écouteur ferme tous les menus).
2. **Le serveur fait le travail, le script améliore.** Toute mutation est un
   `<form method="post">` avec `check_csrf()`. Sans JavaScript, l'application
   reste utilisable : les formulaires partent, les pages se rechargent. Le
   script évite un aller-retour, il ne crée jamais la seule voie possible.

---

## 1. Où vivent les actions

**En haut à droite**, dans `.head-actions`, à l'intérieur de `.page-head` (page)
ou `.card-head-row` (carte). C'est vrai du « + » d'une liste comme de l'envoi
d'un document. Rien d'important ne se met en bas.

### « Modifier » est TOUJOURS le dernier bouton, tout à droite

Sans exception, sur une page comme sur une carte. C'est le geste qu'on cherche
des yeux au même endroit d'un écran à l'autre, et c'est l'emplacement que la
croix d'annulation vient reprendre quand l'édition s'ouvre (§ 2d). Ce qui le
précède, ce sont les gestes qui ne modifient pas : consulter, imprimer, envoyer,
contacter, émettre.

| écran | barre d'actions |
| --- | --- |
| `?p=fiche` | Aperçu · Envoyer · **Modifier** |
| `?p=facture` (brouillon) | Émettre · **Modifier** |
| `?p=structure` | Contacter · **Modifier** |
| `?p=employe_voir` | **Modifier** |
| `?p=campagne` | **Modifier** |

### « Supprimer » n'est pas dans cette barre

**La suppression vit sur l'écran de modification**, pas sur celui qui consulte.
Un écran de consultation s'ouvre cent fois pour lire, imprimer, envoyer ; on ne
vient sur celui d'édition que pour toucher à l'objet. C'est la même règle que
pour les lignes de liste, où la corbeille n'apparaît qu'en mode édition (§ 3).

Elle s'y place **tout à droite** de l'en-tête (`?p=fiche_edit`,
`?p=facturation_form`, `?p=employe`, `?p=campagne_form`), et jamais à la
création — il n'y a encore rien à détruire.

**Quand il n'y a pas d'écran de modification** — tout s'édite en place, carte par
carte (`?p=evenement`, `?p=structure`) —, c'est un crayon dans la barre de la
page qui découvre la suppression, et la croix qui la referme. Sans rechargement,
et sans rien d'autre à l'écran : ce qui n'a plus lieu d'être pendant ce temps
s'efface (« Contacter », « Feuille de route »).

Ce comportement est **générique**, dans `assets/app.js` — on ne le réécrit pas
par page :

| | |
| --- | --- |
| `.entete-editable` | le conteneur, en général `.page-head` |
| `.entete-edit-btn` | le crayon ; `data-focus="<sélecteur>"` place le curseur à l'ouverture |
| `.entete-annuler-btn` | la croix ; elle remet les formulaires de la zone dans leur état d'origine |
| `.entete-lecture` | visible en lecture seulement |
| `.entete-edition` | visible en édition seulement — `hidden` dans le balisage, pour que rien ne clignote au chargement |

```html
<div class="page-head entete-editable">
  <h1 class="entete-lecture">…</h1>
  <div class="head-actions">
    <a class="btn ghost entete-lecture">👁 Feuille de route</a>
    <form class="d-inline entete-edition" hidden data-confirm="…">🗑</form>
    <button class="btn ghost icon-only entete-edit-btn">✎</button>
    <button class="btn ghost icon-only entete-annuler-btn entete-edition" hidden>✕</button>
  </div>
</div>
```

### Le retour d'un écran de modification

Il ramène à **ce qu'on modifiait**, pas à la liste : `?p=fiche_edit&id=X` revient
sur `?p=fiche&id=X`. Le `?depuis=` continue de voyager dans le lien, pour que
l'écran de consultation garde de son côté son propre retour contextuel.

Un bouton d'action porte **une icône seule** (`.icon-only`) dès que la place
manque, avec `title` *et* `aria-label` — jamais l'un sans l'autre. Quand
l'intitulé tient, il vit dans un `<span class="lbl">` : la feuille de style
l'efface sur écran étroit et l'icône reste.

```php
<button type="button" class="btn ghost icon-only" title="Modifier" aria-label="Modifier"><?= icon('pencil') ?></button>
<a class="btn ghost" href="…"><?= icon('eye') ?><span class="lbl"> Feuille de route</span></a>
```

**Deux tailles, et elles ne se mélangent pas.** Les actions d'une **page ou
d'une carte** sont au format normal (`btn`, `btn ghost`, `btn danger`) : elles
commandent tout un bloc. Les actions d'une **ligne** sont au petit format
(`btn-sm`) : elles ne commandent que cette ligne, et un bouton de taille normale
y déborde. `.btn.icon-only.btn-sm` existe pour que l'icône seule suive.

**Dans une ligne, les CHAMPS aussi sont petits.** Un champ de 43 px posé entre
des pastilles de 17 px double la hauteur de sa ligne. Ligne et carte ont chacune
leur boîte de référence : `--action-height` (43 px) et `--action-height-sm`
(31 px). Elles se dérivent du remplissage réel des boutons — on ne réinvente
jamais un chiffre à la main.

Un bouton d'**icône seule** n'a pas de ligne de texte pour lui donner sa
hauteur : il la tient de `--action-height` (format normal) ou
`--action-height-sm` (petit), les deux dérivées du padding et de la bordure de
`button/.btn`. Ne jamais lui donner un padding vertical en dur — c'est ainsi
qu'un « Modifier » se retrouvait 6 px plus bas qu'un « Contacter » posé à côté.

**Une commande ne se cache pas derrière le survol.** Un crayon en `opacity: 0`
révélé au passage de la souris n'existe pas pour qui ne le cherche pas déjà, et
pas du tout au clavier ni au doigt. Si une colonne de crayons paraît trop
présente, c'est le bouton qu'il faut assourdir (`ghost`, gris), pas le faire
disparaître.

## 2. Modifier

**Cinq portées, cinq mécanismes** — et rien au-delà : si un besoin n'entre dans
aucun, on élargit celui qui s'en approche plutôt que d'en poser un sixième.

| Portée | Mécanisme |
| --- | --- |
| La barre d'actions d'une page | `.entete-editable` (§ 1) |
| Une carte entière | `.card-edit-btn` (a) |
| Une section d'une carte | `.section-editable` (b) |
| Un bloc répété | `lassoInitBlocEdition()` (c) |
| Une ligne d'une liste ordonnable | `.plan-edit-btn` + `.editing` (d) |

### L'exception : une étiquette se modifie dans son champ

**Les entités qui n'ont qu'un nom** — un tag, un axe analytique, une catégorie
de structure, un pays, une unité — n'ont pas besoin d'un mode d'édition : le
crayon ouvre directement le champ, là où le nom se lit, et l'enregistrement le
referme. C'est le motif `.row-field-disp` / `.row-field-inp` (`?p=compta_ecritures`
pour l'axe d'une écriture, `?p=structures` pour un tag dans son entonnoir).

La limite est le contenu, pas l'écran : dès qu'une ligne porte **plusieurs
champs**, elle relève de (d) et de son trio de boutons. Une étiquette, c'est un
mot — on le corrige, on valide, c'est fini.

### a. Une carte entière — `.card-edit-btn`

Générique, dans `assets/app.js`. La carte porte `.card-editable`, son contenu en
lecture `.card-disp`, son formulaire `.card-edit` (masqué), et `.head-actions`
contient le crayon puis `.card-save-btn` / `.card-cancel-btn`, cachés. Le crayon
échange les trois. **Une seule zone d'édition par carte** : c'est la limite.

**« Annuler » referme sur place**, sans recharger : `form.reset()` rend au
formulaire les valeurs que le serveur avait écrites — c'est l'état INITIAL du
document, donc exactement ce qui s'affichait. Un `<a href>` vers la page
elle-même faisait clignoter tout l'écran, perdait la position de défilement et
rouvrait les autres cartes dans leur état par défaut, pour abandonner trois
caractères.

⚠️ `.card-edit` n'est pas toujours le `<form>` : sur une fiche de structure,
c'est un `<div>` **à l'intérieur** du formulaire de la carte. La remise à zéro
passe donc par `element.form` de ses champs, jamais par le conteneur seul.

### b. Une section d'une carte — `.section-editable` + `.edit-toggle-btn`

Un crayon d'en-tête bascule `.editing` sur la section ; tout ce qui porte
`.edit-only` apparaît alors, `.read-only` disparaît. Le bouton devient une croix
et son `title`/`aria-label` passent à « Annuler ». Sert quand une section entière
change de mode — ajouter, lier, délier, supprimer (colonne de droite de
`?p=structure`).

### c. Un bloc répété — `lassoInitBlocEdition()`

Le plus réutilisable : on lui donne `{bloc, lecture, edition, ouvrir}` et il
bascule les deux, en restaurant les valeurs d'origine à l'annulation. Utilisé
par les contacts d'une structure, les boîtes d'envoi, les modèles de message,
les tags.

Quelques écrans font la même bascule avec un script de page plutôt qu'avec ce
helper (`?p=compta_axes`, `?p=compta_comptes`, `?p=taux_horaires`). Peu importe
le mécanisme : **les boutons et leur ordre sont ceux décrits en 2d** —
enregistrer mis en évidence, corbeille rouge révélée par l'édition, croix à la
place du crayon.

Quand l'édition ouvre un **cadre** à la place de la ligne (contacts d'une
structure, boîtes d'envoi, modèles de message), ses commandes sont en haut à
droite du cadre — là où était la ligne : **enregistrer puis la croix**, celle-ci
en dernier comme partout. Supprimer reste au pied du cadre, à distance des deux
gestes courants.

### d. Une ligne d'une liste ordonnable — `.plan-edit-btn` + `.editing`

Le motif des listes qui se réordonnent (`?p=compta_plan`, `?p=postes`,
`?p=spectacles`, `?p=parametres_structures`, `?p=parametres_pays`) :

```html
<tr class="plan-row" data-id="12">
  <td><div class="inline-edit">
    <span class="plan-grip" draggable="true">…</span>
    <span class="plan-nom">Libellé</span>            <!-- lecture -->
    <form class="inline-edit plan-edit">…</form>     <!-- édition -->
  </div></td>
  <td class="actions nowrap">
    <button class="plan-edit-btn">✎</button>  …
  </td>
</tr>
```

Le trait à retenir : **les deux états sont dans le DOM, et le repli sans
JavaScript est le formulaire lui-même**. `.plan-nom` et `.plan-edit-btn` sont
`display:none` par défaut ; c'est `.dnd-on`, posée sur le conteneur *par le
script*, qui les révèle et masque le formulaire. Sans JavaScript, on édite
directement, sans rien basculer.

### Les boutons d'une ligne, dans les deux états

**Le crayon est le DERNIER bouton** de la colonne d'actions — tout à droite. Ce
qui le précède en lecture, ce sont les gestes qui ne modifient rien : les
flèches de repli (tout à gauche), puis télécharger, archiver, ouvrir une fiche.

**En édition, le crayon cède la place à trois boutons.** Ils se lisent dans
l'ordre du geste, et **la croix se pose exactement à l'emplacement du crayon,
tout à droite** : un seul et même endroit pour ouvrir l'édition et pour la
refermer, où que soit la souris quand on change d'avis.

| ordre | | |
| --- | --- | --- |
| 1 | **Enregistrer** | mis en évidence (`class="btn btn-sm"`, pas `ghost`), avec son libellé dans un `<span class="lbl">` — que l'écran étroit efface, le fond plein suffisant alors à le distinguer |
| 2 | **Supprimer** | rouge (`btn danger`), classe `.plan-supprimer` |
| 3 | **Annuler** | une croix (`btn ghost icon-only`), classe `.plan-annuler-btn`, **à la place du crayon** |

D'autres commandes peuvent s'y ajouter selon l'écran — un interrupteur, un
choix de parent. Elles se rangent avant la croix, qui reste la dernière.

**Tous ces boutons sont au petit format** (`btn-sm`), y compris celui qui est
mis en évidence : une ligne de liste n'a pas la place d'une barre de carte, et
un bouton de taille normale y déforme la hauteur de la ligne. `btn-sm` et
`icon-only` se combinent — `.btn.icon-only.btn-sm` donne à la corbeille et à la
croix exactement la hauteur du bouton « Enregistrer » à côté d'elles.

```html
<!-- flèches de repli, télécharger, archiver… -->
<button type="submit" form="plan-edit-12" class="btn btn-sm cell-edition">💾 Enregistrer</button>
<form class="d-inline plan-supprimer" data-confirm="…">
  <button type="submit" class="btn danger btn-sm icon-only">🗑</button>
</form>
<button type="button" class="btn ghost btn-sm icon-only plan-edit-btn" title="Modifier">✎</button>
<button type="button" class="btn ghost btn-sm icon-only plan-annuler-btn cell-edition" title="Annuler">✕</button>
```

Les boutons sont posés **à plat** dans la cellule, sans conteneur : c'est leur
ordre dans le DOM qui fait l'ordre à l'écran, et c'est ce qui permet à la croix
de venir se poser juste après le crayon, donc à sa place.

Enregistrer vit **hors** du formulaire et le vise par `form="<id>"` : c'est ce
qui lui permet de se lire à droite, avec les deux autres, plutôt qu'au pied des
champs. Le formulaire d'édition a donc toujours un `id`.

`.cell-edition` est masquée hors `.editing`, `.plan-edit-btn` l'est en
`.editing` — les deux règles sont globales, il n'y a rien à écrire par écran.

**Sur une carte, la règle tombe d'elle-même** : le crayon y est seul dans
l'en-tête, donc déjà tout à droite, et `.card-cancel-btn` s'y substitue au même
endroit (`?p=evenement`, `?p=structure`, l'historique).

## 3. Supprimer

- Toujours un `<form method="post">`, jamais un lien : aucune route ne mute sur
  un GET (les trois exceptions, toutes hors session, sont listées dans
  `CLAUDE.md § Modules & droits`).
- **Un lien reçu par e-mail ne mute jamais non plus.** Un antivirus de
  messagerie ou l'aperçu de lien d'un client mail suit les URL d'un message pour
  les inspecter : le lien ouvre une page de confirmation, c'est le bouton qui
  agit (`route_desinscription()`).
- Toujours `data-confirm="…"`. Le message dit **ce qui est détruit**, pas
  « Êtes-vous sûr ? » — *« Supprimer cette pièce jointe ? Le fichier sera effacé
  du serveur. »*
- Une suppression qui emporte autre chose que la ligne (un fichier, une réponse
  notée) le dit dans son message.
- Quand la suppression demande une **décision** (que faire des écritures d'une
  catégorie ?), elle passe par une boîte de dialogue plutôt qu'un `confirm()`.

### La corbeille est rouge, et ne se montre qu'en édition

**Rouge, partout** : `class="btn danger …"`. Une corbeille en bouton neutre se
confond avec les autres actions d'une ligne, alors qu'elle est la seule dont on
ne revient pas.

**Une seule variante est admise** : l'icône rouge **nue**, sans fond ni contour,
pour les listes denses où aucun bouton n'en porte (`.tag-gerer-suppr`, les
étiquettes dans leur entonnoir). Le rouge est alors posé dès la lecture, pas
seulement au survol. Ce qui n'est jamais admis, c'est une corbeille **sombre sur
un bouton standard** : c'est exactement ce qui la fait passer pour une action
ordinaire.

**Visible seulement quand la ligne est en édition.** Dès qu'un écran a un mode
d'édition, la corbeille lui appartient : un geste irréversible n'a pas à être à
portée de clic quand on ne fait que lire.

| Mécanisme d'édition | Comment |
| --- | --- |
| Liste ordonnable (`.plan-row`) | classe `.plan-supprimer` — une règle globale la masque hors `.editing` |
| Section éditable (`.section-editable`) | `.edit-only` : révélée par le crayon de section |
| Bloc répété (`lassoInitBlocEdition`) | **dans le panneau d'édition**, en bas |
| Script de page (`?p=compta_axes`, `?p=compta_comptes`, `?p=taux_horaires`) | `hidden` posé au chargement, levé par le crayon — même résultat, sans le helper |

```css
.dnd-on .plan-row .plan-supprimer { display: none; }
.dnd-on .plan-row.editing .plan-supprimer { display: inline-flex; }
```

La règle est conditionnée à `.dnd-on`, donc au script : **sans JavaScript, ces
lignes sont en édition permanente** (§2d) et la corbeille doit y rester.

Pour un bloc répété, le `<form>` de suppression vit **à côté** du formulaire
d'édition — deux `<form>` ne s'imbriquent pas — et son bouton le vise par
`form="<id>"`.

## 4. Réordonner

**Glisser-déposer**, partout où c'est possible. C'est la convention de
l'application : plan comptable, lignes du décompte, catégories de structure,
pays et régions, spectacles, déroulé d'un événement, cartes du tableau de bord.

Le vocabulaire est fixe et partagé :

| | |
| --- | --- |
| `.plan-row` | la ligne déplaçable, avec `data-id` |
| `.plan-grip` | la **poignée** — `draggable="true"`, icône `grip`, `title="Glisser pour ranger ailleurs"`, `aria-hidden` |
| `.plan-indic` | la barre d'insertion qui suit le curseur |
| `.dnd-on` | posée sur le conteneur par le script : elle atteste que le glisser-déposer est actif, et **c'est elle qui fait apparaître la poignée** |
| `.plan-fallback` | les commandes de repli, **masquées par `.dnd-on`** |

Deux implémentations, selon la forme de la liste :

- **`lassoPlanArbre()`** — liste hiérarchique : le décalage horizontal du
  curseur pendant le glissement change le **niveau** (22 px par cran), la
  position verticale change le rang. `?p=compta_plan`, `?p=parametres_structures`,
  `?p=spectacles`, `?p=parametres_pays`.
- **`lassoOrdreListe()`** — liste plate, même vocabulaire sans la hiérarchie.
  `?p=postes`, le déroulé d'un événement (`?p=evenement`), les cartes du tableau
  de bord (`?p=resumes`, panneau « Organiser les cartes »).

Trois règles qui comptent :

1. **Le dépôt poste, le serveur renumérote.** Le script renseigne un formulaire
   caché (`#reorder-form` : `id` + `order` complet) et l'envoie ; la page
   n'invente aucun ordre local. Un rang calculé côté client et un rang stocké
   qui divergent, c'est une liste qui se réordonne toute seule au rechargement.
   Sur une liste **plate**, l'envoi part en AJAX (`retour=json`, même convention
   que les cellules de `?p=structures`) et la ligne n'est déplacée dans le
   document qu'**après** l'accusé de réception : ce qui s'affiche est ce qui est
   enregistré. Un simple déplacement de nœud, jamais une reconstruction — les
   autres lignes gardent leurs écouteurs et leur état d'édition. Si l'appel
   échoue, le formulaire part de façon classique : la page se recharge et montre
   l'état réel, plutôt que de laisser une ligne déplacée à l'écran et pas en
   base. Les listes **hiérarchiques** rechargent encore : un dépôt y change aussi
   le niveau et le parent, que le document ne peut pas mettre à jour seul.
2. **Il y a toujours un repli sans JavaScript** (`.plan-fallback`) : un menu
   « dans <parent> » avec son bouton d'enregistrement, ou des flèches. Le script
   pose `.dnd-on`, qui les masque — donc sans lui, ils restent là.
3. **Sur téléphone, la poignée s'efface** et les flèches de repli reprennent la
   main : glisser au doigt dans une page qui défile est un combat perdu.
   ⚠️ Appliqué au seul déroulé d'un événement pour l'instant ; les cinq autres
   listes gardent leur poignée au doigt. À trancher : étendre, ou retirer la
   règle.
4. **La position de défilement est mémorisée** (`sessionStorage`) avant l'envoi
   et restaurée au retour, `history.scrollRestoration = 'manual'`. Sans cela,
   déplacer la trentième ligne d'une liste renvoie en haut de page à chaque
   dépôt. Les deux helpers le font ; ailleurs, `?p=evenement` et
   `?p=import_fiches` le réimplémentent à la main, faute d'une liste ordonnable
   à qui le confier.

### Quand des flèches, alors ?

Seulement quand le glisser-déposer ne convient pas : une liste de deux ou trois
éléments (`.regle-arrows`, `?p=compta_regles`). Partout ailleurs elles sont le
**repli** du glisser-déposer, pas une alternative. Dans les deux cas :

- aux extrémités, la flèche est **`disabled`**, pas masquée (`?p=compta_plan`) :
  la colonne garde sa largeur d'une ligne à l'autre, et l'opacité d'un bouton
  empêché dit déjà qu'il ne se passera rien ;
- côté serveur, échanger les rangs de deux voisins **après avoir renuméroté**
  (`feuille_deplacer()` / `feuille_renumeroter()`, `lib/feuille_route.php`).
  Des rangs égaux — import, reprise — ne doivent pas rendre l'ordre
  imprévisible : `ORDER BY ordre, id` retombe sur l'ordre de création ;
- **réserver la largeur** de la colonne de boutons quand leur nombre varie d'une
  ligne à l'autre (`min-width`), sinon la colonne voisine ondule.

## 5. Ajouter

Un **« + »** qui déplie un menu quand il y a plusieurs choses à ajouter ; un
bouton nommé quand il n'y en a qu'une. Aligné à droite : on ajoute *après* avoir
lu la liste.

Le menu est un `<details>` + un panneau absolu, aligné sur le **bord droit** du
bouton. Les entrées sont des **liens** qui rouvrent la page avec le type
demandé (`?ajout=<type>`), et c'est le **serveur** qui déplie le formulaire :

- rien à tenir côté client ;
- l'adresse dit ce qui est ouvert ;
- le formulaire s'affiche là où il a de la place (sous la liste, pleine
  largeur), ce qu'un panneau de menu ne peut pas offrir.

En cas d'erreur, la redirection **conserve** le type demandé : refermer le
formulaire emporterait la saisie avec le message qui l'explique.

**L'ENVOI, lui, part en arrière-plan** — c'est une ligne de plus dans une liste
déjà à l'écran, pas une page qui change. La route répond avec **la ligne
rendue**, et le script l'insère :

- le balisage de la ligne vit dans **un partiel** (`_evenement_feuille_item.php`)
  que la boucle de la liste et la route rendent tous deux : deux exemplaires
  divergeraient à la première retouche ;
- la ligne est rendue depuis ce que la base contient **vraiment** après
  l'insertion (jointures comprises), pas depuis ce qu'on croit avoir écrit ;
- les écouteurs de la liste sont **délégués** (`document.addEventListener`), pas
  posés ligne à ligne : une ligne arrivée après coup a son crayon et sa poignée
  sans qu'on rebranche quoi que ce soit ;
- ce que l'insertion périme se met à jour — la ligne qui n'est plus la dernière
  retrouve sa flèche « descendre », le « aucun élément » cède la place à la
  liste ;
- l'adresse perd son `?ajout=` : il n'y a plus de formulaire ouvert à décrire ;
- une erreur **métier** (fichier trop lourd, type inconnu) revient en JSON et
  s'affiche au-dessus du formulaire, qui garde la saisie ; un échec **réseau**
  renvoie le formulaire de façon classique et la page montre l'état réel.

### La rangée de liaison — `.linked-add`

Un champ (ou une liste) et son bouton, sur une ligne, pour rattacher quelque
chose à la fiche qu'on lit : un employé à une date, une facture à un événement,
une salle à une structure, un tag, une campagne.

**Deux verbes, deux icônes, et rien d'autre :**

| | Quand | Icône | Libellé |
| --- | --- | --- | --- |
| **Lier** | rattacher une entité qui existe déjà, des deux côtés | `link` | Lier |
| **Ajouter** | verser une entrée dans une liste | `plus` | Ajouter |

**Le bouton a la taille d'un champ**, pas celle d'un bouton de ligne : format
normal (`btn`, jamais `btn-sm`), donc `--action-height` — la même boîte que le
champ posé à côté. Un `btn-sm` y faisait douze pixels de moins et pendait au
milieu de la rangée.

**Le libellé vit dans un `<span class="lbl">`** : la feuille de style l'efface
sous 800 px, où la rangée n'a plus la largeur d'un mot, et la boîte reste celle
du champ. Un libellé qui peut disparaître veut dire `title` **et** `aria-label`
sur le bouton, toujours.

### Rattacher une étiquette ou assimilé : le motif complet

**Étiquette, campagne — tout ce qui se rattache en un mot** se pose de la même
façon, et cette façon ne se rediscute pas d'un écran à l'autre :

| | |
| --- | --- |
| **Ouverture** | un « + » discret dans la ligne ou en tête de carte, qui déplie la rangée |
| **Format** | petit, champ compris (`.linked-add-ligne`, § 1) |
| **Champ** | **cherchable**, jamais un menu déroulant — dès la dizaine d'entrées, on tape trois lettres plutôt que de parcourir |
| **Liste** | **fermée** par défaut : on rattache une entité existante. La création se déclare, elle ne s'improvise pas |
| **Bouton** | un « + » mis en évidence, **sans libellé**, et une croix pour refermer |
| **Envoi** | **en arrière-plan** (`data-ajout` / `data-remplace`), jamais un rechargement |

Ce sont cinq décisions qui vont ensemble : un champ cherchable dans une rangée
à la taille d'une carte, ou un ajout en arrière-plan dont le bouton porte un
libellé, se remarquent tout de suite comme des exceptions.

### Le gabarit d'une rangée dans une ligne — `.linked-add-ligne`

Une cellule de tableau, la suite des pastilles d'une fiche : là, **tout passe au
petit format, champ compris** (§ 1), et le bouton d'ajout se réduit à un « + »
**sans libellé** — il n'y a pas la place d'un mot. Il reste mis en évidence
(`btn btn-sm icon-only`, pas `ghost`) : c'est l'action de la rangée. La croix
d'annulation l'accompagne, petite elle aussi.

```html
<form class="linked-add linked-add-ligne">
  <input type="text" class="cat-search-input" placeholder="Tag…">
  <button type="submit" class="btn btn-sm icon-only" title="Ajouter" aria-label="Ajouter le tag">＋</button>
  <button type="button" class="btn ghost btn-sm icon-only" title="Annuler" aria-label="Annuler">✕</button>
</form>
```

Concerne l'ajout d'une étiquette et d'une campagne, sur `?p=structures`,
`?p=campagne` et la fiche d'une structure.

**Le champ y est cherchable, pas un menu déroulant** (`lassoInitCatSearch()`,
`.cat-search` + `.cat-search-input` + `.cat-search-val` + `.cat-search-list`) :
dès qu'une liste dépasse la dizaine, on sait ce qu'on cherche et l'on tape les
trois premières lettres. Liste **fermée** — la valeur cachée n'est remplie que
par une sélection, `clearHiddenOnInput` la vide à la frappe : on ne crée pas
l'entité depuis là. Un `<li data-val="__new__">` en tête ouvre le cas
contraire, quand la création est prévue (lier une salle depuis une structure).

```html
<form class="linked-add">
  <input type="text" class="cat-search-input" placeholder="Rechercher une facture à lier…">
  <button type="submit" class="btn ghost" title="Lier" aria-label="Lier cette facture">
    🔗<span class="lbl"> Lier</span>
  </button>
</form>
```

Quand une liste recommence toujours par les mêmes entrées, offrir un bouton qui
les pose d'un coup (« Déroulé type »), **idempotent** : n'ajoute que ce qui
manque, comparaison insensible à la casse.

## 6. Menus déroulants

`<details>` + panneau. Un `<details>` natif ne se referme pas au clic dehors :
l'écouteur global d'`assets/app.js` s'en charge.

```js
const LASSO_MENUS = '.col-filter[open], .feuille-menu[open], .dash-reglages[open]';
```

**Un nouveau menu s'ajoute à ce sélecteur** — on n'écrit pas un second
écouteur. Le test `details.contains(e.target)` est ce qui permet de cliquer une
entrée avant que le menu ne se referme.

**Tout `<details>` n'est pas un menu.** Un **panneau de saisie** — « Plus de
filtres » (`.filters-more`), où l'on coche plusieurs cases avant d'envoyer —
reste ouvert : le refermer au premier clic à côté ferait perdre le travail en
cours. Il n'est donc pas dans `LASSO_MENUS`, et c'est voulu. La règle : un menu
où l'on choisit UNE entrée se referme au clic dehors ; un panneau où l'on
compose se referme quand on le décide.

## 7. Fenêtres

Deux familles, à ne pas confondre.

| | Quand | Quoi |
| --- | --- | --- |
| **Aperçu de document** | Montrer une feuille A4 : décompte, certificat, bilan, feuille de route | `<a href="?p=…_print" data-preview target="_blank">` — la fenêtre partagée de `views/layout.php` (`#preview-modal`) charge la page d'impression dans un cadre à la largeur d'une feuille |
| **Boîte de dialogue** | Poser une question, recueillir une saisie | `.modal-overlay` > `.modal-card` > `.modal-actions`, ouverte par `data-show="<id>"`, fermée par `data-hide="<id>"` |

`data-preview` donne gratuitement la croix de fermeture, Échap, le clic hors
cadre, et la barre « Imprimer / PDF » de la page cible. **Ne jamais reconstruire
cette fenêtre** : un aperçu de document passe par elle. Format `a4` par défaut,
`data-preview="ajuste"` pour un contenu qui n'est pas une feuille.

Ne pas poser `data-hide` sur le fond d'une boîte de dialogue : un clic à
l'intérieur de la carte remonterait jusqu'à lui.

## 8. Documents imprimables

Une page `views/*_print.php` rendue par `render_bare()` — sans layout, donc son
en-tête est à écrire à la main. Trois choses s'oublient systématiquement :

```php
<html lang="fr" data-theme="clair">   <!-- une feuille est blanche : encre foncée, quel que soit le thème -->
    <link rel="stylesheet" href="assets/app.css?v=<?= @filemtime(__DIR__ . '/../assets/app.css') ?: '1' ?>">
```

…et le script d'impression, `data-print` n'étant traité que dans `app.js`, que
ces pages ne chargent pas :

```php
<script nonce="<?= e(csp_nonce()) ?>">
document.querySelector('[data-print]').addEventListener('click', () => window.print());
document.addEventListener('keydown', e => { if (e.key === 'Escape') window.close(); });
</script>
```

Structure : `<body class="print-page">` > `.print-toolbar` (masquée à
l'impression) > `.sheet` (la feuille blanche).

**Toutes ne s'impriment pas.** L'aperçu d'export SUISA
(`evenements_export_suisa_print.php`) utilise la même enveloppe pour montrer ce
qui partira en CSV : sa barre porte « Télécharger » et « Copier », pas
« Imprimer » — un tableau de dix-huit colonnes n'est pas fait pour le papier.
Une page d'impression sans bouton d'impression n'a donc pas besoin du script,
mais garde le thème clair et la feuille de style versionnée.

**L'aperçu doit montrer ce qui sortira de l'imprimante.** Une règle qui ne vaut
que sous `@media print` crée un aperçu menteur. Cas déjà rencontré : les `<h1>`
de l'application sont remplis d'un dégradé en clip-text, qu'un navigateur
n'imprime pas — le titre disparaissait. La correction vaut pour les deux.

Une page d'impression **partage son corps** avec l'écran quand les deux montrent
la même chose (`_evenement_feuille_corps.php`, `_fiche_body.php`) : deux
exemplaires divergent à la première retouche.

## 8 bis. Envoyer un document par e-mail

Même transport que les fiches de salaire : `envoyer_email()`
(`lib/helpers.php`). En développement, **rien ne part** — tout est journalisé
dans `data/emails_envoyes.log`, ce qui rend l'envoi vérifiable.

- Le corps réutilise le **corps partagé** de l'écran et de l'impression, dans un
  document HTML autonome qui **embarque la feuille de style** : un client mail
  ne va pas chercher un fichier CSS. Avec `data-theme="clair"`, comme une page
  d'impression.
- L'objet porte de quoi **retrouver le message** des semaines plus tard : la
  date et le lieu, pas seulement « Feuille de route ».
- **Un message par destinataire**, jamais un envoi groupé en copie : les
  adresses de l'équipe n'ont pas à circuler entre elles, et un échec sur l'une
  n'emporte pas les autres.
- Le compte rendu revient **par l'URL** (`?mail…=`) : un rechargement ne doit pas
  renvoyer les messages. Il distingue les envoyés, les échecs **et** ceux qui
  n'ont pas d'adresse — sans quoi on croirait la feuille partie à toute
  l'équipe.
- Le bouton est **empêché plutôt qu'absent** quand personne n'est joignable, et
  son `title` dit laquelle des deux raisons s'applique : aucun employé lié, ou
  aucun d'eux n'a d'adresse. Un bouton disparu n'apprend rien.

> Un formulaire posté depuis une page affichée dans la fenêtre d'aperçu doit
> porter `target="_top"` : sans lui, la redirection s'afficherait **dans le
> cadre d'aperçu**, à la place du document.

## 9. Listes

- **Tri** : `tri_entete_html()`, trois états au clic — croissant, décroissant,
  retour à l'ordre par défaut. Le tri se fait en SQL (les listes sont paginées)
  et se mémorise en session. Le sens s'applique à **chaque terme** de l'`ORDER
  BY`, sinon « nom, prénom DESC » ne renverse que les prénoms.
- **Filtres de colonne** : `filtre_colonne_html()`, un entonnoir par colonne,
  mémorisé en session, avec un bouton de remise à zéro quand au moins un est
  actif.
- **Recherche** : instantanée sur les lignes affichées sous le seuil de
  pagination client (`pagination_mode_client()`), sinon envoyée au serveur.
- **Largeur des colonnes** : un tableau en disposition automatique répartit la
  place entre toutes ses colonnes, y compris celles qui n'en demandent pas.
  Serrer les colonnes courtes sur leur contenu (`width: 1%` + `white-space:
  nowrap`) laisse toute la place à celle qui en a besoin. Poser ces classes **à
  la main dans le balisage**, jamais par `:nth-child` : une colonne
  conditionnelle — « Axe », absente sans le module analytique — décale tout
  décompte de position.
- **Un seul compteur**, quelle qu'en soit la cause : filtres et recherche
  réduisent la même liste, deux nombres côte à côte obligeraient à les
  rapprocher soi-même.
- **Modification groupée** : cases à cocher + barre d'action
  (`_structures_bulk_bar.php`), et un bandeau d'annulation qui survit dix
  secondes à l'écran, Ctrl-Z au-delà.
- **Sur téléphone**, une liste devient des mini-cartes (`.liste-cartes`) : le
  `<thead>` disparaît, les entonnoirs sont repris par `_filtres_mobile.php`.
  Une cellule sans place assignée dans la grille se rangerait par-dessus le nom.

## 10. Formulaires

- **Un formulaire d'édition en ligne** utilise `.inline-edit` : une rangée flex,
  le champ principal en `.grow`, les boutons en `flex: 0 0 auto`. Il passe en
  colonne sous 700 px, sans règle à écrire.
- **Un formulaire à plusieurs champs** se pose en `.grid2` / `.grid3` — attention,
  `.card-block` les ramène à une colonne (elle vise les sous-sections d'une carte
  étroite). Quand les champs doivent se recomposer selon la largeur réelle plutôt
  qu'en colonnes fixes, une rangée flex où chacun déclare sa largeur souhaitée
  (`flex: 3 1 190px` pour une remarque, `0 0 108px` pour une heure) évite tout
  point de rupture codé en dur.
- **Pas de paragraphe de description au-dessus d'un champ** : le texte indicatif
  (`placeholder`) dit ce qu'on y met. Le dire deux fois allonge sans apprendre.
  Exception : une contrainte réelle qu'un `placeholder` ne peut pas porter
  (formats et taille d'un fichier) rejoint l'intitulé du champ.
- **Normaliser plutôt que refuser** quand c'est possible : « 20h30 » devient
  `20:30`, une saisie incompréhensible est vidée. Refuser bloquerait
  l'enregistrement du reste.
- Le bouton d'envoi termine la rangée quand il reste de la place.

## 11. Messages

| Classe | Usage |
| --- | --- |
| `.ok` | Confirmation |
| `.err` | Échec |
| `.warn` | Avertissement, sans échec |
| `.flash` | Pastille **flottante** en haut de page, effacée après 3 s |

`.flash` se combine aux trois premières. **Ne pas la ramener dans le flux** avec
une règle plus spécifique : c'est ce qui l'avait un jour enfermée dans le cadre
d'une carte.

Après un POST, rediriger avec un drapeau dans l'URL (`?ok=…`, `?err…`) plutôt
que d'afficher depuis le POST : un rechargement ne doit pas rejouer l'action.
`redirect()` accepte une **ancre** — sur une fiche longue, revenir en haut
oblige à redescendre.

## 12. Icônes

`icon('nom')` — Lucide, chemins recopiés à la main dans `icone_table()`
(`lib/helpers.php`). **Ne pas introduire une icône sans vérifier qu'elle
existe** : `icon()` rend une chaîne vide pour un nom inconnu, sans rien signaler.

Le vocabulaire est fixe :

| Icône | Sens |
| --- | --- |
| `pencil` | Modifier |
| `save` | Enregistrer |
| `x` | Annuler, retirer |
| `trash` | Supprimer — **toujours en rouge** (`btn danger`) |
| `plus` | Ajouter |
| `eye` | Consulter, aperçu |
| `printer` | Imprimer |
| `download` / `import` | Télécharger / importer |
| `chevron-up` / `chevron-down` | Déplacer dans une liste |
| `check` | Marquer comme fait |
| `mail` / `send` | Contacter / envoyer |
| `funnel` | Filtrer |
| `lock` | Jeton, secret |
| `info` | Bulle d'explication (`info_tip()`) |
| `rows-3` | Déroulé, gabarit de lignes |

## 13. Couleurs

Les tokens portent le **sens**, jamais la teinte, et se déclinent en `-d`
(survol) et `-tint` (fond).

| Token | Sens |
| --- | --- |
| `--ok` (teal) | Acquis, confirmé, intéressé |
| `--danger` | Destructeur, refusé |
| `--amber` | En attente, à faire |
| `--rose` | Contact privilégié |
| `--muted` | Secondaire, pas encore approché |
| `--primary` / `--highlight` | Accents, réglés par l'employeur |

Les mêmes couleurs doivent dire la même chose partout : un sélecteur de réponse,
les segments d'une jauge et sa légende se peignent depuis la même table
(`CAMPAGNE_REPONSES_CLASSES_ICONE`, `lib/booking.php`). Une couleur ne porte
jamais le sens **seule** : une icône ou un libellé l'accompagne.

Tout écran existe en thème clair **et** sombre : n'écrire aucune couleur en dur,
toujours un token.

## 14. Comportements déclaratifs

Le balisage **déclare l'intention**, le comportement vit dans `app.js`. Aucun
attribut `onclick` : c'est du script inline, que la CSP ne peut autoriser
qu'avec `'unsafe-inline'`.

**Un réglage qui n'engage que sa propre ligne n'a pas à recharger la page.**
`data-ajax` sur le formulaire suffit : l'envoi part en `fetch` avec
`retour=json`, la route répond `{"ok":true}` au lieu de rediriger, et si l'appel
échoue l'envoi classique reprend la main — la page montre alors l'état réel
plutôt que de laisser un interrupteur basculé à l'écran et pas en base.

Ce qui change à l'écran doit alors suivre **sans rendu** : une classe ou un
badge se pilotent depuis la case elle-même (`:has(.regle-actif-cb:checked)`),
jamais en réécrivant la ligne. Attention, `[hidden]` porte un `!important` dans
`app.css` : une règle CSS ne le lèvera pas, c'est la vue qui doit s'abstenir de
poser l'attribut.

**À l'inverse, on recharge** dès que le geste change autre chose que sa ligne :
un chiffre agrégé (l'impact d'une règle de lettrage), la navigation (activer un
module), ou un libellé repris ailleurs dans la page (renommer une étiquette).

| Attribut | Effet |
| --- | --- |
| `data-confirm="…"` | Demande confirmation (form : envoi ; bouton/lien : clic). Vide = ne demande rien |
| `data-print` | Imprime la page |
| `data-preview` | Ouvre la cible dans la fenêtre d'aperçu |
| `data-show` / `data-hide` | Affiche / masque l'élément d'id donné |
| `data-submit-on-change` | Envoie le formulaire porteur au changement |
| `data-ajax="<message>"` | Sur le **formulaire** : l'envoi part en arrière-plan au lieu de recharger la page ; le message s'affiche en pastille flottante |
| `data-submit-form="<id>"` | Envoie le formulaire désigné |
| `data-go-on-change="<préfixe>"` | Navigue vers préfixe + valeur |

## 15. Ce qui se vérifie avant de livrer un écran

- [ ] `php tests/run.php` passe.
- [ ] L'écran rendu **sans JavaScript** reste utilisable.
- [ ] Testé à **375 px** de large : rien ne déborde, aucune ligne inutilement
      coupée en deux.
- [ ] Thème **sombre** vérifié.
- [ ] Chaque bouton en icône seule a `title` **et** `aria-label`.
- [ ] Aucune action destructrice sans `data-confirm`, ni hors mode édition.
- [ ] Les tailles et positions sont **mesurées** dans le navigateur, pas
      supposées : `getBoundingClientRect()` dit ce qu'une capture d'écran laisse
      croire.
