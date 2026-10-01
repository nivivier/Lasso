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

Deux tables, et elles seules.

### `fonds_campagnes` — une recherche de fonds

| champ | notes |
|---|---|
| `id` | |
| `nom` | « Création 2027 », « Fonctionnement 2027 » |
| `date_debut`, `date_fin` | la période de la recherche, comme une campagne de booking |
| `montant_cible` | le budget à boucler — ce qui donne un sens à la jauge (§ 5) |
| `criteres` | la sélection de bailleurs, même format qu'une campagne |
| `notes`, `cree_le` | |

Projets visés : table de liaison `fonds_campagne_spectacles`, sur le modèle de
`campagne_spectacles` (à confirmer, § 9.3).

### `fonds_demandes` — un dossier chez un bailleur

| champ | notes |
|---|---|
| `id` | |
| `campagne_id` | FK → `fonds_campagnes` |
| `structure_id` | FK → `structures` — le bailleur |
| `statut` | voir § 4 |
| `montant_demande`, `montant_accorde` | figés à la saisie, jamais recalculés |
| `date_limite` | **la date de dépôt imposée par le bailleur** — c'est elle qui pilote les alertes |
| `date_depot`, `date_reponse` | |
| `reference` | numéro de dossier chez le bailleur |
| `notes`, `cree_le` | |

Une demande par (campagne, bailleur) — sauf si § 9.4 en décide autrement.

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

1. **Socle** — migration (deux tables + index), entrée `MODULES`, couleur,
   permissions, routes vides, onglets. Rien d'autre : c'est l'étape qui vérifie
   que le module s'allume et s'éteint proprement.
2. **Les campagnes** — `?p=fonds` et `?p=fonds_campagne_form`, en reprenant
   `campagnes.php` / `campagne_form.php` et la sélection par filtres.
3. **Le suivi** — `?p=fonds_campagne` : tableau des bailleurs, saisie des
   montants et des dates sur place (motif § 2d de `docs/UI.md`), jauge en francs.
4. **Le dossier** — `?p=fonds_demande` : la fiche complète, l'historique, les
   pièces jointes si retenues (§ 9.14).
5. **Les échéances** — statut dérivé « en retard », carte du tableau de bord,
   mise en valeur ambre de ce qui attend un geste.
6. **Les liens** — carte « Subventions » sur la fiche d'une structure, recherche
   unifiée, export.
7. **Comptabilité** — selon la réponse au § 9.11.

Tests attendus à chaque étape, dans `tests/fonds_test.php` : statut dérivé,
répartition de la jauge, détection des échéances. Fonctions pures paramétrées
par la date du jour, comme `campagne_statut()`.

## 9. Questions à trancher avant d'écrire une ligne

### Le modèle

1. **Un bailleur est-il bien une `structure` ?** (recommandé, § 2) Ou faut-il
   une table à part parce que les champs utiles diffèrent trop (type de fonds,
   périodicité, montants plafonds) ?
2. Si c'est une structure, **comment la reconnaît-on** : une catégorie
   « Bailleur » dans `structure_categories`, une étiquette, ou un drapeau ? Une
   commune qui programme **et** subventionne doit pouvoir être les deux.
3. **Une recherche de fonds vise-t-elle un projet, une saison, ou le
   fonctionnement ?** Plusieurs projets à la fois ? Si oui, la liaison aux
   `spectacles` suffit-elle, ou faut-il un objet « projet » distinct (une
   création n'est pas toujours un spectacle au catalogue) ?
4. **Peut-on déposer deux dossiers chez le même bailleur dans la même
   campagne** (deux projets, deux guichets) ?
5. **Une demande peut-elle exister hors campagne** — une sollicitation ponctuelle
   en cours d'année ?

### Le cycle de vie

6. **La liste de statuts du § 4 est-elle la bonne ?** Faut-il « en
   instruction » ? « Partielle » mérite-t-elle un statut, ou suffit-il de
   comparer les deux montants ?
7. **Les versements sont-ils échelonnés** (acompte / solde) ? Si oui, il faut un
   montant versé distinct du montant accordé, et peut-être une table de
   versements.
8. **Quelles dates suit-on vraiment ?** Limite de dépôt, dépôt, réponse,
   versement, **rapport final à rendre** — ce dernier est souvent une obligation
   contractuelle, et personne ne le rappelle.
9. **Un refus se retente l'année suivante** : faut-il relier une demande à la
   précédente (« déjà demandé en 2026, refusé »), ou la campagne suivante et
   l'historique de la structure suffisent-ils ?

### L'argent

10. **La jauge compte-t-elle en francs** (§ 5) ou en nombre de dossiers ? Le
    `montant_cible` est-il toujours connu à l'ouverture d'une recherche ?
11. **Lien avec la comptabilité** : une subvention accordée doit-elle produire
    une **facture** au bailleur (QR-facture — certains l'exigent), une
    **écriture** à rapprocher au relevé, un **axe analytique** ? Ou reste-t-elle
    purement informative dans ce module ?
12. **Pluriannuel** : une subvention de fonctionnement sur trois ans se saisit-
    elle une fois avec trois versements, ou une fois par année ?

### Les échanges

13. **Réutilise-t-on « Contacter » et les modèles de message ?** Un envoi
    groupé a-t-il un sens ici, ou chaque dossier est-il trop spécifique (ma
    lecture : trop spécifique, donc pas de `mailing`) ?
14. **Pièces jointes au dossier** (budget, lettre, décision, rapport) : les
    range-t-on hors webroot comme celles d'une feuille de route
    (`data/fichiers/`, route authentifiée), avec la même limite de 8 Mo ?
15. **L'historique d'une structure** mélange-t-il booking et subventions dans le
    même fil, ou faut-il les séparer ? (le fil est déjà unifié par
    `entite_type`/`entite_id` — les mélanger est le comportement par défaut)

### Les écrans

16. **Que montre la carte du tableau de bord** : les échéances les plus proches,
    les réponses qui tardent, le total obtenu sur l'année, les trois ?
17. **Un rapport pour le comité** : faut-il un export (CSV, PDF imprimable) de
    l'état d'une recherche — qui a donné combien, ce qui reste à trouver ?

### Le module

18. **Son nom à l'écran** : « Subventions », « Recherche de fonds »,
    « Financement » ? C'est ce qui s'affichera dans le rail.
19. **Son périmètre v1** : qu'est-ce qu'on écarte explicitement ? Proposition à
    valider — pas de rappels automatiques par e-mail (pas de tâche planifiée),
    pas de versements échelonnés, pas de pluriannuel, pas de lien comptable.
20. **Y a-t-il des données existantes à reprendre** (un tableur de suivi des
    demandes passées) ? Si oui, un import CSV ponctuel fait partie du
    périmètre — et sa forme dépend du tableur réel.

---

Une fois ces réponses arrêtées, ce document est repris avec elles, son bandeau
passe à « module en service » le jour de la livraison, et le plan du § 8 devient
la suite des commits.
