# Spécification — Module Recherche de fonds (subventions)

Statut : **à valider avant implémentation**. Ce document cadre le besoin et pose
les questions à trancher ; il n'est pas un plan de code figé. Rien n'est écrit
tant que les réponses du § 9 ne sont pas arrêtées.

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
> (`?p=parametres_evenements` → « Terme pour une série d'événements » ;
> `evenements_terme_spectacle()`). Les écrans de ce module emploient donc ce
> terme-là, jamais « spectacle » en dur — si l'association l'appelle « projet »,
> tout suit (voir la question 3 bis).

### `fonds_campagnes` — une recherche de fonds

| champ | notes |
|---|---|
| `id` | |
| `nom` | « Création 2027 », « Fonctionnement 2027 » |
| `date_debut`, `date_fin` | la période de la recherche, comme une campagne de booking |
| `montant_cible` | le budget à boucler — ce qui donne un sens à la jauge (§ 5) |
| `criteres` | la sélection de bailleurs, même format qu'une campagne |
| `axe_analytique_id` | FK nullable → `axes_analytiques` : **celui du projet financé** (tranché le 01.10.2026), pré-rempli à la création et modifiable. C'est lui que porteront la facture et les écritures de cette recherche (§ 3 ter) |
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

Une demande par (campagne, bailleur) — sauf si § 9.4 en décide autrement.

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
- **reste à trouver** (gris) : `montant_cible` − les deux précédents.

Le compte en nombre de dossiers reste affiché à côté (« 7 / 12 déposés »), comme
le « 3 / 6 » d'une campagne.

## 6. Écrans

| route | contenu | modèle existant |
|---|---|---|
| `?p=fonds` | les recherches de fonds, en tranches (en cours / à venir / passées) | `?p=campagnes` |
| `?p=fonds_campagne&id=` | le suivi : jauge en francs, tableau des bailleurs, montants, dates limites, statut par ligne, modification sur place | `?p=campagne` |
| `?p=fonds_campagne_form` | créer/modifier : nom, période, cible, projets, sélection des bailleurs par filtres | `?p=campagne_form` |
| `?p=fonds_demande&id=` | le dossier : montants, dates, référence, pièces jointes, historique | `?p=facture` |
| Carte tableau de bord | ce qui attend un geste : dépôts dont la date limite approche, réponses qui tardent | carte « Campagnes » |

La fiche d'une structure gagne une carte **« Subventions »** listant ses
demandes, comme elle liste déjà ses factures et ses événements.

## 7. Droits et activation

- Nouveau module `fonds` dans `MODULES`, **`requires: []`** : il réutilise les
  structures comme la Facturation, sans dépendre du Booking.
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
2. **Les campagnes** — `?p=fonds` et `?p=fonds_campagne_form`, en reprenant
   `campagnes.php` / `campagne_form.php` et la sélection par filtres.
3. **Le suivi** — `?p=fonds_campagne` : tableau des bailleurs, délai propre à
   chacun, saisie des montants et des dates sur place (motif § 2d de
   `docs/UI.md`), jauge en francs.
4. **Le dossier** — `?p=fonds_demande` : la fiche complète, les pièces exigées
   par le bailleur (cochées à son niveau, § 3), l'historique, les pièces jointes
   si retenues (§ 9.14). C'est aussi ici que se règlent les pièces d'un
   bailleur, depuis sa première demande — on ne fait pas un écran à part pour
   trois cases.
5. **Les échéances** — statut dérivé, **les deux** : le dépôt et le bilan. Carte
   du tableau de bord, mise en valeur ambre de ce qui attend un geste.
6. **Le versement** — une ligne, sa date, son rapprochement à une écriture.
   L'échéancier à plusieurs lignes attendra d'être demandé (§ 9.7).
7. **Les liens** — facture au bailleur, axe analytique, carte « Subventions »
   sur la fiche d'une structure, recherche unifiée, export.

Tests attendus à chaque étape, dans `tests/fonds_test.php` : statut dérivé,
répartition de la jauge, détection des échéances. Fonctions pures paramétrées
par la date du jour, comme `campagne_statut()`.

## 9. Questions

### Ce qui est tranché (01.10.2026)

| | décision |
|---|---|
| 1 | **Un bailleur est une `structure`**, et rien ne l'en distingue dans la liste pour l'instant — pas de catégorie ni de drapeau tant que le besoin d'un filtre ne s'est pas fait sentir. |
| 3 | **Le projet visé est un `spectacle`.** Le terme « Spectacles » devient « Projets » **par défaut à l'installation** — une valeur semée, pas un changement de code : le paramètre existe déjà (« Terme pour une série d'événements »). ⚠️ Semé, il ne touche QUE les nouvelles installations ; celle de l'association garde « Spectacles » jusqu'à ce qu'on règle le paramètre à la main. C'est voulu : changer le repli en dur renommerait le vocabulaire de toutes les installations existantes sans prévenir. |
| 7 | **Pas de versement échelonné en v1** — cas rare. Mais la table `fonds_versements` existe dès le départ, utilisée avec une seule ligne : c'est elle qui serait coûteuse à ajouter après coup, pas l'écran. |
| 8 | **Les pièces exigées appartiennent au bailleur**, pas à la campagne. Trois cases semées — Rapport d'activité, Bilan financier du projet, Comptes vérifiés — plus un champ libre « Autres ». Catalogue + liaison, comme les étiquettes. |
| 10 | Le **délai de dépôt est propre à la campagne** : le même bailleur n'a pas la même date d'une année sur l'autre. On suit aussi **le délai du bilan**. |
| 11 | **Les trois liens comptables existent** : facture au bailleur, écriture par versement, axe analytique (§ 3 ter). Aucun n'est obligatoire. L'axe est **celui du projet**. |
| 20 | **Aucune donnée à reprendre** : pas de tableur existant, donc pas d'import au périmètre. |

### Ce que ces réponses ouvrent

8 quater. **Une pièce exigée se coche-t-elle comme « fournie » ?** La liste du
   bailleur dit ce qu'il faut ; sur une demande, savoir ce qui est déjà prêt est
   une autre information — et c'est elle qui dirait « il manque les comptes
   vérifiés » avant la date limite. Si oui, une table de plus
   (`fonds_demande_pieces`), ou un simple compteur ?

11 quater. **Quel axe est « celui du projet » ?** Un `spectacle` n'en porte
   aucun aujourd'hui — l'axe vit sur les événements, les lignes de fiche, de
   facture et les écritures. Trois voies : (a) ajouter `axe_analytique_id` à
   `spectacles`, ce qui servirait aussi aux événements, qui le choisissent un à
   un alors que leur projet le sait ; (b) le choisir à la main sur la recherche
   de fonds ; (c) le déduire des événements du projet, ce qui est fragile s'ils
   divergent. Mon avis : (a), parce que la notion manque là où elle devrait
   être — mais elle touche le module Événements, donc c'est à toi.

11 bis. **La facture au bailleur porte quel montant** : le total accordé, ou un
   montant par versement ? (sans échelonnement en v1, c'est le total — la
   question se repose le jour où l'échelonnement arrive)

### Restées ouvertes

4. **Deux dossiers chez le même bailleur dans une même campagne** (deux guichets,
   deux projets) : possible ou non ?
5. **Une demande hors campagne** — une sollicitation ponctuelle en cours d'année ?
6. **La liste de statuts du § 4 est-elle la bonne ?** Faut-il « en instruction » ?
   « Partielle » mérite-t-elle un statut, ou suffit-il de comparer les montants ?
9. **Un refus se retente l'année suivante** : faut-il relier une demande à la
   précédente, ou la campagne suivante et l'historique de la structure
   suffisent-ils ?
12. **Pluriannuel** : une subvention de fonctionnement sur trois ans se saisit-elle
    une fois avec trois versements, ou une fois par année ? (lié au § 7 : écarté
    de la v1 avec l'échelonnement)
13. **Réutilise-t-on « Contacter » et les modèles de message ?** Un envoi groupé
    a-t-il un sens ici ? (ma lecture : non, chaque dossier est trop spécifique —
    mais le bouton « Formulaire de contact », lui, sert déjà)
14. **Pièces jointes réelles** (budget, lettre, décision, bilan) : les range-t-on
    hors webroot comme celles d'une feuille de route (`data/fichiers/`, route
    authentifiée, 8 Mo) ? C'est la suite logique du point 8.
15. **L'historique d'une structure** mélange-t-il booking et subventions dans le
    même fil ? (c'est le comportement par défaut, le fil est déjà unifié)
16. **Que montre la carte du tableau de bord** : les dépôts qui approchent, les
    bilans dus, les réponses qui tardent, le total obtenu sur l'année ?
17. **Un rapport pour le comité** : export CSV ou PDF de l'état d'une recherche ?
18. **Le nom du module à l'écran** : « Subventions », « Recherche de fonds »,
    « Financement » ?
19. **Le périmètre v1** : qu'écarte-t-on explicitement ? Proposition — pas de
    rappel automatique par e-mail (aucune tâche planifiée), pas de pluriannuel.

---

Une fois ces réponses arrêtées, ce document est repris avec elles, son bandeau
passe à « module en service » le jour de la livraison, et le plan du § 8 devient
la suite des commits.
