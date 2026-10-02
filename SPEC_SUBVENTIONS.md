# Spécification — Module Recherche de fonds (subventions)

Statut : **cadrage complet, prêt pour l'implémentation** (01.10.2026). Toutes
les questions ouvertes ont reçu une réponse (§ 9) ; le plan du § 8 devient la
suite des commits. Ce document reste le « pourquoi » — en cas de désaccord avec
le code livré, c'est le code qui fait foi.

## 1. Objectif

Suivre les **demandes de subvention et les recherches de fonds** : à qui l'on
demande, pour quel projet, combien, où en est le dossier, et ce qu'on a obtenu.

Le besoin est celui d'une **campagne de booking transposée à l'argent** : une
sélection d'interlocuteurs, une période, un avancement qu'on regarde monter.
Deux différences commandent toute la conception :

- ce qu'on suit par ligne n'est pas une réponse à trois valeurs mais un
  **dossier** : un montant demandé, un montant accordé, des dates ;
- ce qui presse n'est pas « rappeler ceux qu'on n'a pas contactés » mais une
  **date limite de dépôt** — un bailleur manqué l'est pour un an.

## 2. Ce qui est déjà là, et qu'on ne réécrit pas

| Besoin | Ce que l'application a déjà |
| --- | --- |
| Fiche d'un bailleur (adresse, contacts, notes, historique) | `structures` + `structure_contacts` + `historique` — la Facturation s'en sert déjà sans dépendre du Booking |
| Écrire à un interlocuteur, garder trace | « Contacter » (`_structure_contacter.php`), `historique` type `mailing` |
| Passer par le formulaire d'un site qui n'expose pas d'adresse | `bouton_formulaire_contact_html()` + `structure_formulaire_sql()` — fréquent chez les bailleurs, qui veulent leur propre guichet |
| Sélectionner des interlocuteurs par filtres | `_structures_table.php`, `_structures_filtres.php`, `criteres` d'une campagne |
| Rattacher à un ou plusieurs projets | `spectacles` + table de liaison (`SPECTACLES_LIAISONS`) |
| Avancement en barre segmentée | `campagne_barre_html()` + `campagne_repartition()` |
| Carte de tableau de bord, mise en valeur de ce qui attend un geste | `.ligne-action`, `$dash_reste()`, `campagnes_dashboard()` |
| Étiquettes, import CSV, fusion de doublons, carte | tout le CRM du Booking |

**Un bailleur est donc une `structure`**, pas une table nouvelle : une commune
qui subventionne peut aussi programmer, et la tenir en double obligerait à
saisir deux fois la même adresse — c'est exactement l'erreur que la fusion
`debiteurs` → `structures` a corrigée (voir `SPEC_FACTURATION.md § 3`).

## 3. Ce qui est nouveau

Trois tables.

> **Tranché le 01.10.2026** — un bailleur est bien une `structure`, et rien ne
> le distingue dans la liste pour l'instant : on verra à l'usage si le besoin
> d'un filtre apparaît. Le **projet visé est un `spectacle`**, l'entité du
> module Événements, dont le libellé se renomme déjà pour toute l'application
> (`?p=evenements_reglages` → « Terme pour une série d'événements » ;
> `evenements_terme_spectacle()`). Les écrans de ce module emploient donc ce
> terme-là, jamais « spectacle » en dur — si l'association l'appelle « projet »,
> tout suit (voir la question 3 bis).

### `fonds_campagnes` — une recherche de fonds

| champ | notes |
|---|---|
| `id` | |
| `nom` | « Création 2027 », « Fonctionnement 2027 » |
| `date_debut`, `date_fin` | la période de la recherche, comme une campagne de booking |
| `montant_minimal` | le plancher : en dessous, le projet ne se fait pas. C'est le repère de la jauge (§ 5) |
| `montant_ideal` | ce qu'il faudrait pour faire le projet comme on le voudrait. C'est la longueur de la jauge (§ 5) |
| `criteres` | la sélection de bailleurs, même format qu'une campagne |
| `axe_analytique_id` | FK nullable → `axes_analytiques` : **celui du projet financé** (tranché le 01.10.2026), pré-rempli à la création et modifiable. C'est lui que porteront la facture et les écritures de cette recherche (§ 3 ter) |
| `drive_url` | **le dossier externe** où vivent toutes les pièces de cette recherche — budgets, lettres, décisions, bilans. Un lien, pas un dépôt de fichiers (§ 3 quater) |
| `notes`, `cree_le` | |

Projets visés : table de liaison `fonds_campagne_spectacles`, sur le modèle de
`campagne_spectacles` — un `spectacle` au sens du module Événements, c'est-à-dire
ce que l'association appelle un projet.

### `fonds_demandes` — un dossier chez un bailleur

| champ | notes |
|---|---|
| `id` | |
| `campagne_id` | FK → `fonds_campagnes` |
| `structure_id` | FK → `structures` — le bailleur |
| `statut` | voir § 4 |
| `montant_demande`, `montant_accorde` | figés à la saisie, jamais recalculés |
| `date_limite` | **la date de dépôt imposée par le bailleur** — propre à CETTE campagne : le même bailleur n'a pas le même délai d'une année à l'autre. C'est elle qui pilote les alertes |
| `date_depot`, `date_reponse` | |
| `date_limite_bilan` | la date à laquelle le **bilan** est dû — l'autre échéance, celle qu'on oublie une fois l'argent reçu |
| `date_bilan` | quand il a été transmis |
| `pieces_autres` | les pièces hors catalogue réclamées pour CETTE demande (texte libre) |
| `facture_id` | FK nullable → `factures` : certains bailleurs veulent une facture (§ 3 ter) |
| `reference` | numéro de dossier chez le bailleur |
| `notes`, `cree_le` | |

**Une demande par (campagne, bailleur)**, garanti par un index unique : deux
guichets chez le même bailleur dans la même recherche, ce n'est pas le besoin.
Et `campagne_id` est **obligatoire** — une sollicitation ponctuelle hors
campagne n'existe pas non plus ; au pire, on ouvre une recherche d'une ligne.

### `fonds_pieces` et `fonds_bailleur_pieces` — ce que le bailleur exige

**Tranché le 01.10.2026 : les pièces appartiennent au BAILLEUR, pas à la
campagne.** La Loterie Romande demande les mêmes documents d'une année sur
l'autre ; les redemander à chaque campagne serait de la ressaisie.

Un catalogue et une liaison, exactement comme les étiquettes
(`structure_tags` / `structure_tag_liens`) et les catégories — c'est le motif
déjà en place pour « une liste courte, commune, qu'on coche ».

| `fonds_pieces` | |
|---|---|
| `id`, `nom`, `ordre` | semé avec les trois plus courantes : **Rapport d'activité**, **Bilan financier du projet**, **Comptes vérifiés**. Une quatrième s'ajoute alors sans migration. |

| `fonds_bailleur_pieces` | |
|---|---|
| `structure_id` | le bailleur |
| `piece_id` | FK → `fonds_pieces` |
| `moment` | `demande` ou `bilan` — « Comptes vérifiés » se demande souvent aux deux, et ce n'est pas la même liste à préparer |

Le texte libre « Autres » vit en deux endroits, et c'est voulu : sur le bailleur
(`structures.fonds_pieces_autres`) pour ce qu'il réclame toujours, et sur la
demande (`fonds_demandes.pieces_autres`) pour ce qu'il a réclamé cette fois-là.

> Une **case à cocher par pièce** sur l'écran d'une demande dit ce qui manque,
> donc ce qui reste à faire avant le dépôt. La liste cochée est celle du
> bailleur ; ce qui est **fourni** se suit sur la demande (question 8 quater).

### `fonds_versements` — l'argent qui arrive, en une fois ou en plusieurs

Rare mais possible : un octroi versé en acompte puis solde, parfois sur deux
exercices. Une table plutôt que deux colonnes de plus, parce que le nombre de
versements n'est pas connu d'avance et qu'il faut rapprocher **chacun** de son
écriture bancaire.

| champ | notes |
|---|---|
| `id` | |
| `demande_id` | FK → `fonds_demandes` |
| `montant` | |
| `date_prevue`, `date_recue` | |
| `ecriture_id` | FK nullable → `ecritures` — le rapprochement, comme une facture |
| `notes` | |

Un octroi versé en une fois reste **un** versement : pas de cas particulier à
écrire, et la somme des versements se compare toujours au montant accordé.

> **Tranché le 01.10.2026 — la table existe dès la v1, l'écran n'en montre
> qu'une ligne.** Le versement échelonné est rare : on ne construit pas d'emblée
> l'échéancier, la saisie multiple et l'affichage qui vont avec. Mais la TABLE,
> si — parce que c'est elle qu'il serait coûteux d'ajouter après coup. Déplacer
> plus tard un `date_versement` et un `ecriture_id` depuis `fonds_demandes` vers
> une table nouvelle demanderait de recréer la table sous un nom temporaire, d'y
> recopier les données, de la renommer — la manœuvre que `docs/DECISIONS.md
> § Migrations SQLite` décrit comme celle qui casse des clés étrangères quand on
> s'y prend mal. Une table dès maintenant, utilisée avec une seule ligne, et le
> jour où l'échelonnement arrive, seul l'écran change.

### 3 ter. Ce que devient une subvention accordée, en comptabilité

**Tranché le 01.10.2026** : les trois liens existent, et ils ne se contredisent
pas — ils répondent à trois questions différentes.

| lien | quand | à quoi ça sert |
|---|---|---|
| une **facture** au bailleur (`factures`, QR-facture) | quand il en demande une — tous ne le font pas | lui envoyer une pièce conforme, avec l'IBAN de l'association |
| une ou plusieurs **écritures** rapprochées (`fonds_versements.ecriture_id`) | à chaque versement reçu | savoir que l'argent est arrivé, et le voir dans les comptes |
| un **axe analytique** (`fonds_campagnes.axe_analytique_id`) | dès l'ouverture de la recherche | rattacher l'argent au projet qu'il finance, comme le reste du module analytique |

⚠️ **Un `spectacle` ne porte aujourd'hui aucun axe analytique** : la colonne
existe sur les événements, les lignes de fiche, les lignes de facture et les
écritures, jamais sur le projet lui-même. « L'axe est celui du projet » suppose
donc de savoir lequel — voir la question 11 quater.

Le module **ne duplique aucun de ces trois objets** : il pose des clés vers eux.
Une subvention sans facture, sans écriture et sans axe reste parfaitement
suivable — le lien comptable est une commodité, pas une condition.

### 3 quater. Les pièces vivent sur un drive, pas ici

**Tranché le 01.10.2026 : le module ne stocke aucun fichier.** Les dossiers de
subvention sont volumineux, se relisent à plusieurs et s'éditent ailleurs — un
drive fait ce travail mieux qu'une application de gestion. La campagne porte
donc **un lien** vers le dossier qui contient tout : budgets, lettres, pièces
exigées, décisions, bilans.

Ce que ça évite, et qui n'est pas rien : aucune route de téléchargement
authentifiée, aucun quota à surveiller, aucune pièce jointe à embarquer dans la
sauvegarde — la base reste petite, et une restauration n'a pas de fichiers à
retrouver (comparer avec la feuille de route d'un événement, § `README.md`,
dont les pièces jointes vivent sous `data/fichiers/`).

Un lien par **campagne**, pas par demande : c'est le dossier de la recherche
qu'on ouvre, et il s'organise en sous-dossiers chez le prestataire. Si l'usage
réclame un lien par bailleur, une colonne de plus sur `fonds_demandes` suffira —
`ALTER TABLE … ADD COLUMN` est l'opération sans risque.

Le lien s'affiche avec les mêmes garde-fous que « Formulaire de contact » :
adresse **http(s) absolue** seulement, `target="_blank"`, `rel="noopener"`,
icône `external-link`. C'est le moment de généraliser
`bouton_formulaire_contact_html()` en un bouton de lien externe dont le
formulaire de contact ne serait qu'un appelant — deux boutons identiques à un
libellé près ne se dessinent pas deux fois.

## 4. Statut d'une demande

**Dérivé quand c'est possible, stocké sinon** — même partage que le statut d'une
campagne (`campagne_statut()`) ou d'une facture.

| statut | ce qu'il dit |
|---|---|
| **À préparer** | rien n'est parti ; `date_limite` approche ou non |
| **Déposée** | `date_depot` renseignée, pas de réponse |
| **Accordée** | `montant_accorde` > 0 |
| **Partielle** | accordée pour moins que demandé — *faut-il la distinguer ? § 9.6* |
| **Refusée** | réponse négative notée |
| **Abandonnée** | on ne dépose pas (hors critères, trop tard) |

« En retard » est **dérivé** : à préparer et `date_limite` dépassée, ou déposée
depuis plus de N mois sans réponse (N configurable, comme le délai SUISA).

**Le bilan est un second cycle, après l'argent.** Une demande accordée n'est pas
finie : il reste à rendre un bilan, à une date que le bailleur fixe. Deux états
de plus, dérivés eux aussi de `date_limite_bilan` et `date_bilan` :
**bilan à rendre** (accordée, date limite connue, rien de transmis) et **bilan en
retard** (la date est passée). C'est la seconde échéance que le module doit
rappeler — la première, personne ne l'oublie, c'est elle qui apporte l'argent.

## 5. L'avancement se compte en francs

C'est la différence visible avec le booking : la jauge d'une campagne de
démarchage compte des structures, celle d'une recherche de fonds compte de
l'**argent**. Trois parts, mêmes couleurs que partout :

- **obtenu** (teal) : somme des `montant_accorde` ;
- **en attente** (ambre) : somme des `montant_demande` des dossiers déposés sans
  réponse ;
- **reste à trouver** (gris) : la base − les deux précédents.

La base est `montant_ideal` s'il est chiffré, sinon `montant_minimal`, sinon ce
qui est en jeu (obtenu + attente) — une barre pleine dit alors « tout est joué »,
pas « c'est gagné ». **Deux paliers et non un** : une campagne vise le minimum
sans lequel le projet ne se fait pas ET ce qu'il faudrait pour le faire comme on
le voudrait. Un montant unique laissait croire qu'au-dessous de la barre tout est
perdu, et au-dessus qu'il n'y a plus rien à chercher. La barre se cale donc sur
l'idéal — elle peut dépasser le minimum sans déborder — et un **repère** dit où
est ce minimum. Le verdict (« minimum atteint ») se tranche sur l'argent
**acquis** : l'attente n'est pas de l'argent, et c'est précisément ce que ce
seuil sert à trancher.

Le compte en nombre de dossiers reste affiché à côté (« 7 / 12 déposés »), comme
le « 3 / 6 » d'une campagne.

## 6. Écrans

| route | contenu | modèle existant |
|---|---|---|
| `?p=fonds_campagnes` | les recherches de fonds, en tranches (en cours / à venir / passées) | `?p=booking_campagnes` |
| `?p=fonds_campagne&id=` | le suivi : jauge en francs, tableau des bailleurs, montants, dates limites, statut par ligne, modification sur place | `?p=booking_campagne` |
| `?p=fonds_campagne_form` | créer/modifier : nom, période, objectifs minimal et idéal, projets, sélection des bailleurs par filtres | `?p=booking_campagne_form` |
| `?p=fonds_demande&id=` | le dossier : montants, dates, référence, pièces jointes, historique | `?p=facture` |
| Carte tableau de bord | **deux choses** : l'avancement de la recherche, comme une campagne de booking — ce qu'il reste à envoyer se voit d'un coup d'œil — et **les bilans dus** | carte « Campagnes » |

La fiche d'une structure gagne une carte **« Subventions »** listant ses
demandes, comme elle liste déjà ses factures et ses événements.

## 7. Droits et activation

- Nouveau module `fonds` dans `MODULES`, libellé **« Recherche de fonds »**,
  **`requires: []`** : il réutilise les structures comme la Facturation, sans
  dépendre du Booking.
- Une couleur dans `MODULE_COULEURS` (les cinq prises : teal, ambre, indigo,
  rose, violet — contraste AA obligatoire sous texte blanc).
- Une entrée dans `PERMISSION_MODULES`, et les routes rattachées par
  `ajouter_routes_module()` : lecture pour un GET, écriture pour un POST.
- `recherche_sources()` : une recherche unifiée doit trouver une demande par le
  nom du bailleur ou la référence du dossier.

## 8. Plan d'implémentation

Chaque étape est livrable seule et laisse l'application utilisable.

1. **Socle** — migration (cinq tables + index : campagnes, demandes,
   versements, catalogue de pièces, liaison bailleur↔pièces), catalogue semé
   avec ses trois entrées, « Projets » semé comme terme par défaut, entrée
   `MODULES`, couleur, permissions, routes vides, onglets. Rien d'autre : c'est
   l'étape qui vérifie que le module s'allume et s'éteint proprement.
2. **Les campagnes** — `?p=fonds_campagnes` et `?p=fonds_campagne_form`, en reprenant
   `campagnes.php` / `campagne_form.php` et la sélection par filtres.
3. **Le suivi** — `?p=fonds_campagne` : tableau des bailleurs, délai propre à
   chacun, saisie des montants et des dates sur place (motif § 2d de
   `docs/UI.md`), jauge en francs.
4. **Le dossier** — `?p=fonds_demande` : la fiche complète, les pièces exigées
   par le bailleur, l'historique, le lien vers le dossier de la campagne sur le
   drive. C'est aussi ici que se règlent les pièces d'un bailleur, depuis sa
   première demande — on ne fait pas un écran à part pour trois cases.
5. **Les échéances** — statut dérivé, **les deux** : le dépôt et le bilan. Carte
   du tableau de bord, mise en valeur ambre de ce qui attend un geste.
6. **Le versement** — une ligne, sa date, son rapprochement à une écriture.
   L'échéancier à plusieurs lignes attendra d'être demandé (§ 9.7).
7. **Les liens** — facture au bailleur, axe analytique (déjà posé sur le
   projet, migration 91), carte « Recherche de fonds » sur la fiche d'une
   structure, recherche unifiée.

Tests attendus à chaque étape, dans `tests/fonds_test.php` : statut dérivé,
répartition de la jauge, détection des échéances. Fonctions pures paramétrées
par la date du jour, comme `campagne_statut()`.

## 9. Ce qui a été tranché

Toutes les questions du cadrage ont reçu une réponse les 01.10.2026.

### Le modèle

| | décision |
|---|---|
| 1 | **Un bailleur est une `structure`**, et rien ne l'en distingue dans la liste — pas de catégorie ni de drapeau tant que le besoin d'un filtre ne s'est pas fait sentir. |
| 2 | *(sans objet : pas de marque distinctive)* |
| 3 | **Le projet visé est un `spectacle`.** Le terme « Spectacles » devient « Projets » **par défaut à l'installation** — une valeur semée, pas un changement de code. ⚠️ Elle ne touche QUE les nouvelles installations ; celle de l'association garde « Spectacles » jusqu'à ce qu'on règle le paramètre à la main. Changer le repli en dur renommerait le vocabulaire de toutes les installations existantes sans prévenir. |
| 4 | **Une seule demande par (campagne, bailleur)** — index unique. |
| 5 | **Pas de demande hors campagne.** Au pire, une recherche d'une ligne. |

### Le cycle de vie

| | décision |
|---|---|
| 6 | **Les statuts du § 4 sont les bons**, bilan compris. |
| 7 | **Pas de versement échelonné en v1.** Mais la table `fonds_versements` existe dès le départ, utilisée avec une seule ligne : c'est elle qui serait coûteuse à ajouter après coup, pas l'écran. |
| 8 | **Les pièces exigées appartiennent au bailleur.** Trois cases semées — Rapport d'activité, Bilan financier du projet, Comptes vérifiés — plus un champ libre « Autres ». Catalogue + liaison, comme les étiquettes. **Aucune case « fournie »** : on note seulement si le bilan a été envoyé (`date_bilan`), pas pièce par pièce. |
| 9 | **Pas de lien vers la demande précédente.** La campagne suivante et l'historique de la structure suffisent. |
| 10 | Le **délai de dépôt est propre à la campagne** ; on suit aussi **le délai du bilan**. |

### L'argent

| | décision |
|---|---|
| 11 | **Les trois liens comptables existent** : facture au bailleur, écriture par versement, axe analytique (§ 3 ter). Aucun n'est obligatoire. |
| 11 quater | **L'axe est celui du projet — fait.** `spectacles.axe_analytique_id` existe depuis la migration 91 : l'axe se réglait date par date, il appartient maintenant au projet, et les dates en héritent. Le module s'y branche, il n'a rien à inventer. |
| 12 | **Pas de pluriannuel** en v1. |

### Les échanges et les écrans

| | décision |
|---|---|
| 13 | **Pas d'envoi groupé** : chaque dossier est trop spécifique. Le bouton « Formulaire de contact » et « Contacter », eux, servent déjà. |
| 15 | **Un seul fil d'historique** par structure, booking et recherche de fonds mêlés — c'est déjà le comportement du fil unifié. |
| 16 | **La carte du tableau de bord montre deux choses** : l'avancement de la recherche, comme une campagne de booking — ce qu'il reste à envoyer se voit d'un coup d'œil — et **les bilans dus**. |
| 17 | **Pas d'export pour le comité.** |
| 18 | **Le module s'appelle « Recherche de fonds »**, et ce qu'on y crée une **campagne** — « campagne de recherche de fonds » en entier, « campagne » dans le module, qui dit déjà desquelles il s'agit. « Recherche » seul entrait en collision avec la recherche unifiée de l'application. |
| 20 | **Aucune donnée à reprendre** : pas de tableur existant, donc pas d'import. |

### Hors périmètre v1, explicitement

Rappel automatique par e-mail (aucune tâche planifiée), subvention
pluriannuelle, versement échelonné. Les trois peuvent arriver plus tard sans
rien défaire : le rappel est un écran de plus, le pluriannuel une demande par
année, et l'échelonnement une table déjà là.

### Les pièces et les fichiers

| | décision |
|---|---|
| 14 | **Aucun fichier stocké par l'application.** Un lien vers le drive externe, porté par la campagne (§ 3 quater). |
| 11 bis | **Le montant d'une facture en cas d'échelonnement : pas maintenant.** Sans échelonnement en v1, c'est le total accordé. |

---

Le bandeau passera à « module en service » le jour de la livraison.
