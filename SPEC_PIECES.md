# Spécification — Pièces comptables

Statut : **cadrage complet, en attente de validation** (11.10.2026). Les onze
questions ouvertes ont reçu une réponse (§ 11) ; le plan du § 12 devient la
suite des commits. Ce document reste le « pourquoi » — en cas de désaccord avec
le code livré, c'est le code qui fait foi.

## 1. Objectif

Tenir les **justificatifs** de la comptabilité : la facture reçue d'un
fournisseur, le ticket de caisse photographié, la quittance remise à qui nous a
payés. Et surtout répondre à la question qu'on se pose au bouclement : **quelles
lignes du compte n'ont pas de pièce ?**

Trois besoins, dans cet ordre d'urgence :

- **enregistrer une pièce en avance**, avant tout mouvement bancaire — une
  facture arrive le 3, elle se paie le 30 : entre les deux elle existe, avec son
  montant et son échéance, et c'est déjà une liste de ce qu'il y a à payer ;
- **la rattacher** à l'écriture qui la solde, depuis l'une ou l'autre ;
- **voir ce qui manque**, en sachant que certaines lignes n'auront jamais de
  pièce — les frais de tenue de compte n'ont pas de facture.

## 2. Ce qui est déjà là, et qu'on ne réécrit pas

| Besoin | Ce que l'application a déjà |
| --- | --- |
| Fichier privé téléversé | `lib/feuille_route.php` : `data/fichiers/` hors racine web, validation par le **contenu** (`finfo`, jamais l'extension), nom de stockage tiré au sort, 8 Mo, route de service authentifiée, effacé avec son enregistrement, inclus dans la sauvegarde |
| Vignette réduite côté navigateur | Le recadrage d'avatar : canvas, data URI, revalidée par le serveur comme un fichier reçu |
| Rapprochement automatique | `facturation_suggerer_rapprochements()` : montant au centime + nom retrouvé dans le libellé, joué à l'import |
| Sélecteur d'écritures libres | `fonds_ecritures_rapprochables()` : exclut celles déjà prises, garde la sienne |
| « Volontairement sans suite » | `origine_lettrage = 'ignore'` — le précédent exact d'une écriture dont on sait qu'elle n'aura pas de pièce |
| Table fille d'une écriture | `ecritures_ventilations` : le 1→N sur une écriture est déjà admis |
| Deux des cinq cas de justificatif | `ecritures.fiche_id` (salaire) et `ecritures.facture_id` (facture émise) |
| Filtre à sentinelles textuelles | `filtre_coche(..., texteLibre: true)` sur `categorie` et `axe` — ids numériques et mots-clés mêlés |
| Pastille de compteur sur un onglet | `nb_ecritures_a_lettrer()` dans `nav_groupes()` |
| Mise en valeur de ce qui attend un geste | `.ligne-action` (ambre) sur une carte du tableau de bord |
| Document imprimable | `render_bare()` + `views/*_imprimer.php` |
| Sous-module dépendant d'un autre | `analytique` → `compta` dans `MODULES` (`requires`) |
| Archive téléchargeable | `lib/sauvegarde.php` : ZIP construit à la volée |

**Le socle fichier se généralise, il ne se recopie pas.** `lib/fichiers.php`
reçoit `fichier_prive_enregistrer()` et `fichiers_prives_dir()` ; la feuille de
route en devient le premier appelant. Le stockage et la validation sont communs,
**l'autorisation ne l'est pas** : chaque module garde sa route de service, qui
sait qui a le droit de lire.

## 3. Le modèle

Une pièce est une **entité à part entière**, pas une pièce jointe sur une
écriture : elle vit avant qu'aucune écriture n'existe, et c'est tout l'intérêt
de pouvoir la saisir en avance.

```
pieces
    id
    type            facture_recue | ticket | quittance | autre
    origine         televersee | emise        -- reçue, ou produite par Lasso
    libelle         « Facture Swisscom janvier »
    tiers           fournisseur, ou bénéficiaire pour une quittance
    montant         REAL, SIGNÉ COMME UNE ÉCRITURE : < 0 dépense, > 0 recette
    date_piece      date du document
    date_echeance   quand elle doit être payée ('' si sans objet)
    date_payee      '' tant qu'elle ne l'est pas
    mode_paiement   '' | compte | hors_compte
    plan_compte_id  → plan_comptes(id) ON DELETE SET NULL   -- catégorie présumée
    ecriture_id     → ecritures(id)    ON DELETE SET NULL   -- NULL = pas rapprochée
    fichier         nom de stockage sous data/fichiers/ ('' pour une quittance émise)
    nom_origine, mime, taille
    numero          Q-2026-001 pour une quittance, '' sinon
    notes
    cree_le
```

**Le montant est signé comme celui d'une écriture.** Une pièce et l'écriture qui
la solde doivent se comparer sans conversion : c'est ce qui rend le
rapprochement lisible, et c'est la convention déjà en place (`montant > 0`
crédit, `< 0` débit).

**Plusieurs pièces pour une écriture, pas l'inverse** (§ 11). Un virement groupé
qui solde trois factures se modélise ; une facture payée en deux acomptes ne se
modélise pas, et se saisit alors en deux pièces. C'est une clé étrangère, pas
une table de liaison : le coût d'un `piece_ecritures(piece_id, ecriture_id,
montant)` — un écran de répartition de plus, un montant à ventiler à la main —
n'est pas justifié par le volume. Si les acomptes deviennent courants, la table
de liaison se pose sans rien casser (les données migrent, le sens ne change pas).

## 4. Payée n'est pas rapprochée — trois états, pas deux

C'est la contrainte du terrain : **les relevés sont importés une fois par mois**.
Une facture payée le 30 n'a pas d'écriture avant la mi-mois suivant. Si « payée »
voulait dire « rapprochée », elle resterait à payer pendant trois semaines, et la
liste de ce qu'il reste à régler mentirait tout le temps.

| État | Condition | Ce qu'on en fait |
| --- | --- | --- |
| **À payer** | `date_payee = ''` | La liste de travail. Échéance dépassée → ambre |
| **Payée, à rapprocher** | `date_payee ≠ ''`, `mode_paiement = 'compte'`, `ecriture_id IS NULL` | Elle attend l'import du mois. Rien à faire |
| **Classée** | `ecriture_id` renseigné **ou** `mode_paiement = 'hors_compte'` | Terminée |

**`hors_compte` est un mode de paiement, pas un état** : espèces, carte privée,
autre banque — il dit qu'**aucune écriture ne viendra jamais**, et c'est à ce
titre qu'il classe la pièce. Le distinguer d'un simple « payée » évite qu'une
pièce réglée en espèces reste à vie dans « à rapprocher ».

**Une pièce ne crée pas d'écriture** (§ 11). Une écriture se saisit dans les
écritures, point — un second chemin de création multiplierait les écritures
manuelles posées par inadvertance, qui ne sont dans aucun relevé et qu'aucun
solde ne contredit.

## 5. L'état « pièce » d'une écriture — une seule fonction

```php
ecriture_piece_etat(array $ecr): string
// 'fiche' | 'facture' | 'piece' | 'dispensee' | 'manquante'
```

Résolution dans cet ordre : `fiche_id` → `facture_id` → pièce liée → dispensée →
**manquante**.

⚠️ **C'est elle, et elle seule, que lisent** la colonne du tableau, les
sentinelles du filtre, le compteur de la pastille, la carte du tableau de bord,
l'export CSV et l'archive annuelle. Six endroits qui, écrits chacun sa requête,
finiraient par ne plus dire le même nombre — c'est exactement ce que
`STRUCTURE_STATUTS` évite pour les statuts de structure, et un test du même
genre (`tests/statuts_structure_test.php`) nommera ce qui manque.

## 6. Les dispenses — déclarées une fois, pas ligne par ligne

**`plan_comptes.piece_attendue`** (`INTEGER NOT NULL DEFAULT 1`). À 0 sur « Frais
bancaires » et « Cotisations des membres », la question ne se repose plus
jamais : toute écriture lettrée dans cette catégorie est dispensée d'office. Le
réglage vit dans le plan comptable, là où il se lit — c'est une propriété de la
nature de la dépense, pas de chaque ligne de relevé.

**`ecritures.piece_dispense_motif`** (`TEXT NOT NULL DEFAULT ''`) pour
l'exception isolée. **Non vide = dispensée, et le texte dit pourquoi.** Une
colonne, pas deux : même économie que `origine_lettrage`, et on ne peut pas se
retrouver avec une dispense sans raison.

Une écriture non lettrée n'a pas de catégorie, donc pas de dispense par ce
chemin : elle est « manquante » jusqu'à ce qu'on la lettre. C'est voulu —
lettrer vient avant justifier.

## 7. Les écrans et les droits

**Un cinquième onglet « Pièces »** dans la comptabilité : Écritures · Pièces ·
Comptes annuels · Analyse · Plan comptable. ⚠️ Vérifier à quelle largeur la
rangée cesse de tenir à côté du titre et se replie — c'est la contrainte qui a
décidé du regroupement sous « Plan comptable », elle vaut toujours.

**Un module `pieces`, avec `requires: ['compta']`** (§ 11), exactement comme
`analytique`. C'est le mécanisme existant qui porte le « droit de dépôt séparé » :

- `pieces` **écriture** — déposer et modifier une pièce ;
- `compta` **lecture** — voir les écritures.

Quelqu'un qui photographie ses tickets reçoit le premier sans le second. ⚠️ **À
régler à l'implémentation** : `nav_groupes()` construit le bandeau de la
comptabilité depuis `module_accessible('compta')`. L'onglet Pièces doit
apparaître pour qui n'a que `pieces` — c'est le seul endroit du dispositif où un
onglet survit à son module parent, et `SPEC_PERMISSIONS.md` devra le dire.

**`?p=pieces`** — la liste. Barre d'outils, recherche, filtres de colonne et
pagination comme partout. Les filtres qui comptent : *à payer*, *à rapprocher*,
*classée*, par type, par catégorie. Une échéance dépassée se signale à l'ambre,
qui dit un geste à faire.

**`?p=piece`** — la fiche : les champs, l'aperçu du fichier, le marquage
« payée » (date + mode), l'écriture liée ou le bouton pour en choisir une.

**Dans `?p=compta_ecritures`** — une colonne « Pièce » : l'icône du justificatif
quand il y en a un (le trombone pour une pièce, les icônes déjà en place pour
une fiche ou une facture), un tiret discret pour une dispense, et rien de
cliquable à remplir pour ce qui manque. Plus un filtre de colonne à sentinelles
(`manquante`, `dispensee`, `presente`), sur la mécanique `filtre_coche()` déjà
utilisée pour `categorie`.

**Pastille** sur l'onglet Pièces : le nombre de pièces à payer, comme
`nb_ecritures_a_lettrer()` le fait pour Écritures.

**Carte du tableau de bord** « Pièces à payer », les échéances dépassées en
`.ligne-action`, le titre menant au module par `nav_groupe_accueil()`.

## 8. Le rapprochement

**Des deux côtés, manuellement.** Depuis une écriture : une fenêtre qui propose
les pièces non rapprochées, triées par écart de montant puis par distance de
date. Depuis une pièce : la même chose avec les écritures libres, sur le modèle
de `fonds_ecritures_rapprochables()`.

**À l'import d'un relevé : on propose, on ne pose pas** (§ 11). Les
correspondances — montant au centime, signe cohérent, date dans une fenêtre
autour de l'échéance ou de la date de paiement, nom du fournisseur retrouvé dans
`texte`/`tiers` — s'affichent comme suggestions à valider. C'est là que ce
module s'écarte délibérément de `facturation_suggerer_rapprochements()`, qui lie
tout seul : une facture reçue peut arriver en double, et deux factures du même
montant au même fournisseur sont la règle plutôt que l'exception (un abonnement
mensuel). Un lien faux posé en silence se découvre au bouclement, un an plus tard.

**Rattacher ne lettre pas** (§ 11). La catégorie présumée d'une pièce ne sert
qu'à ranger les pièces entre elles. Lettrer et justifier restent deux gestes :
le lettrage a sa mécanique — règles automatiques, puis main — et la brouiller
ferait apparaître une troisième provenance là où `origine_lettrage` n'en connaît
que deux.

## 9. Les fichiers

`lib/fichiers.php`, extrait de `lib/feuille_route.php` sans rien changer à son
comportement : `data/fichiers/`, liste blanche de types vérifiée sur le contenu,
8 Mo, nom de stockage aléatoire, nom d'origine nettoyé pour l'affichage.

**La photo d'un ticket** : `<input type="file" accept="image/*"
capture="environment">` ouvre l'appareil photo sur un téléphone, sans
dépendance. **Réduction côté navigateur avant l'envoi : 1600 px sur le plus
grand côté, JPEG qualité 80** (§ 11) — environ 200 à 400 Ko par ticket, où les
petites lignes restent lisibles, contre 3 à 5 Mo pour l'original. Dix ans
d'archivage légal tiennent alors sans question sur l'hébergement mutualisé, et
la sauvegarde complète reste téléchargeable. Le serveur revalide ce qu'il
reçoit, comme pour l'avatar.

**L'archive annuelle** (§ 11) : un bouton qui rassemble les fichiers d'un
exercice dans un ZIP, chacun nommé par sa date et son libellé, avec un index CSV
reliant chaque pièce à son écriture, sa catégorie et son montant. C'est ce qu'un
vérificateur aux comptes ou une fiduciaire demande, et la sauvegarde complète ne
le remplace pas : elle contient tout, sans tri par année ni lien avec les
écritures. `lib/sauvegarde.php` construit déjà un ZIP à la volée, le procédé est
connu.

**Suppression** : effacer une pièce efface son fichier (sinon `data/fichiers/`
accumule ce que plus rien ne désigne — la leçon de `feuille_supprimer()`).
Effacer une écriture ne touche pas aux pièces : `ON DELETE SET NULL`, elles
redeviennent simplement non rapprochées.

## 10. Les quittances

Une quittance est une pièce `origine = 'emise'` **sans fichier** : Lasso la
produit. Elle constate après coup — argent reçu d'une vente, d'une caisse de
merchandising, d'une cotisation — là où une facture réclame avant. Pas de QR, pas
de délai de paiement : réutiliser la facturation dirait mal la chose.

- **Numérotée `Q-2026-001`**, série propre repartant à 1 chaque année (§ 11).
  Distincte de celle des factures : le préfixe dit tout de suite de quel
  document il s'agit, et aucune des deux séries n'a de trou.
- **Imprimable** par `render_bare()`, sur le modèle de `facture_rappel_imprimer`.
- **Le bénéficiaire est une structure OU une personne** — d'où la dépendance à
  `SPEC_PERSONNES.md`, et la place des quittances après lui dans le plan.

## 11. Ce qui a été tranché

| Question | Réponse |
| --- | --- |
| Cardinalité pièce ↔ écriture | **Plusieurs pièces → une écriture**, par clé étrangère. Les acomptes se saisissent en deux pièces |
| Place dans l'interface | **Un cinquième onglet « Pièces »** |
| Rapprochement à l'import | **Proposé, validé à la main** — jamais posé d'office |
| Quittances | **Document Lasso numéroté**, à une structure ou à une personne |
| Pièce réglée hors du compte suivi | **Un mode de paiement `hors_compte`**, qui classe la pièce sans écriture. Et, surtout, **une pièce se marque payée avant que l'écriture existe** — les relevés n'arrivent qu'une fois par mois |
| Pièce créant son écriture | **Non.** Une écriture se saisit dans les écritures |
| Lettrage au rapprochement | **Aucun.** Lettrer et justifier restent deux gestes |
| Types de pièce | `facture_recue`, `ticket`, `quittance`, `autre` (contrat, décompte, attestation) |
| Numérotation des quittances | **Série propre annuelle**, `Q-2026-001` |
| Réduction des photos | **1600 px, JPEG qualité 80** |
| Droits | **Un module `pieces`, `requires: ['compta']`** : écriture sur `pieces` pour déposer, lecture sur `compta` pour voir les écritures |
| Sortie pour le bouclement | **Une archive ZIP par année**, avec index CSV |

## 12. Plan de livraison

Chaque étape est livrable seule et laisse l'application en état de marche.

1. **Socle** — `lib/fichiers.php` extrait de la feuille de route, module
   `pieces` et ses droits, table `pieces`, `?p=pieces` (liste, création,
   téléversement, photo réduite), `?p=piece`, route de service du fichier,
   onglet. *Déjà utile sans aucun lien : la liste de ce qu'il y a à payer.*
2. **Le lien et les trois états** — `date_payee`, `mode_paiement`,
   `ecriture_id`, les deux fenêtres de rapprochement, la colonne et le filtre
   dans `?p=compta_ecritures`, `ecriture_piece_etat()` et son test.
3. **Les dispenses** — `plan_comptes.piece_attendue`,
   `ecritures.piece_dispense_motif`, et la vue « ce qui n'a pas de pièce ».
4. **La suggestion à l'import** — l'écran de validation des correspondances.
5. **L'archive annuelle et les finitions** — ZIP + index CSV, pastille de
   l'onglet, carte du tableau de bord, colonne dans l'export CSV.
6. **Les personnes** — chantier à part, voir `SPEC_PERSONNES.md`.
7. **Les quittances** — édition, numérotation, impression.

## 13. Ce qui reste ouvert

- **La volumétrie de `data/fichiers/`** sur dix ans d'archivage légal, face au
  quota de l'hébergement mutualisé et au poids de la sauvegarde complète. À
  mesurer après quelques mois d'usage réel plutôt qu'à supposer maintenant — la
  réduction à 1600 px rend le sujet peu probable, elle ne le ferme pas.
- **L'onglet Pièces pour qui n'a pas la comptabilité** (§ 7) : le seul point du
  dispositif de droits où un onglet doit survivre à son module parent.
