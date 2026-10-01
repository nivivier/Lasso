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
| `axe_analytique_id` | FK nullable → `axes_analytiques` : le projet financé est déjà un axe en comptabilité, et c'est lui que les factures et les écritures de cette recherche porteront (§ 3 ter) |
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
| `pieces_demande` | ce que le bailleur exige pour le dossier |
| `pieces_bilan` | ce qu'il exige pour le bilan |
| `facture_id` | FK nullable → `factures` : certains bailleurs veulent une facture (§ 3 ter) |
| `reference` | numéro de dossier chez le bailleur |
| `notes`, `cree_le` | |

Une demande par (campagne, bailleur) — sauf si § 9.4 en décide autrement.

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

### 3 ter. Ce que devient une subvention accordée, en comptabilité

**Tranché le 01.10.2026** : les trois liens existent, et ils ne se contredisent
pas — ils répondent à trois questions différentes.

| lien | quand | à quoi ça sert |
|---|---|---|
| une **facture** au bailleur (`factures`, QR-facture) | quand il en demande une — tous ne le font pas | lui envoyer une pièce conforme, avec l'IBAN de l'association |
| une ou plusieurs **écritures** rapprochées (`fonds_versements.ecriture_id`) | à chaque versement reçu | savoir que l'argent est arrivé, et le voir dans les comptes |
| un **axe analytique** (`fonds_campagnes.axe_analytique_id`) | dès l'ouverture de la recherche | rattacher l'argent au projet qu'il finance, comme le reste du module analytique |

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

1. **Socle** — migration (trois tables + index), entrée `MODULES`, couleur,
   permissions, routes vides, onglets. Rien d'autre : c'est l'étape qui vérifie
   que le module s'allume et s'éteint proprement.
2. **Les campagnes** — `?p=fonds` et `?p=fonds_campagne_form`, en reprenant
   `campagnes.php` / `campagne_form.php` et la sélection par filtres.
3. **Le suivi** — `?p=fonds_campagne` : tableau des bailleurs, délai propre à
   chacun, saisie des montants et des dates sur place (motif § 2d de
   `docs/UI.md`), jauge en francs.
4. **Le dossier** — `?p=fonds_demande` : la fiche complète, les pièces exigées
   pour la demande et pour le bilan, l'historique, les pièces jointes si
   retenues (§ 9.14).
5. **Les échéances** — statut dérivé, **les deux** : le dépôt et le bilan. Carte
   du tableau de bord, mise en valeur ambre de ce qui attend un geste.
6. **Les versements** — l'échéancier d'un octroi, et le rapprochement d'une
   écriture par versement.
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
| 3 | **Le projet visé est un `spectacle`**, renommable pour toute l'application par « Terme pour une série d'événements ». |
| 7 | **Les versements peuvent être échelonnés** — rare, mais prévu : table `fonds_versements`. |
| 8 | On suit aussi **le délai du bilan** et **les pièces exigées**, pour la demande comme pour le bilan. |
| 11 | **Les trois liens comptables existent** : facture au bailleur, écriture par versement, axe analytique (§ 3 ter). Aucun n'est obligatoire. |
| 10 | Le **délai de dépôt est propre à la campagne** : le même bailleur n'a pas la même date d'une année sur l'autre. |

### Ce que ces réponses ouvrent

3 bis. **Renommer « Spectacles » en « Projets » renomme TOUT le module
   Événements** — l'onglet du rail, la liste, l'intitulé du champ sur une date,
   la section d'import. C'est bien l'intention ? (Ce n'est pas un défaut : c'est
   la même entité, et un seul mot pour une seule chose vaut mieux que deux.)

8 bis. **« Les pièces demandées » : du texte libre ou une liste à cocher ?**
   Du texte se saisit en dix secondes et se lit ; une liste se coche, donc elle
   dit ce qui manque, et la carte du tableau de bord peut le rappeler. Mon avis :
   du texte libre en v1, une liste si l'usage montre qu'on coche vraiment.

8 ter. **Ces pièces changent-elles d'une campagne à l'autre pour un même
   bailleur ?** Si elles sont stables, elles appartiennent au bailleur (et se
   recopient dans chaque nouvelle demande) plutôt qu'à la demande. Dans le doute
   je les mets sur la demande, quitte à proposer « reprendre celles de l'an
   dernier ».

7 bis. **L'échéancier des versements se saisit-il d'avance** (le bailleur
   annonce 60 % à la signature, 40 % au bilan) **ou note-t-on les versements
   quand ils arrivent ?** Les deux marchent avec la table proposée, mais le
   premier permet d'annoncer la trésorerie à venir.

11 bis. **La facture au bailleur porte quel montant** : le total accordé, ou un
   montant par versement ? Et qui décide qu'il en faut une — une case sur la
   demande, ou le simple fait de cliquer « créer la facture » ?

11 ter. **L'axe analytique est-il celui du projet** (donc déjà existant dans la
   comptabilité) ou un axe propre à la recherche de fonds ?

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
    une fois avec trois versements, ou une fois par année ?
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
20. **Des données existantes à reprendre** (un tableur de suivi des demandes
    passées) ? Sa forme réelle changerait le modèle.

---

Une fois ces réponses arrêtées, ce document est repris avec elles, son bandeau
passe à « module en service » le jour de la livraison, et le plan du § 8 devient
la suite des commits.
