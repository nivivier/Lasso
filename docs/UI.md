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

`.page-head` porte le titre de la page — sauf sur le tableau de bord, où le rail
dit déjà où l'on est : la barre de recherche y prend la place du titre, le
bouton d'organisation des cartes à sa droite.

**Un onglet peut porter des SOUS-ONGLETS** quand il recouvre deux écrans de la
même matière — le plan comptable, qui tient les produits et charges d'un côté
et les comptes bancaires de l'autre. Ils se déclarent en 5ᵉ élément de l'entrée
dans `nav_groupes()` (`[route => libellé]`), et la 2ᵉ — la liste des routes qui
allument l'onglet — doit tous les contenir. Le rendu réutilise la rangée
`.param-subtabs` des Paramètres, à la même place : deux niveaux d'onglets se
lisent partout de la même façon. **À préférer à un onglet de plus** dès que
deux écrans sont deux faces d'une même chose ; pas pour ranger ensemble ce qui
n'a en commun que d'être rarement ouvert.

**La rangée d'onglets se range à droite du titre quand elle y tient**, et ne
descend sur sa propre ligne que sinon : le bandeau fait alors une hauteur au
lieu de deux. Rien à déclarer — c'est le comportement de `.page-head-band`. La
décision se prend sur la largeur réelle, pas sur un seuil : aujourd'hui seule
la comptabilité, avec ses six onglets, reste sur deux lignes.

### Sur téléphone, la barre supérieure porte le module ET l'action

Sous 800 px le rail se replie et une barre fixe le remplace. Elle tient quatre
choses, dans cet ordre : **le burger, le logo réduit, le nom du module, l'action
principale de la page**. Le titre de la page (`.page-head-titre-module`)
s'efface alors — la barre le dit déjà une rangée plus haut, et cette rangée
rendue, c'est 34 px de contenu gagnés sur chaque écran.

- **Le nom du module** vient de `nav_groupes()` + `nav_groupe_actif()`, deux
  fonctions pures de `?p=` : le gabarit le connaît donc avant que la vue ne
  s'exécute. Les Paramètres ne sont pas un module mais sont bien une section :
  `parametres_groupes()` (`lib/modules.php`, la même liste que leurs onglets)
  dit si la route en fait partie, et la barre affiche alors « Paramètres ».
  ⚠️ Le `<h1>` ne s'efface que si la barre en a VRAIMENT un
  (`body.titre-dans-barre`), sinon l'écran n'aurait plus de nom. Et seul le
  `<h1>` qui nomme le MODULE porte cette classe : celui qui nomme un
  enregistrement (une campagne, un certificat) ne doublonne rien et reste.
- **L'action principale, c'est le bouton MIS EN ÉVIDENCE de la barre d'outils** —
  un `.btn` sans `.ghost`, dans `.toolbar > .head-actions`. Il y en a un par
  écran, ou aucun. Pas « le dernier », pas une liste à tenir à jour : la mise en
  évidence est déjà la façon dont l'application désigne l'action d'un écran, on
  la lit plutôt que de la redéclarer ailleurs. Dans la barre, le bouton garde sa
  couleur et perd son libellé (`.lbl`) : le nom du module juste à côté dit de
  quoi il s'agit. **Tout bouton de barre d'outils met donc son intitulé dans un
  `<span class="lbl">`** — sans quoi il entre dans la barre avec son texte et
  écrase le titre.
- **Le bouton est DÉPLACÉ, pas dupliqué.** Un second exemplaire se
  désynchroniserait du premier (lien, libellé, droits) au premier écran qui
  change le sien.
- ⚠️ **Toutes ces actions ont la MÊME boîte**, 36 px de côté, celle du burger à
  l'autre bout de la barre — posée explicitement, `min-height` comprise. Laissées
  à leur taille propre, elles divergent : le « + » du tableau de bord est un
  `<summary class="icon-only">` (41 × 43), les autres des `<a class="btn">`
  (38 × 32), et la barre changeait de hauteur d'un écran à l'autre.
- **Pas de trait d'accent sous elle.** Les 3 px de couleur vive qui ouvrent la
  page sur grand écran y disent le module ; sur téléphone la barre le dit déjà,
  juste au-dessus, et le trait se lisait comme un second soulignement collé
  sous elle.
- ⚠️ **C'est l'un des rares endroits qui exige du JavaScript**, et il vaut de
  savoir pourquoi : la barre est écrite avant la vue, donc le serveur ne connaît
  pas encore le bouton ; et le poser en CSS ne marche pas, parce que `.toolbar`
  ouvre un contexte d'empilement (`z-index: 2`) dont un enfant en
  `position: fixed` ne sort pas — il est peint SOUS la barre quel que soit son
  `z-index`. Essayé, mesuré. Sans script, le bouton reste à sa place d'origine
  et la page garde exactement le comportement qu'elle avait.

### Un écran qui EST un formulaire met aussi ses commandes en haut

Créer un employé, une fiche, une facture, un projet, une campagne, une
structure, une date : ces sept écrans n'ont rien à lire qu'un crayon ouvrirait —
ils sont un formulaire du premier champ au dernier. « Enregistrer » et
« Annuler » y tiennent donc l'en-tête de page, à la place où les cartes mettent
leur crayon, et **rien ne reste au pied du formulaire**. Le bouton vit hors du
`<form>` et le vise par `form="<id>"`.

Un seul appel les pose : `entete_form_actions_html($form, $retour, $opts)`
(`lib/helpers.php`). `$opts['avant']` reçoit la suppression quand l'écran la
propose — elle garde sa place, tout à gauche du trio ; `$opts['libelle']` dit ce
que le bouton fait vraiment quand « Enregistrer » serait faux (« Calculer et
créer la fiche »). `$retour` vide = pas d'« Annuler », pour un écran dont le lien
de retour est juste au-dessus (`?p=booking_campagne_form`).

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
| `?p=employe` | **Modifier** |
| `?p=booking_campagne` | **Modifier** |

### « Supprimer » n'est pas dans cette barre

**La suppression vit sur l'écran de modification**, pas sur celui qui consulte.
Un écran de consultation s'ouvre cent fois pour lire, imprimer, envoyer ; on ne
vient sur celui d'édition que pour toucher à l'objet. C'est la même règle que
pour les lignes de liste, où la corbeille n'apparaît qu'en mode édition (§ 3).

Elle s'y place **tout à droite** de l'en-tête (`?p=fiche_modifier`,
`?p=facture_form`, `?p=employe_form`, `?p=booking_campagne_form`), et jamais à la
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

Il ramène à **ce qu'on modifiait**, pas à la liste : `?p=fiche_modifier&id=X` revient
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

**Six portées, six mécanismes** — et rien au-delà : si un besoin n'entre dans
aucun, on élargit celui qui s'en approche plutôt que d'en poser un sixième.

| Portée | Mécanisme |
| --- | --- |
| La barre d'actions d'une page | `.entete-editable` (§ 1) |
| Une carte entière | `.card-edit-btn` (a) |
| Une section d'une carte | `.section-editable` (b) |
| Un bloc répété | `lassoInitBlocEdition()` (c) |
| Une ligne d'une liste ordonnable | `.plan-edit-btn` + `.editing` (d) |
| Une ligne d'un tableau, plusieurs champs | `lassoInitLigneEdition()` (e) |

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

⚠️ **Ce trio ne se réécrit pas à la main** : `carte_actions_html($opts)`
(`lib/helpers.php`) le rend, et lui seul — l'ordre des icônes, leurs classes et
leurs intitulés se décident là, une fois pour toute l'application. Options :
`form` (l'id du `<form>` visé, quand le bouton vit hors de lui), `petit`
(`.btn-sm`, pour une ligne), `quoi` (complète l'infobulle : « Modifier l'axe par
défaut »), `overlay` (boutons flottants), `classe`, `extra` (un bouton de plus,
posé entre enregistrer et la croix).

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
`?p=projets`, `?p=categories_structures`, `?p=pays`) :

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

⚠️ **La poignée et les deux boutons ne s'écrivent pas à la main** :
`plan_poignee_html()` et `plan_boutons_edition_html()` (`lib/helpers.php`),
comme `plan_puce_html()` pour la puce. Neuf vues recopiaient la poignée, huit
la paire crayon + croix, et la dérive s'y était logée : le même crayon disait
« Modifier » sur trois écrans et « Renommer » sur trois autres. **Son mot est
« Modifier »** — il n'ouvre pas un champ de nom, il ouvre le mode édition de la
ligne, qui découvre aussi la corbeille et tout ce qui porte `.cell-edition`.
L'argument de `plan_boutons_edition_html()` nomme la ligne pour les lecteurs
d'écran (« cet axe », « cette règle ») quand la colonne ne suffit pas.

### e. Une ligne de tableau à plusieurs champs — `lassoInitLigneEdition()`

Quand une ligne porte plusieurs champs mais ne se réordonne pas (les dossiers
d'une recherche de fonds, les prestations d'une date), ses deux états vivent
**dans ses cellules** : `.<prefixe>-disp` ce qui se lit, `.<prefixe>-editable`
ce qui se saisit, et le crayon `.<prefixe>-edit-btn` les échange. Une ligne n'a
pas, comme un bloc, un conteneur par état — d'où ce helper plutôt que
`lassoInitBlocEdition()`.

Par **délégation** sur une racine : une ligne ajoutée après coup s'ouvre sans
qu'on repose d'écouteur. `apres(tr)` sert à ce qui dépasse la ligne — ouvrir
une seconde rangée de détail, placer le curseur.

Les boutons et leur ordre restent ceux de 2d : enregistrer mis en évidence, la
croix à la place du crayon.

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
un bouton de taille normale y déforme la hauteur de la ligne. ⚠️ **Et seulement
là** : le petit format dit « je commande cette ligne ». Un bouton posé dans un
en-tête de section (`.section-head`) ou une barre d'outils commande la page, et
garde donc sa taille normale. `btn-sm` et
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
| Script de page (`?p=compta_axes`, `?p=compta_comptes`, `?p=taux_horaires`, `?p=utilisateurs`) | `hidden` posé au chargement, levé par le crayon — même résultat, sans le helper. Quand la liste s'allonge sans recharger (`?p=utilisateurs`), les écouteurs sont **délégués** au tableau, sinon la ligne neuve naît avec un crayon mort |

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
pays et régions, projets, déroulé d'un événement, cartes du tableau de bord.

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
  position verticale change le rang. `?p=compta_plan`, `?p=categories_structures`,
  `?p=projets`, `?p=pays`.
- **`lassoOrdreListe()`** — liste plate, même vocabulaire sans la hiérarchie.
  `?p=postes`, `?p=compta_regles`, le déroulé d'un événement (`?p=evenement`),
  les cartes du tableau de bord (`?p=tableau_bord`, panneau « Organiser les
  cartes »).

Cinq règles qui comptent :

1. **La poignée ouvre la ligne, tout à gauche**, avant toute autre chose —
   avant l'interrupteur d'une ligne qui s'allume, avant la puce d'une liste
   hiérarchique, avant le nom. C'est un repère de position : on doit la trouver
   sans la chercher, au même endroit sur les six listes qui se glissent, et une
   colonne qui la décale d'un écran à l'autre oblige à viser. Deux écrans y
   dérogeaient parce qu'ils ouvrent sur une colonne d'interrupteur
   (`?p=postes`, `?p=compta_regles`) : la poignée y est passée devant, dans la
   même cellule.

2. **Le dépôt poste, le serveur renumérote.** Le script renseigne un formulaire
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
3. **Il y a toujours un repli sans JavaScript** (`.plan-fallback`) : un menu
   « dans <parent> » avec son bouton d'enregistrement, ou des flèches. Le script
   pose `.dnd-on`, qui les masque — donc sans lui, ils restent là.
**Un tableau large sur téléphone a deux issues, et pas une troisième.** Soit il
se resserre et reste un tableau — on masque les colonnes secondaires, on en
replie une dans sa voisine (`.campagnes-table`, les deux listes de campagnes) ;
soit il se relit en cartes, chaque ligne passant en grille
(`.liste-cartes` + des classes `col-*` qui placent les cellules : la liste des
structures, les dossiers d'une campagne de recherche de fonds). Le choix tient
au nombre de colonnes : cinq se resserrent, douze ne se resserrent pas. Dans les
deux cas, **aucun second gabarit** — ce sont les mêmes cellules, repositionnées.

En cartes, attention aux lignes qui s'ouvrent en édition : les cellules masquées
emportent leurs champs. D'où la règle inverse — on ne masque pas la cellule, on
la replace —, et le libellé de colonne qui disparaît avec l'en-tête se repose
sur la cellule (`data-libelle` + `::before`), sans quoi deux champs voisins ne
disent plus lequel est lequel.

4. **Sur téléphone, la poignée s'efface** et les flèches de repli reprennent la
   main (`@media (max-width: 700px)`), pour **toutes** les listes. Deux raisons,
   et la seconde est dirimante : glisser au doigt dans une page qui défile est
   un combat perdu, et l'API employée — `dragstart`/`dragover`/`drop` — n'est
   de toute façon **jamais déclenchée par un doigt**, ni sur iOS ni sur Android.
   Sans cette règle, `.dnd-on` retire les flèches en échange d'une poignée qui
   ne fait rien, et réordonner devient impossible. C'est ce qui est arrivé au
   tableau de bord, le temps que la règle, écrite d'abord pour le déroulé d'un
   événement, soit étendue aux six listes.
5. **La position de défilement est mémorisée** (`sessionStorage`) avant l'envoi
   et restaurée au retour, `history.scrollRestoration = 'manual'`. Sans cela,
   déplacer la trentième ligne d'une liste renvoie en haut de page à chaque
   dépôt. Les deux helpers le font ; ailleurs, `?p=evenement` et
   `?p=import` le réimplémentent à la main, faute d'une liste ordonnable
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
| `user-plus` | Ajouter quelqu'un — employé, contact, compte |

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
`?p=booking_campagne` et la fiche d'une structure.

**Le champ y est cherchable, pas un menu déroulant** (`lassoInitCatSearch()`,
`.cat-search` + `.cat-search-input` + `.cat-search-val` + `.cat-search-list`) :
dès qu'une liste dépasse la dizaine, on sait ce qu'on cherche et l'on tape les
trois premières lettres. Liste **fermée** — la valeur cachée n'est remplie que
par une sélection, `clearHiddenOnInput` la vide à la frappe : on ne crée pas
l'entité depuis là. Un `<li data-val="__new__">` en tête ouvre le cas
contraire, quand la création est prévue (lier une salle depuis une structure).

**Le champ se branche par ses attributs, pas par un script dans la vue.** Poser
`data-cat-search` sur le `.cat-search` suffit ; les options se déclarent à côté :

| attribut | effet |
| --- | --- |
| `data-vider-en-saisie` | taper vide la valeur cachée — liste fermée |
| `data-texte-vide` | afficher le texte même pour une option de valeur vide (« — Aucun — ») |
| `data-hydrater` | pré-remplir le champ depuis la valeur cachée au chargement |
| `data-filtre-groupes` | masquer les en-têtes de groupe devenus vides |
| `data-revele="#id"` | montrer ce bloc quand `__new__` est choisi |

`lassoInitCatSearch()` reste appelable à la main, et c'est l'exception : deux
champs seulement en ont besoin, ceux qui chargent leur liste par `fetch` au
premier focus. Huit écrans avaient chacun leur variante de la même amorce ;
c'est précisément ce que la première règle de ce document interdit.

```html
<form class="linked-add">
  <input type="text" class="cat-search-input" placeholder="Rechercher une facture à lier…">
  <button type="submit" class="btn ghost" title="Lier" aria-label="Lier cette facture">
    🔗<span class="lbl"> Lier</span>
  </button>
</form>
```

Quand une liste recommence toujours par les mêmes entrées, les proposer en
**suggestions** sous le champ qui les reçoit (`<datalist>`) plutôt que de les
poser d'office : on garde la main sur ce qu'on écrit, et rien n'est à élaguer
ensuite.

## 6. Menus déroulants

⚠️ **Un menu déroulant s'écrit avec `menu_deroulant_html()`**
(`lib/helpers.php`), jamais à la main : un bouton qui déplie une courte liste de
gestes voisins. Trois écrans l'emploient — le « + » du déroulé d'une date,
« Charger un modèle » de la fenêtre Contacter, « Synchroniser » sur la liste des
projets. Un quatrième s'y branche plutôt que de redessiner le sien à côté.

```php
menu_deroulant_html(
    ['icone' => 'calendar-sync', 'libelle' => 'Synchroniser'],   // le bouton
    [['libelle' => 'Dates publiques (iCal)', 'icone' => 'calendar-sync',
      'classe' => 'export-copy', 'attrs' => ['data-url' => $url]]]  // les entrées
);
```

Un libellé vide donne un bouton à l'**icône seule** — c'est la forme à prendre
dans une ligne de tableau, où le menu se range avec les autres boutons
d'action ; le titre dit alors de quoi il s'agit. `'petit' => true` pour la
taille des boutons de ligne.

Le panneau s'aligne sur le bord **droit** du bouton (`'gauche' => true` pour
l'inverse) : un bouton de menu est d'ordinaire en bout de ligne, et un panneau
qui partirait vers la droite sortirait du cadre.

Un `<details>` natif ne se referme pas au clic dehors : l'écouteur global
d'`assets/app.js` s'en charge.

```js
const LASSO_MENUS = '.col-filter[open], .menu-deroulant[open], .dash-reglages[open]';
```

**Un nouveau menu s'ajoute à ce sélecteur** — on n'écrit pas un second
écouteur. Le test `details.contains(e.target)` est ce qui permet de cliquer une
entrée avant que le menu ne se referme.

**Un menu posé dans un conteneur qui rogne** — une cellule de tableau dans
`.table-scroll`, une fenêtre — passerait sous le bord de ce conteneur en
position absolue. `assets/app.js` le bascule alors en `position: fixed` et le
replace sous son bouton (en le retournant au-dessus s'il ne tient pas en
dessous). Il ne le fait **que là** : ailleurs, l'ancrage du CSS suffit et il a
l'avantage de suivre la page quand on la fait défiler.

**Une ligne cliquable doit laisser passer le menu** : le `<summary>` n'est ni un
`<a>` ni un `<button>`, et `views/layout.php` l'exclut nommément
(`.menu-deroulant`) — sans quoi ouvrir le menu navigue vers la fiche.

Une entrée est un **lien** quand elle mène quelque part (le déroulé d'une date
revient avec `?ajout=<type>`), un **bouton** quand elle se traite sur place
(charger un modèle, copier un lien de synchronisation). Même dessin dans les
deux cas. Une entrée qui accuse réception — « copié » — échange son **icône**
contre une coche et garde son libellé : le menu reste ouvert sous les yeux, et
une entrée qui perdrait son nom ne dirait plus ce qu'on vient de faire.

**Tout `<details>` n'est pas un menu.** Un **panneau de saisie** — « Plus de
filtres » (`.filters-more`), où l'on coche plusieurs cases avant d'envoyer —
reste ouvert : le refermer au premier clic à côté ferait perdre le travail en
cours. Il n'est donc pas dans `LASSO_MENUS`, et c'est voulu. La règle : un menu
où l'on choisit UNE entrée se referme au clic dehors ; un panneau où l'on
compose se referme quand on le décide.

## 7. Fenêtres

**Une seule fenêtre, un seul dessin.** Barre colorée en haut — titre à gauche,
les réglages d'entrée au milieu, « Fermer » à droite —, le contenu en dessous,
et au bas ce qui conclut. Un aperçu de document n'en est qu'un cas particulier :
même fenêtre, même barre, et pour seule différence un contenu qui est un
document posé sur son fond gris.

**« Fermer » s'écrit `bouton_fermer_modal_html()`**, jamais à la main : six
fenêtres l'avaient recopié à l'identique, à l'identifiant près, et la septième
aurait dérivé.

### Un bouton à l'étroit : réduire le mot, ou le texte

Deux traitements, et ils ne disent pas la même chose. Le choix se fait sur une
question : **l'icône seule suffit-elle à dire le geste ?**

- `.btn-compact-mobile` + `<span class="btn-txt">` : le libellé **part**, l'icône
  reste. Pour un geste dont le dessin est universel — la croix de « Fermer ».
- `.btn-compact` : le libellé **reste**, en plus petit, sur deux lignes s'il le
  faut, aligné à gauche sous l'icône. Pour un geste que son icône ne raconte
  pas : une flèche de téléchargement ne dit pas si l'on installe, réinstalle ou
  revient en arrière (`?p=maj`) ; un document ne dit pas qu'il *charge* un
  modèle (fenêtre « Contacter »).
- `.btn-compact-break` pose en plus un `<br>` à un endroit choisi : la coupure
  devient un choix de mise en page, et non un repli sous contrainte — le texte
  reste sur deux lignes même quand une seule suffirait. Dans un menu déroulant,
  il suffit d'un saut de ligne au milieu du libellé passé à
  `menu_deroulant_html()` : le helper le traduit en `<br>` coupé, et laisse
  l'infobulle d'un tenant.

⚠️ **Dans une barre de FENÊTRE, les deux font la même hauteur**, celle du
bouton à icône seule : ils s'y côtoient, et un voisin plus court trahit le
bricolage. Le libellé réduit se range DANS cette hauteur, sur une ou deux
lignes — c'est le remplissage vertical qui cède, pas le bouton qui rapetisse.
**Et seulement là** : ailleurs, un `.btn-compact` voisine des boutons de taille
normale, et cette hauteur-là le rendrait plus court qu'eux.

Les deux basculent au **même seuil** que tout ce qui se réduit sur un
téléphone. Un menu déroulant prend son traitement par l'option `classe` de
`menu_deroulant_html()` — pas par une règle écrite pour lui.

```html
<div class="modal-overlay" hidden>
  <div class="modal-card">
    <div class="modal-head">
      <span class="modal-titre">Supprimer « … »</span>
      <?= bouton_fermer_modal_html('…-annuler') ?>
    </div>
    …
    <div class="modal-actions">…</div>
  </div>
</div>
```

| | Quand | Quoi |
| --- | --- | --- |
| **Aperçu de document** | Montrer une feuille A4 : décompte, certificat, bilan, feuille de route | `<a href="?p=…_print" data-preview target="_blank">` — la fenêtre partagée de `views/layout.php` (`#preview-modal`) charge la page d'impression dans un cadre à la largeur d'une feuille |
| **Boîte de dialogue** | Poser une question, recueillir une saisie | `.modal-overlay` > `.modal-card` > `.modal-head` > … > `.modal-actions`, ouverte par `data-show="<id>"`, fermée par `data-hide="<id>"` |

La barre est **collante** : elle reste en tête quand le contenu défile. Son fond
vient de `--modal-head-bg` — la surface de marque en thème clair, une teinte
éclaircie en sombre, où la couleur de marque se confondrait avec la carte. Le
haut de `.modal-card` lui appartient : la carte n'a pas de padding en haut, et
une fenêtre sans barre perdrait donc sa respiration.

**Pas d'« Annuler » en bas.** Refermer est en haut, toujours au même endroit ;
le bas ne garde que ce qui conclut — enregistrer, envoyer, supprimer. Un dernier
réglage peut partager cette rangée, à gauche des boutons, quand il se décide
juste avant d'agir (« Contacter » : le projet où noter la prise de contact).

`data-preview` donne gratuitement Échap, le clic hors cadre, la barre d'outils
de la page cible et le bouton « Fermer » qui s'y ajoute. **Ne jamais
reconstruire cette fenêtre** : un aperçu de document passe par elle. Format `a4`
par défaut, `data-preview="ajuste"` pour un contenu qui n'est pas une feuille.

**« Fermer » est un bouton comme les autres, en fin de rangée.** La fenêtre
d'aperçu l'injecte à la fin de la `.print-toolbar` de la page affichée
(`views/layout.php`), poussé à droite par `.print-toolbar-fermer` — et non une
pastille ronde posée par-dessus le document. Il n'est pas écrit dans les vues
d'impression : elles s'ouvrent aussi seules, hors de la fenêtre, où il n'aurait
rien à fermer. Une pastille de repli reste prévue pour une page qui n'aurait pas
de barre d'outils, faute de quoi la fenêtre n'aurait aucune sortie visible.

Ne pas poser `data-hide` sur le fond d'une boîte de dialogue : un clic à
l'intérieur de la carte remonterait jusqu'à lui.

Un réglage qui appartient à l'entrée de la fenêtre monte dans la barre, entre le
titre et « Fermer » : le « Charger un modèle » de « Contacter »
(`_structure_contacter.php`) en est le seul exemple à ce jour.

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

**La barre reste en haut quand le document défile** (`position: sticky`) : c'est
le seul endroit d'où l'on imprime, télécharge ou referme, et la perdre au
premier coup de molette obligeait à remonter. Vrai dans la fenêtre d'aperçu
comme dans un onglet.

**Toutes ne s'impriment pas.** L'aperçu d'export SUISA
(`evenements_export_suisa_print.php`) utilise la même enveloppe pour montrer ce
qui partira en CSV : sa barre porte « Télécharger » et « Copier », pas
« Imprimer » — un tableau de dix-huit colonnes n'est pas fait pour le papier.
Une page d'impression sans bouton d'impression n'a donc pas besoin du script,
mais garde le thème clair et la feuille de style versionnée.

**Le logo suit le thème À L'ÉCRAN, jamais sur un document.** Une fiche de
salaire ou une facture consultée en thème sombre montre la variante pour fond
sombre : les deux images sont rendues, le CSS montre la bonne
(`.ps-logo-clair` / `.ps-logo-sombre`, même mécanique que le rail). Une page
d'impression force `data-theme="clair"`, ce qui neutralise la bascule — sa
feuille est blanche. Et un corps partagé avec l'e-mail (`_fiche_body.php`)
n'émet qu'une image quand l'appelant lui passe un `$logo_src` : chez le
destinataire, les règles de thème de l'application n'ont aucun sens.

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

- ⚠️ **Un tableau posé dans une carte la REMPLIT, bord à bord.** Pas de marge
  latérale : un tableau arrêté à quelques pixels des bords laisse ses
  séparateurs de lignes flotter dans le vide, et la carte semble contenir deux
  cadres emboîtés. Le tableau annule le retrait de la carte d'une marge
  négative, et repose ce même retrait (`--card-pad`) sur sa première et sa
  dernière cellule : le contenu reste ainsi aligné sur le titre du cadre —
  exactement le procédé d'une liste pleine largeur, à l'échelle d'une carte.
  Quand il **ferme** la carte, il la ferme vraiment : pas de bande vide sous sa
  dernière ligne, et les coins du bas prennent l'arrondi de la carte. Et quand
  il l'**ouvre** — une carte qui porte son titre au-dessus d'elle plutôt que
  dedans (`?p=pays`, `?p=categories_structures`, `?p=tags`) —, il l'ouvre de
  même : pas de bande vide au-dessus de sa première ligne, qui se lisait comme
  une rangée sans contenu. Trois bords tirés à fleur et le quatrième laissé en
  arrière est pire que les quatre en retrait.
  ⚠️ **Et le retrait latéral suit le titre.** Les 26px reposés sur les cellules
  de bord servent à les aligner sur le titre INTÉRIEUR du cadre. Une carte dont
  la liste est tout le contenu — titre posé au-dessus d'elle — n'a rien à quoi
  s'aligner : ses cellules de bord reprennent alors le retrait de n'importe
  quelle autre cellule, et la carte n'a plus de cas particulier du tout.
  ⚠️ C'est le padding des CELLULES qu'on corrige, surtout pas `--card-pad` :
  `.card` porte ses 26 px en dur, et `--card-pad` sert justement à les annuler
  d'une marge négative pour que le tableau affleure. Redéfinir la variable ne
  déplace pas le bord de la carte — il cesse seulement d'être annulé, et le
  tableau se met à flotter à 15 px de ses bords, séparateurs suspendus dans le
  vide. Essayé, mesuré.
  **Rien à déclarer** : c'est le comportement de tout `.list` dans un `.card`,
  avec ou sans conteneur `.table-scroll`. Les deux pièges qui y faisaient
  échapper un écran : porter `.card` et `.table-scroll` sur le **même** élément
  (le tableau prend alors le retrait de la PAGE et déborde du cadre — mettre le
  conteneur de défilement DANS la carte), et calculer un retrait sur
  `--content-pad` (celui de la page, qui change en mobile) au lieu de
  `--card-pad`. Un tableau de clé-valeur (`.kv-table`) n'est pas concerné : ce
  n'est pas une liste, il se lit comme du texte et garde le retrait de la carte.
- ⚠️ **Une bande d'onglets qui défile garde sa marge de gauche.** Les onglets et
  sous-onglets passent sur une seule ligne défilante sous 800 px, avec un
  accrochage (`scroll-snap-align: start`). Or `start` aligne sur le début du
  *scrollport*, qui est le bord de la boîte de REMPLISSAGE : la bande s'accroche
  et le retrait de gauche passe sous le bord, pastilles collées à l'écran — mais
  seulement quand la bande déborde, donc invisible sur les sections courtes.
  `scroll-padding-left` de la même valeur que le retrait décale le scrollport et
  la marge tient.
- **Le séparateur de groupe d'une liste** — le mois, l'année, l'étape — écrit
  son nom GRAND et MAIGRE, en bas-de-casse, au-dessus d'un filet d'un cheveu :
  c'est l'air au-dessus de lui qui sépare, pas un trait. La rangée est en
  VERRE (`--glass` + le flou des cartes du tableau de bord), donc le décor de
  la page se voit au travers.
  ⚠️ C'est pour cela que **`.module-content` ne porte plus d'aplat blanc** : un
  fond translucide ne montre que ce qui est peint DERRIÈRE lui, et un bloc
  opaque ne montrait que lui-même. Le blanc est posé morceau par morceau, sur
  la barre d'outils, les rangées du tableau et la pagination — jamais sur le
  bloc ni sur le `<table>`, sinon le verre se recompose dessus et l'effet
  disparaît sans rien signaler. Le survol, lui, ne touche pas un séparateur :
  ce n'est pas une ligne qu'on ouvre, et le teinter crèverait son verre.
- ⚠️ **C'est la dernière rangée MONTRÉE qui ferme une liste, jamais le
  tableau.** Un `.list` pleine largeur ne porte pas de `border-bottom` : chaque
  rangée garde le sien, et celle qu'on voit en dernier referme la liste d'un
  trait d'un pixel, comme tous les autres. La raison tient à la pagination
  **côté client** (`pagination_mode_client()`) : toutes les rangées sont dans
  le document et le script cache celles des autres pages, si bien que la
  dernière visible n'est pas `:last-child` — elle gardait son filet, celui du
  tableau s'y ajoutait, et la liste finissait sur 2px (`?p=structures`,
  `?p=evenements`). Aucun sélecteur CSS ne peut désigner « la dernière rangée
  affichée » ; c'est pourquoi la règle s'inverse. Plus généralement : **aucun
  trait épais dans un tableau** — un filet deux fois plus lourd que ceux qui
  séparent les lignes se lit comme une erreur, jamais comme une structure.
- ⚠️ **Dans un bloc de module, une liste n'est pas une carte.** Porter `.card`
  sur l'élément qui EST la zone de défilement (`.card.table-scroll`) dans un
  `.module-content` tire la carte bord à bord par la marge négative de la page,
  et il faut ensuite lui reprendre un par un ses traits latéraux, ses coins
  arrondis et son filet du bas — trois rattrapages pour une carte qui n'en est
  plus une, et le filet du bas se doublait avec celui de la dernière rangée.
  Deux formes, deux écritures : une liste bord à bord se passe de `.card`
  (`?p=projets`, `?p=compta_regles`) ; une vraie carte met le conteneur de
  défilement DEDANS.
- **La pagination touche son tableau** : aucune marge entre les deux — la
  séparation est déjà faite par le filet de la dernière rangée. L'air se met
  DANS la pagination, en remplissage haut et bas, pour que son fond blanc
  l'accompagne plutôt que de laisser une bande de décor s'intercaler.
- ⚠️ **Le retrait d'une cellule de liste, c'est `--row-pad`, et rien d'autre.**
  Trois écrans s'en écartaient — les écritures à 5px, les listes ordonnées à
  2px, le compte d'exploitation à 5px —, chacun pour une raison oubliée, et une
  liste finissait par changer de densité d'un écran à l'autre. Un écran dont
  les lignes doivent respirer autrement se trompe de problème : la densité est
  un RÉGLAGE, `?p=apparence` → Densité des tableaux, qui fait varier
  `--row-pad-y` sur tout le site (`:root[data-densite]`). Elle ne touche qu'au
  vertical : l'horizontal aligne le texte sur le bord de sa carte ou de sa
  page, et le faire varier décalerait les colonnes d'un réglage à l'autre.
- **Tri** : `tri_entete_html()`, trois états au clic — croissant, décroissant,
  retour à l'ordre par défaut. Le tri se fait en SQL (les listes sont paginées)
  et se mémorise en session. Le sens s'applique à **chaque terme** de l'`ORDER
  BY`, sinon « nom, prénom DESC » ne renverse que les prénoms.
- **Filtres de colonne** : `filtre_colonne_html()`, un entonnoir par colonne,
  mémorisé en session, avec un bouton de remise à zéro quand au moins un est
  actif.
- **Un bouton d'en-tête de colonne qui agit sur TOUTE la colonne** se range à
  côté du titre, avec la discrétion de l'entonnoir voisin (`.col-th-btn`) : il
  accompagne le titre, il ne le concurrence pas, et il s'allume en couleur
  primaire tant que son effet est en cours. Il **lit l'état réel des cellules**
  plutôt que de tenir son propre compteur — sinon une cellule qu'on a ouverte à
  la main le désynchronise, et le clic suivant ne fait rien de ce qu'on attend.
  Un seul écran s'en sert pour l'instant : « tout détailler / tout résumer » le
  libellé des écritures (`?p=compta_ecritures`).
- ⚠️ **Les liens de tri reportent les filtres actifs, mais ce report ne pilote
  rien.** Un filtre de colonne est un `filtre_coche()` : sans son marqueur
  `<clé>_set`, il ignore l'URL et retombe sur la **session**. C'est donc la
  session — et elle seule — qui fait survivre un entonnoir au clic sur un
  en-tête ; les valeurs qu'on lit dans l'URL d'une liste triée n'en sont que le
  reflet, utile parce qu'il dit ce que la page montre. Ne pas en conclure qu'un
  lien écrit à la main avec ces mêmes paramètres filtrerait quoi que ce soit :
  il faut `lien_liste_filtree()` (voir ci-dessous). L'erreur a été faite dans un
  commentaire avant de l'être dans un lien.
- ⚠️ **Un lien vers une liste filtrée s'écrit avec `lien_liste_filtree()`**,
  jamais à la main. Ces filtres sont des `filtre_coche()` : ils ne lisent l'URL
  que si le marqueur `<clé>_set` l'accompagne, et retombent sinon sur la
  session. `?p=evenements&statut=annule` n'a donc aucun effet — la page
  s'ouvre et montre ce qu'on avait laissé la dernière fois, sans rien signaler.
  Le helper pose aussi les filtres qu'on veut **vider** (un tableau vide), seule
  façon de garantir que le compte annoncé sur la carte et la liste ouverte
  portent sur les mêmes lignes.
- **Recherche** : instantanée sur les lignes affichées sous le seuil de
  pagination client (`pagination_mode_client()`), sinon envoyée au serveur.
- **Une liste courte d'événements se rend par `evenement_mini_html()`**
  (`lib/evenements.php`) : la date en pastille d'agenda (jour, mois, année),
  l'artiste en petit — `Artiste › Projet`, le chevron de `projet_chemin()`
  —, la VILLE en grand parce que c'est elle qu'on cherche des yeux, la salle en
  dessous, et le statut à droite (icône au-dessus du mot, largeur
  commune calée sur le plus long libellé pour que la colonne ne zigzague pas).
  Trois écrans l'appellent : la carte « Événements » d'une structure, la carte
  « Prochains événements » du tableau de bord, la liste des événements sur
  téléphone. Un quatrième se branche dessus, il ne se redessine pas à côté.
- **Une carte du tableau de bord met en valeur ce qui attend un geste** : la
  ligne se détache sur un fond ambre très clair (`.ligne-action`) — une fiche à
  verser dont le mois est passé, une facture échue, une campagne ouverte, la
  ligne « À faire ». Ce qui suit son cours reste à l'encre. C'est la seule
  couleur d'une carte ; elle a remplacé les médaillons d'alerte posés sur les
  titres, qui annonçaient en chiffre ce que les lignes montraient déjà.
  **Un total n'est jamais mis en valeur** : il ne demande rien. Il se fond dans
  la carte comme son titre — aucun fond —, un simple filet le séparant des
  lignes qu'il additionne. Le tableau s'ouvre et se ferme sur des filets de la
  même épaisseur que ceux qui séparent ses lignes.
- **Le titre d'une carte du tableau de bord mène à son module** : la carte est
  un aperçu, son titre est la porte. L'adresse vient de `nav_groupe_accueil()`,
  jamais écrite dans la vue — c'est celle où l'icône du rail mène déjà, et elle
  suit un onglet renommé. Le titre garde son allure et ne se signale qu'au
  survol, comme un en-tête de colonne triable (§ 9, `.col-tri`) : c'est la
  carte qui mène ailleurs, pas un lien posé dedans.
- **Une carte du tableau de bord ne s'étire pas** : elle montre ce qui demande
  du travail et referme le reste sur une dernière ligne « et X autres », qui
  mène à la liste complète (`$dash_reste()` dans `views/tableau_bord.php`, posée
  DANS le tableau). Un suffixe dit ce que sont ces autres quand ils ne sont pas
  de la même espèce que les lignes montrées — « et 2 autres à venir » sous les
  seules campagnes en cours. Le total d'un pied de carte porte alors sur TOUT,
  pas sur les lignes visibles : c'est cette ligne qui rend l'écart lisible.
- **Largeur des colonnes** : un tableau en disposition automatique répartit la
  place entre toutes ses colonnes, y compris celles qui n'en demandent pas.
  Serrer les colonnes courtes sur leur contenu (`width: 1%` + `white-space:
  nowrap`) laisse toute la place à celle qui en a besoin. Poser ces classes **à
  la main dans le balisage**, jamais par `:nth-child` : une colonne
  conditionnelle — « Axe », absente sans le module analytique — décale tout
  décompte de position.
- **Un tableau que le téléphone ne peut pas montrer se relit en blocs.** Quand
  les colonnes sont trop nombreuses pour tenir (les huit droits de
  `?p=utilisateurs`), les réduire ne les rend pas lisibles : sous une largeur donnée,
  `thead` disparaît, `tr` et `td` passent en `display: block`, et chaque cellule
  porte son intitulé par `data-label` + `::before`. Même balisage, même contenu
  — seule la forme change. Attention aux largeurs de colonne déclarées par
  `:nth-last-child()` : plus spécifiques que `.table td`, elles survivent au
  passage en blocs si on ne les annule pas.
- **Un seul compteur**, quelle qu'en soit la cause : filtres et recherche
  réduisent la même liste, deux nombres côte à côte obligeraient à les
  rapprocher soi-même.
- **Modification groupée** : cases à cocher + barre d'action
  (`_structures_bulk_bar.php`), et un bandeau d'annulation qui survit dix
  secondes à l'écran, Ctrl-Z au-delà.
- ⚠️ **Sur téléphone**, une liste devient des mini-cartes (`.liste-cartes`) : le
  `<thead>` disparaît, les entonnoirs sont repris par `_filtres_mobile.php`.
  Une cellule sans place assignée dans la grille se rangerait par-dessus le nom.
  **Le panneau n'est pas un agrément : il est la contrepartie obligée du
  `<thead>` masqué.** Une liste en mini-cartes qui porte un entonnoir en
  en-tête et pas de bouton « Filtres » rend ce filtre inatteignable depuis un
  téléphone — et c'est invisible au développement, qui se fait au large.
  `?p=employes` est resté dans cet état : on ne pouvait pas y afficher les
  employés inactifs. Le panneau prend les mêmes filtres, **avec leur libellé**
  (hors tableau, aucun en-tête ne les nomme) et porte le compte des filtres
  actifs. **Il se pose par le partiel, jamais par une copie** : `?p=evenements`
  avait le sien réécrit à la main, et c'est le compte qui lui manquait — panneau
  fermé, rien ne disait qu'on ne regardait pas tous les événements. Les libellés et les paramètres reportés se posent **une fois** en tête
  de vue, puis servent à l'entonnoir d'en-tête comme à celui du panneau : les
  écrire deux fois, c'est les voir diverger. Le bouton suit la recherche — sans
  rien à filtrer, ni l'un ni l'autre n'a de raison d'être là —, sauf quand un
  filtre est actif, sinon on ne pourrait plus le retirer.

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
- **Un groupe de cases à cocher ne se met JAMAIS dans un `<label>`.** Cliquer un
  libellé active le premier contrôle qu'il contient : pour `choix_coches_html()`,
  c'est la case « Tout », qui coche alors toute la liste — y compris quand on
  clique le libellé du champ ou le vide à côté du bouton. Utiliser
  `<div class="field-group">`, qui a la même apparence sans être un label.
- **Un champ dont la valeur est courte n'occupe pas toute la largeur** :
  `.champ-court` (moitié de la largeur, plancher à 155 px) pour une date. Sur une
  carte dont les boutons flottent en superposition (`.card-actions-overlay`),
  c'est aussi ce qui les empêche de se poser sur le champ.

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
| `check` / `circle-check` | ⚠️ **Un geste ACCOMPLI, jamais un état.** Marquer comme fait, un envoi parti, une date confirmée, un seuil franchi. Une structure « active » ne porte donc pas de coche — elle n'a rien accompli, elle est dans un certain état : `circle-dot` |
| `circle-dot` | Un état qui compte, sans qu'il se soit rien passé |
| `circle-dashed` | Un état en pointillé : inactif, mis de côté |
| `circle-x` | Un état qui ferme : ne pas contacter, refusé |

⚠️ **Une icône rendue en MASQUE CSS ne se voit pas dans le balisage.** La
colonne « Statut » de `?p=structures` dessine ses quatre états par
`--ico-m-*` (`assets/app.css`), pour ne pas écrire six mille `<svg>` dans une
page : changer `icone_table()` seul n'y change rien. Les deux doivent bouger
ensemble — et un tracé plein (un point, un cœur) y a besoin de son `fill`, un
masque ne gardant que l'alpha du dessin.
| `mail` / `send` | Contacter / envoyer |
| `funnel` | Filtrer |
| `external-link` | Ouvrir un AUTRE site, dans un onglet à lui (`bouton_formulaire_contact_html()`) |
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
| `--amber` | **Un geste à faire**, et rien d'autre |
| `--amber-piste` | Le même sens, en fond : la part d'une jauge qui reste à faire |
| `--rose` | Contact privilégié |
| `--muted` | Secondaire, en attente d'autrui, déjà traité de notre côté |
| `--primary` / `--highlight` | Accents, réglés par l'employeur |

**L'ambre dit un geste qui nous revient** — une fiche à verser, une facture
échue, une déclaration SUISA, un bailleur à solliciter. Il ne dit pas
« en attente » : attendre la réponse de quelqu'un n'est pas une tâche, c'est du
gris (`--muted`). La règle se vérifie vite — si l'utilisateur ne peut rien
faire de la chose colorée, elle n'est pas ambre.

Les mêmes couleurs doivent dire la même chose partout : un sélecteur de réponse,
les segments d'une jauge et sa légende se peignent depuis la même table
(`CAMPAGNE_REPONSES_CLASSES_ICONE`, `lib/booking.php`). Une couleur ne porte
jamais le sens **seule** : une icône ou un libellé l'accompagne.

Dans une jauge, deux registres se répondent : les **segments sont pleins** (ce
qui est traité), la **piste est pâle** (ce qui ne l'est pas). D'où
`--amber-piste` plutôt que `--amber` en fond : un ambre plein donnait une barre
qui semblait remplie, c'est-à-dire l'inverse de ce qu'elle dit.

Tout écran existe en thème clair **et** sombre : n'écrire aucune couleur en dur,
toujours un token.

**Le gras suffit à désigner ce qui demande un geste.** Sur une ligne déjà
cliquable en entier, la teinter en plus n'apprend rien : `.list .strong-encre`
met en gras sans prendre la couleur d'accent — le nom d'une campagne en cours,
la ligne « À faire » de la carte Suisa. `.list .strong` (gras + teal) reste pour
les chiffres d'un tableau.

## 13 bis. Tailles de texte

**Une taille de texte est un token, jamais une valeur.** Sept crans, et rien
entre eux — aucune règle de la feuille n'écrit un nombre de pixels :

| Token | Taille | Pour |
| --- | --- | --- |
| `--fs-tiny` | 9px | Mentions d'une mini-carte, pastilles |
| `--fs-small` | 11px | Libellés, légendes, intitulés de colonne |
| `--fs-basic` | 13px | Le corps de l'application |
| `--fs-large` | 16px | Le nom que porte une ligne, un titre de carte |
| `--fs-big` | 20px | Un séparateur de groupe, un montant mis en avant |
| `--fs-bigger` | 24px | Une date d'en-tête, des initiales en médaillon |
| `--fs-huge` | 32px | Le chiffre d'un en-tête |

C'est la même règle que pour les couleurs (§ 13), et pour la même raison : une
valeur écrite en dur dit la même chose que le token le jour où on l'écrit, et
plus la même le jour où le token bouge — sans que rien ne le signale.

⚠️ **Le piège n'est pas la valeur inconnue, c'est la valeur JUSTE.** Écrire
`font-size: 11px` là où `--fs-small` vaut 11px ne se voit pas : l'écran est
correct, il a seulement cessé de suivre l'échelle. Douze déclarations étaient
dans cet état.

⚠️ **Et un écran qui tombe entre deux crans ne les écarte pas : il prend le
plus proche, ou il fait un cran.** Les mini-cartes de téléphone s'étaient
réglées à l'œil — 10px et 12px, onze fois, entre `--fs-tiny` et `--fs-basic` —
et ces écrans vivaient leur propre vie, invisible au développement qui se fait
au large. Un corps intermédiaire n'est pas un besoin : c'est le signe qu'on n'a
pas tranché. **Si le besoin est réel, il devient un CRAN**, déclaré en tête de
feuille avec les autres — c'est ainsi que `--fs-bigger` est né : l'échelle
sautait de 20 à 32, soixante pour cent d'un coup, et les corps d'affichage qui
tombaient dans ce trou n'avaient nulle part où aller.

⚠️ **À égalité, on monte.** Un corps qui tombe à mi-chemin de deux crans (18
entre 16 et 20, 22 entre 20 et 24) est presque toujours là pour RESSORTIR —
un montant à côté d'un nom, un chiffre, une date. Descendre est la direction
qui efface la distinction qu'il portait : le montant d'une facture tombé à 16
aurait pesé exactement le poids du nom posé à côté de lui. Seule exception,
ce qui est secondaire par nature : la légende d'un graphique prend le cran du
dessous.

Deux tailles séparées de deux pixels ne se distinguent sur aucune capture ;
c'est bien pourquoi l'échelle se dissout sans qu'on la voie partir. Avant la
réforme : 23 valeurs distinctes de 9 à 32.

`tests/tailles_texte_test.php` refuse toute taille écrite en pixels — qu'elle
double un token ou qu'elle tombe entre deux —, la même chose en `em`/`rem`
(changer d'unité contournerait l'échelle), et vérifie qu'aucun `var(--fs-…)`
ne pointe un token inexistant : cette faute-là donne une règle valide qui ne
dessine rien, et l'écart se voit à peine.

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
- [ ] Une liste qui passe en mini-cartes a son bouton « Filtres » (§ 9) : le
      `<thead>` masqué emporte sinon ses entonnoirs, et le filtre devient
      inatteignable sur téléphone sans que rien ne le signale.
- [ ] Thème **sombre** vérifié.
- [ ] Aucune couleur (§ 13) ni **taille de texte** (§ 13 bis) écrite en dur :
      toujours un token. `tests/tailles_texte_test.php` le vérifie pour les
      tailles.
- [ ] Chaque bouton en icône seule a `title` **et** `aria-label`.
- [ ] Aucun bouton d'enregistrement au pied d'une carte ou d'une page : il est en
      haut à droite, rendu par `carte_actions_html()` (§ 2a) ou
      `entete_form_actions_html()` (§ 1), jamais réécrit à la main.
- [ ] Aucun `<label>` n'enveloppe un groupe de cases à cocher (il en coche la
      première au moindre clic dans le libellé).
- [ ] Aucune action destructrice sans `data-confirm`, ni hors mode édition.
- [ ] Les tailles et positions sont **mesurées** dans le navigateur, pas
      supposées : `getBoundingClientRect()` dit ce qu'une capture d'écran laisse
      croire.
