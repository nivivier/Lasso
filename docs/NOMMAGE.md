# Nommage des routes, des vues et des fonctions

État : **proposition**, non appliquée. À valider avant toute exécution.

L'application a grandi par couches successives : chaque module a apporté ses
habitudes, et personne n'a jamais relu l'ensemble d'un coup. Ce document fait
cette relecture, pose une convention, et donne un plan pour l'appliquer.

**Le moment est le bon.** L'application tourne en production avec **un seul
utilisateur**, aucun envoi groupé n'a jamais eu lieu, et rien de ce qu'elle
expose n'a été diffusé largement. Renommer coûte aujourd'hui une poignée de
gestes manuels (§ 5) ; dans un an, avec cinq comptes et des liens partis dans
la nature, ce serait un chantier qu'on n'entreprendrait plus.

Deux conséquences, et c'est ce qui rend l'opération simple : **aucune route
n'est gelée** — on renomme tout, correctement, une fois —, et **on ne garde
aucune redirection** vers les anciens noms. Un signet mort chez le seul
utilisateur de l'application ne vaut pas une table d'alias à entretenir et à
retirer un jour.

---

## 1. Ce qui existe aujourd'hui

| | nombre |
|---|---|
| routes déclarées (`$handlers`, `index.php`) | **165** |
| vues pleines (`views/*.php`, hors partiels) | **74** |
| partiels (`views/_*.php`) | **45** |
| appels `render()` / `render_bare()` / `rendre_fragment()` | **104** |
| occurrences de `?p=<route>` en dur (vues, lib, assets, index) | **738** |
| appels `redirect('<route>')` | **287** |
| citations de routes dans `assets/app.css` (commentaires) | **75** |
| citations de routes dans `docs/` et les `*.md` | **58** |

C'est la dernière ligne qui donne l'ordre de grandeur du travail : **un nom de
route est cité un peu plus de 1 100 fois**, dont une bonne part dans des
commentaires qui mentent silencieusement quand le nom change.

---

## 2. Les huit incohérences

### 2.1 Le nom ne dit pas la chose

| route | ce que c'est |
|---|---|
| `resumes` | le **tableau de bord** |
| `resume` | les **cotisations** d'une année (salaires) |
| `comptes` | les **comptes utilisateurs** |
| `compta_comptes` | les **comptes bancaires** |

`comptes` et `compta_comptes` diffèrent d'une lettre et ne parlent pas de la
même chose. C'est le pire cas du lot.

### 2.2 Deux langues

`_delete`, `_print`, `_save`, `_new`, `_edit`, `_view`, `_form`, `backup`,
`login`, `logout`, `setup` cohabitent avec `_supprimer`, `_imprimer`,
`_enregistrer`, `_ajouter`, `_retirer`, `_lier`, `_delier`, `_modifier`,
`_deplacer`, `_envoyer`, `_emettre`, `_annuler`.

Le projet est en français — interface, commentaires, journal des versions. Les
routes sont le seul endroit où l'anglais subsiste.

### 2.3 Quatre mots pour « défaire »

`_delete` (7 routes), `_supprimer` (1), `_retirer` (1), `_delier` (2). Rien ne
distingue les cas : `structure_contact_delete` détruit un contact,
`structure_tag_retirer` défait un lien — mais `evenement_facture_delier` défait
un lien lui aussi, et `evenement_feuille_supprimer` détruit.

### 2.4 Le suffixe `_liste`, parfois

| avec | sans |
|---|---|
| `facturation_liste`, `evenements_liste` | `fiches`, `employes`, `campagnes`, `spectacles`, `structures`, `postes`, `comptes`, `fonds` |

Et `views/structures.php` porte le suffixe alors que **sa route ne l'a
pas**.

### 2.5 Quatre conventions pour « le formulaire »

`fiche_new` + `fiche_edit` · `employe` (!) · `facturation_form` ·
`campagne_form` · `fonds_campagne_form`.

`employe_voir` est la fiche et `employe` est le formulaire : l'inversion exacte
de tous les autres modules, où le nom nu est la fiche.

### 2.6 Le préfixe de module, à géométrie variable

| module | discipline |
|---|---|
| **Recherche de fonds** | `fonds_*` sans exception — le module le plus récent |
| **Comptabilité** | `compta_*` sauf `import_ecritures` |
| **Facturation** | `facturation_liste`, `facturation_form`, mais `facture`, `facture_*`, `import_factures` |
| **Événements** | `evenement(s)_*`, plus `spectacle(s)*`, `parametres_evenements`, `import_evenements` |
| **Booking** | `structure(s)_*`, `campagne(s)*`, `mailing_*`, `lieux_options`, `parametres_structures`, `parametres_tags` |
| **Salaires** | `fiche(s)_*`, `employe(s)_*`, `taux_horaires`, `unites`, `taux`, `postes`, `certificat*`, `resume`, `import_fiches` |

### 2.7 Trois sortes de campagnes, un seul mot

| route | de quoi parle-t-elle |
|---|---|
| `campagnes`, `campagne`, `campagne_form`, `campagne_reponse`, `structure_campagne` | une campagne de **démarchage** (booking) |
| `fonds`, `fonds_campagne`, `fonds_campagne_form` | une campagne de **recherche de fonds** |
| `mailing_campagne` | une campagne d'**envoi groupé** |

Le démarchage, arrivé le premier, a pris le mot nu. Les deux autres ont dû se
préfixer. Résultat : `?p=booking_campagne` ne dit pas de laquelle il s'agit, et c'est
la seule des trois qui ne le dit pas — l'ancienneté n'est pas une raison.

Le piège est le même que celui déjà rencontré à l'écran, où la carte du tableau
de bord a été renommée « Booking » et la colonne des structures « Campagnes de
booking » : **là où les deux sortes se croisent, le nom doit dire laquelle.**
Les URL n'ont pas encore suivi.

Deuxième anomalie du même endroit : `?p=fonds_campagnes` est la **liste des campagnes**
de recherche de fonds — un pluriel qui manque (N2), et un nom de module servant
de nom d'écran.

### 2.8 Les paramètres, moitié préfixés

`parametres`, `parametres_pays`, `parametres_structures`, `parametres_tags`,
`parametres_evenements`, `parametres_modules` — mais `employeur`, `emails`,
`emails_booking`, `apparence`, `comptes`, `maj`, `diagnostic`, `export` sont
eux aussi des pages de Paramètres, sans préfixe.

---

## 3. La convention

**N1 — Tout en français.** Aucun mot anglais dans un nom de route, de vue ou de
fonction. Les verbes sont à l'infinitif.

**N2 — Pluriel = la liste, singulier = une fiche.** `?p=fiches` montre la liste,
`?p=fiche&id=` montre l'une d'elles. Le suffixe `_liste` disparaît.

**N3 — Un formulaire plein écran : `<objet>_form`.** Créer et modifier passent
par la même route, `id=0` ou absent valant création.

**N4 — Une route qui ÉCRIT porte un verbe : `<objet>_<verbe>`.** C'est
l'invariante du projet rendue lisible dans l'URL (GET = lecture, POST =
écriture). Vocabulaire fermé :

| verbe | sens |
|---|---|
| `_supprimer` | détruire l'objet |
| `_retirer` | défaire un lien, sans rien détruire |
| `_ajouter` / `_lier` | poser un lien |
| `_enregistrer` | écrire les champs d'un formulaire |
| `_imprimer` | rendre la version imprimable |
| `_envoyer` | expédier un e-mail |
| `_importer` / `_exporter` | lire ou produire un fichier |

Exception assumée : une route qui pose ET défait le même lien selon un
paramètre (`structure_campagne`, `action=retirer`) garde son nom de paire, sans
verbe — le verbe serait faux la moitié du temps.

**N5 — L'impression et les exports.** `<objet>_imprimer` ;
`<objet>_exporter_<format>` pour un fichier (`xml`, `pdf`, `csv`, `camt053`,
`json`, `ical`). La forme longue est retenue parce qu'elle dit le **geste** :
`certificat_exporter_xml` plutôt que `certificat_xml`, qui ne dit que le
format.

**N6 — Le préfixe de module est obligatoire quand le nom nu serait ambigu**, et
seulement là. `fiche` n'a pas besoin de `salaires_` ; les comptes bancaires
gardent `compta_`.

Le cas d'école est **« campagne »**, que trois modules emploient. Aucun des
trois ne garde le mot nu — pas même le démarchage, qui l'avait pris le
premier : `booking_campagne`, `fonds_campagne`, `mailing_campagne`. Une route
qui s'appelle `campagne` tout court n'existe plus. C'est la règle du § 2.7
appliquée aux URL : là où les deux sortes se croisent, le nom doit dire
laquelle.

À l'inverse, `structures` reste nu : la liste est **partagée** par trois
modules, elle n'appartient à aucun. Un préfixe y mentirait.

**N7 — Pas de préfixe `parametres_`.** Une page de réglages porte le nom de ce
qu'elle règle : `pays`, `tags`, `modules`, `employeur`, `utilisateurs`. Le
bandeau d'onglets dit déjà qu'on est dans les Paramètres ; le répéter dans
l'URL n'apprend rien. **Quand ce nom est déjà pris par un écran du module**, on
suffixe `_reglages` : `evenements_reglages` (le `evenements` nu est la liste).
Un seul cas aujourd'hui.

**N8 — Le fichier de vue porte le nom de la route qui le rend.** Les partiels
gardent leur `_` initial et le nom de ce qu'ils rendent. `layout.php` reste.

**N9 — La fonction porte le nom de la route : `route_<route>()`.** C'est déjà
vrai presque partout ; cela le devient partout.

**N10 — Pas d'abréviation**, sauf celles que l'interface emploie déjà telles
quelles : `maj`, `suisa`, `lpp`, `laa`, et les noms de formats (`csv`, `pdf`,
`xml`, `json`, `ical`, `camt053`).

---

## 4. Les renommages

### 4.1 Entrée et noyau

| actuel | proposé |
|---|---|
| `setup` | `installation` |
| `login`, `logout` | `connexion`, `deconnexion` |
| `motdepasse_reinit` | `motdepasse_reinitialiser` |
| `resumes` | `tableau_bord` |
| `compte` *(son propre compte)* | `mon_compte` |
| `backup` | `sauvegarde` |
| `recherche`, `motdepasse_oublie`, `dev` | inchangées |

### 4.2 Salaires

| actuel | proposé |
|---|---|
| `fiche_new`, `fiche_edit` | `fiche_form` |
| `fiche_print` | `fiche_imprimer` |
| `fiche_delete` | `fiche_supprimer` |
| `fiche_date` | `fiche_paiement` |
| `fiche_email` | `fiche_envoyer` |
| `fiche_cout` | `fiche_cout_employeur` |
| `fiches_recalcul` | `fiches_recalculer` |
| `employe_voir` | `employe` |
| `employe` *(le formulaire)* | `employe_form` |
| `employe_delete` | `employe_supprimer` |
| `employe_avatar` | `employe_photo` |
| `certificat_print` | `certificat_imprimer` |
| `certificat_xml` | `certificat_exporter_xml` |
| `resume` | `cotisations` |
| `unites`, `taux` | **supprimées** (déjà de simples redirections) |
| `import_fiches` | `fiches_importer` |
| `fiches`, `fiche`, `postes`, `taux_horaires`, `certificat` | inchangées |

### 4.3 Comptabilité

| actuel | proposé |
|---|---|
| `compta` | **supprimée** (redirection) |
| `compta_lettrage` | **supprimée** (doublon exact de `compta_ecritures`) |
| `compta_bilan_print` | `compta_bilan_imprimer` |
| `compta_analyse_print` | `compta_analyse_imprimer` |
| `compta_analyse_axe_print` | `compta_analyse_axe_imprimer` |
| `compta_ecritures_csv` | `compta_ecritures_exporter_csv` |
| `compta_ecritures_camt053` | `compta_ecritures_exporter_camt053` |
| `compta_ventilation_save` | `compta_ventilation_enregistrer` |
| `compta_import` | `compta_ecritures_importer` |
| `import_ecritures` | `compta_ecritures_importer_valider` |
| `compta_suggestion_ventilation`, `compta_suggestion_preview` | `compta_ventilation_suggestion`, `compta_ventilation_suggestion_apercu` |

### 4.4 Facturation

| actuel | proposé |
|---|---|
| `facturation` | **supprimée** (redirection) |
| `facturation_liste` | `factures` |
| `facturation_form` | `facture_form` |
| `facture_delete` | `facture_supprimer` |
| `facture_pdf` | `facture_exporter_pdf` |
| `facture_email` | `facture_envoyer` |
| `facture_payee` | `facture_paiement` |
| `facture_rappel` | `facture_rappel_imprimer` |
| `facture_ligne_axe` | `facture_ligne_axe_enregistrer` |
| `import_factures` | `factures_importer` |
| `facture`, `facture_emettre`, `facture_annuler` | inchangées |

### 4.5 Booking

| actuel | proposé |
|---|---|
| `campagnes` | `booking_campagnes` |
| `campagne` | `booking_campagne` |
| `campagne_form` | `booking_campagne_form` |
| `campagne_enregistrer` | `booking_campagne_enregistrer` |
| `campagne_delete` | `booking_campagne_supprimer` |
| `campagne_reponse` | `booking_campagne_reponse_enregistrer` |
| `structure_campagne` | `booking_campagne_structure` *(pose ET défait : pas de verbe, N4)* |
| `structures_options`, `lieux_options` | `structures_json`, `lieux_json` *(réponses JSON, pas des écrans)* |
| `structure_contact_delete` | `structure_contact_supprimer` |
| `structure_lieu_delier` | `structure_lieu_retirer` |
| `structure_localisation` | `structure_localisation_enregistrer` |
| `structure_message` | `structure_message_envoyer` |
| `import_structures` | `structures_importer` |
| `parametres_structures` | `categories_structures` |
| `parametres_tags` | `tags` |
| `structures`, `structure`, le reste de `structure_*`, `mailing_*` | inchangées |

`booking_` ne s'applique **qu'à la famille « campagne »** : c'est le seul mot
que trois modules se disputent. Les structures, partagées, gardent leur nom nu.

### 4.6 Événements

| actuel | proposé |
|---|---|
| `evenements` | **supprimée** (redirection) |
| `evenements_liste` | `evenements` |
| `evenement_delete` | `evenement_supprimer` |
| `evenement_informations` | `evenement_informations_enregistrer` |
| `evenement_localisation` | `evenement_localisation_enregistrer` |
| `evenement_organisation` | `evenement_organisation_enregistrer` |
| `evenement_suisa` | `evenement_suisa_enregistrer` |
| `evenement_production_externe` | `evenement_production_externe_enregistrer` |
| `evenement_employe_delier` | `evenement_employe_retirer` |
| `evenement_facture_delier` | `evenement_facture_retirer` |
| `evenement_feuille_email` | `evenement_feuille_envoyer` |
| `evenement_fichier` | `evenement_feuille_fichier` |
| `evenements_export_suisa` | `evenements_suisa_exporter` |
| `evenements_export_suisa_apercu` | `evenements_suisa_exporter_apercu` |
| `evenements_json` | `evenements_exporter_json` |
| `evenements_ical` | `evenements_exporter_ical` |
| `evenements_equipe_ical` | `evenements_equipe_exporter_ical` |
| `spectacles` | `projets` |
| `spectacle` | `projet` |
| `spectacle_delete` | `projet_supprimer` |
| `spectacle_image` | `projet_icone` |
| `import_evenements` | `evenements_importer` |
| `parametres_evenements` | `evenements_reglages` |

#### Pourquoi « projet » et pas « spectacle »

Le mot visible est **réglable par installation**
(`evenements_terme_spectacle`), et sa valeur semée est « Projets » depuis le
module de recherche de fonds : une campagne finance un *projet*, qui n'est pas
toujours un spectacle. Le code doit malgré tout figer **un** mot — une route ne
peut pas être configurable. Autant figer celui que l'application propose par
défaut.

Conséquence à ne pas oublier : le **repli en dur** de
`evenements_terme_spectacle()` vaut encore `'Spectacles'`. Le laisser ferait
afficher « Spectacles » sur une page dont l'URL dit `?p=projets`. Il passe à
`'Projets'` dans la même livraison.

**Quatre couches, quatre décisions séparées** — le mot vit à quatre
profondeurs, et elles ne coûtent pas la même chose :

| couche | ce que c'est | volume | risque |
|---|---|---|---|
| **1. Routes, vues, fonctions `route_*()`** | `?p=spectacles`, `views/projet_form.php` | 20 citations `?p=`, 12 `redirect()`/`render()`, 2 fichiers, 4 fonctions | nul — **on la fait** |
| **2. Identifiants PHP** | `spectacle_map()`, `$spectacles`, `spectacle_nom`, `spectacleId` | ~700 occurrences | nul, purement mécanique — **on la fait**, dans la même livraison |
| **3. Le schéma** | table `spectacles`, colonne `evenements.spectacle_id`, et quatre tables de liaison (`campagne_spectacles`, `historique_spectacles`, `mailing_modele_spectacles`, `fonds_campagne_spectacles`) | 5 tables, 5 colonnes, 3 index | **on la fait aussi** — la version de SQLite de l'hébergeur a été relevée : 3.34, voir ci-dessous |
| **4. La clé `spectacle` de l'export JSON** | contrat avec le site web qui le consomme | 1 clé | contrat externe — § 5 |

#### La couche 3 : le schéma

L'hébergeur est en **SQLite 3.34**. C'est au-dessus des deux seuils qui
comptent : `ALTER TABLE … RENAME TO` reporte le nouveau nom dans les clauses
`REFERENCES` des autres tables depuis **3.25**, et `ALTER TABLE … RENAME
COLUMN` existe depuis **3.25** également. Seul `DROP COLUMN` demande 3.35 — et
ce renommage n'en a pas besoin.

**On renomme donc, on ne duplique pas.** Dupliquer serait plus risqué, pas
moins : un `DROP TABLE` ne déclenche aucune réécriture ailleurs
(`docs/DECISIONS.md § Migrations SQLite`), si bien que les quatre tables de
liaison **et `evenements`** garderaient un `REFERENCES spectacles(id)` pointant
vers une table disparue. Il faudrait alors reconstruire ces six tables — dont
`evenements`, dont les colonnes se sont accumulées sur quatre-vingt-dix
migrations et qu'il faudrait redéclarer à la main. Le renommage, lui, tient en
une instruction par objet.

Le comportement sur lequel on s'appuie est **celui que le projet avait consigné
comme un piège** : `RENAME TO` réécrit les `REFERENCES` des autres tables, même
avec `PRAGMA foreign_keys = OFF`. Ce qui cassait `migration_21` est exactement
ce qu'on veut ici.

**Essayé sur une base construite par le schéma et les migrations de
l'application** (table renommée, colonnes renommées, index refaits, avec des
données) : les clés étrangères des quatre tables de liaison et d'`evenements`
pointent sur `projets`, **l'auto-référence `projets.parent_id` comprise** ;
`PRAGMA foreign_key_check` ne rend rien ; plus aucune occurrence de
« spectacle » dans `sqlite_master` ; les données sont intactes.

Trois détails qui viennent avec :

- **`lib/db/schema.php` n'est pas touché** : `spectacles` naît dans
  `migration_23`, pas dans le schéma de base. Une installation neuve joue donc
  la création **puis** le renommage, comme une installation existante — une
  seule source de vérité, pas deux schémas à tenir d'accord.
- **Les index se refont** : SQLite n'a pas de `RENAME INDEX`. Trois sont
  concernés (`idx_spectacles_parent`, `idx_evenements_spectacle`,
  `idx_historique_spectacles_spectacle`) ; les index implicites des clés
  primaires suivent le renommage tout seuls.
- **Les icônes déjà envoyées gardent leur nom de fichier** `spectacle_*.jpg` :
  le chemin est une donnée, pas un identifiant. Les suivantes s'appelleront
  `projet_*`. `uploads/` portera les deux préfixes, sans conséquence.

Et une précaution propre à cette migration : `run_migrations()` n'enveloppe pas
ses étapes dans une transaction, et une étape qui échoue à mi-chemin laisse
`PRAGMA user_version` en arrière — toute requête HTTP plante alors sur `db()`.
Celle-ci ouvre donc **sa propre transaction**, vérifie avant de valider
(plus aucun « spectacle » dans `sqlite_master`, `foreign_key_check` vide) et
annule tout sinon. C'est la première migration du projet à en avoir besoin ;
c'est aussi la première à toucher six tables d'un coup.

#### Le terme réglable : deux paramètres au lieu d'un

Le terme est aujourd'hui **un seul** paramètre, au pluriel, dont le singulier
est deviné en retirant un « s » final. Le français ne se plie pas à cette
règle — « Festival » / « Festivals », « Spectacle musical » / « Spectacles
musicaux » —, et la devinette se trompe aussi dans l'autre sens : une valeur
saisie au singulier ressort telle quelle au pluriel. C'est exactement ce qui
s'est produit, la base de développement portant « Projet » là où l'écran
attendait « Projets ».

| | |
|---|---|
| `evenements_terme_spectacle` | → `evenements_terme_projet` *(le pluriel)* |
| *(nouveau)* | `evenements_terme_projet_singulier` |

`evenements_terme_spectacle()` devient `evenements_terme_projet()`, garde sa
signature `(bool $pluriel = true)` — les 19 appels existants n'ont que leur nom
à changer — et lit l'un ou l'autre paramètre. **Plus aucune dérivation
automatique.** Replis en dur : `'Projets'` et `'Projet'`.

La clé de paramètre bouge donc, contrairement à ce qu'on avait d'abord écrit :
l'argument était qu'elle est stockée en base et que la renommer perdrait le
réglage — mais cette livraison écrit déjà une migration, et reporter une ligne
de `parametres` y tient en une instruction. Ce qui restait vrai tant qu'on ne
touchait pas au schéma ne l'est plus une fois qu'on y touche.

La migration reprend la valeur existante **comme pluriel**, et sème le
singulier avec l'ancienne règle comme point de départ. Là où le français ne la
suit pas, la correction se fait à la main — y compris pour la base de
développement, dont le « Projet » actuel deviendra un pluriel à corriger.

La page de réglages gagne un second champ ; les deux disent **au singulier** et
**au pluriel** dans leur étiquette, ce silence étant à l'origine de la
confusion.

### 4.7 Recherche de fonds

Le module est né après l'essentiel de la convention, mais deux règles lui
manquent encore : le pluriel d'une liste (N2) et le verbe d'une route qui
écrit (N4).

| actuel | proposé |
|---|---|
| `fonds` *(la liste des campagnes)* | `fonds_campagnes` |
| `fonds_pieces` | `fonds_bailleur_pieces_enregistrer` |
| `fonds_versement` | `fonds_versement_enregistrer` |
| `fonds_structure_campagne` | `fonds_campagne_structure_ajouter` *(ajoute seulement — d'où le verbe, contrairement à son jumeau du booking)* |
| `fonds_campagne`, `fonds_campagne_form`, `fonds_campagne_enregistrer`, `fonds_demande`, `fonds_demande_enregistrer` | inchangées |

Le préfixe `fonds_` est aussi ce qui évite la collision avec `recherche`, la
recherche unifiée : le module s'appelle « Recherche de fonds » à l'écran, mais
aucune de ses routes ne commence par `recherche`.

### 4.8 Paramètres (sans préfixe, N7)

| actuel | proposé |
|---|---|
| `parametres` | **supprimée** (redirection vers `employeur`) |
| `parametres_modules` | `modules` |
| `parametres_pays` | `pays` |
| `comptes` | `utilisateurs` |
| `compte_modifier` | `utilisateur_enregistrer` |
| `compte_delete` | `utilisateur_supprimer` |
| `employeur`, `emails`, `emails_booking`, `apparence`, `apparence_fond_supprimer`, `maj`, `diagnostic`, `export` | inchangées |

`comptes` → `utilisateurs` lève à lui seul la collision du § 2.1 :
`utilisateurs` d'un côté, `compta_comptes` de l'autre, et plus rien entre les
deux.

### 4.9 Vues

Après N8 :

| actuel | proposé |
|---|---|
| `resumes.php` | `tableau_bord.php` |
| `campagnes.php` | `booking_campagnes.php` |
| `campagne.php` | `booking_campagne.php` |
| `campagne_form.php` | `booking_campagne_form.php` |
| `fonds.php` | `fonds_campagnes.php` |
| `resume.php` | `cotisations.php` |
| `structures_liste.php` | `structures.php` |
| `facturation_liste.php` | `factures.php` |
| `facturation_voir.php` | `facture.php` |
| `facturation_form.php` | `facture_form.php` |
| `facturation_rappel_print.php` | `facture_rappel_imprimer.php` |
| `fiche_view.php` | `fiche.php` |
| `fiche_print.php` | `fiche_imprimer.php` |
| `employe_voir.php` | `employe.php` |
| `evenement_form.php` | `evenement.php` |
| `evenement_feuille_print.php` | `evenement_feuille_imprimer.php` |
| `evenements_export_suisa_print.php` | `evenements_suisa_exporter_imprimer.php` |
| `structure_form.php` | `structure.php` |
| `spectacles.php` | `projets.php` |
| `spectacle_form.php` | `projet_form.php` |
| `certificat_print.php` | `certificat_imprimer.php` |
| `compta_bilan_print.php` | `compta_bilan_imprimer.php` |
| `compta_analyse_print.php` | `compta_analyse_imprimer.php` |
| `compta_analyse_axe_print.php` | `compta_analyse_axe_imprimer.php` |
| `parametres_structures.php` | `categories_structures.php` |
| `parametres_tags.php` | `tags.php` |
| `parametres_pays.php` | `pays.php` |
| `parametres_modules.php` | `modules.php` |
| `parametres_evenements.php` | `evenements_reglages.php` |
| `comptes.php` | `utilisateurs.php` |
| `compte.php` | `mon_compte.php` |
| `login.php` | `connexion.php` |
| `setup.php` | `installation.php` |
| `motdepasse_reinit.php` | `motdepasse_reinitialiser.php` |

---

## 5. Ce qui est exposé au dehors : à faire à la main

Six routes et quatre sorties de fichier sont renommées comme le reste, mais
chacune a une vie **hors** de l'application. Rien ne cassera visiblement : ce sont des choses qui cessent de
fonctionner en silence. D'où cette liste, à reprendre telle quelle dans le
CHANGELOG de la version qui porte le renommage, sous « ⚠ à faire après la mise
à jour ».

| route | ce qui s'arrête | le geste |
|---|---|---|
| `evenements_ical`, `evenements_json`, `evenements_equipe_ical` | les agendas abonnés **cessent de se mettre à jour**, sans message ni erreur ; idem pour un site web qui consomme le JSON | recopier les liens depuis le menu « Synchroniser » de `?p=spectacles` et les reposer dans chaque agenda abonné, puis sur le site |
| `evenement_fichier` | les pièces jointes des feuilles de route **déjà envoyées par e-mail** deviennent introuvables | renvoyer la feuille si quelqu'un la cherche encore ; sans objet au-delà de quelques semaines |
| `mailing_traiter` | la file d'envoi **s'arrête**, le planificateur appelant une adresse morte | changer l'URL dans le planificateur de tâches de l'hébergeur, **le jour même** |
| `desinscription` | les liens de désinscription des e-mails **déjà partis** mènent à une page morte | aucun envoi groupé n'a eu lieu : la liste des e-mails concernés est vide. Rien à faire — et c'est précisément pour cela qu'on renomme maintenant |
| `backup` | un script de sauvegarde côté hébergeur, s'il en existe un | vérifier ; adapter le cas échéant |
| la clé **`spectacle`** de l'export JSON *(pas une route, mais le même genre de contrat)* | le site web qui lit l'export ne trouve plus le nom du projet, et l'affiche vide | elle est renommée `projet` comme le reste ; **le site sera adapté ensuite**. L'écart dure le temps de cette adaptation |
| l'en-tête **`Spectacle`** de l'export CSV / SUISA | un classeur qui lit la colonne par son nom ne la trouve plus | l'en-tête suit le terme réglé (« Projet »). À vérifier si un tableur s'appuie dessus |
| l'**`UID`** des événements de l'iCal d'équipe (`equipe-spectacle-<id>`) | un agenda déjà abonné verrait des événements *nouveaux* plutôt que modifiés | sans objet : l'URL de l'abonnement change de toute façon, l'agenda repart de zéro |
| `login`, `setup` | les signets du poste de travail | se recréent tout seuls |

Les deux premières lignes sont les seules qui demandent un vrai geste, et c'est
précisément pour elles que le moment est bien choisi : un agenda abonné
aujourd'hui, une poignée demain.

Aucune de ces six routes ne garde de redirection : une adresse morte se voit et
se corrige, une redirection oubliée survit des années.

---

## 6. Pas de redirection : on coupe net

Un renommage classique s'accompagne d'une table d'alias et de redirections 301,
le temps que les signets se corrigent. **Ici, non.** Un seul utilisateur, dont
les signets se refont en un après-midi : une table d'alias serait un second
jeu de noms à écrire, à tester, à documenter — et à retirer un jour, ce qu'on
ne ferait jamais.

Les cinq redirections écrites à la main (`compta`, `facturation`, `evenements`,
`unites`, `taux`) et `parametres` disparaissent du même coup : elles existaient
pour rattraper d'anciens liens qui, après ce chantier, ne pointeront plus nulle
part de toute façon. **Six routes et six fonctions `route_*()` en moins.**

Ce qui reste, et qui est la vraie valeur du chantier, c'est le test.

### `tests/nommage_test.php`

1. toute route respecte la convention du § 3 — une expression régulière par
   famille : une liste est au pluriel, un formulaire finit par `_form`, une
   route POST-seulement porte un verbe du vocabulaire fermé de N4, aucun mot
   anglais, aucune route nue nommée `campagne*` ;
2. toute vue pleine (`views/*.php`, hors `_*` et `layout.php`) porte le nom
   d'une route ;
3. toute route a une fonction `route_<route>()` ;
4. aucun nom de route n'est le préfixe d'un autre **sans séparateur** — le
   garde-fou des recherches-remplacements du § 8.

Sans ce test, la convention se redégradera en deux ans, exactement comme la
première fois. Pendant la migration, il porte une liste d'exceptions — les
routes pas encore passées — qui se vide livraison après livraison. **Quand la
liste est vide, le chantier est fini** ; c'est lui qui mesure l'avancement, pas
une case à cocher.

---

## 7. Le plan, en cinq livraisons

Chacune est livrable seule et laisse l'application entière.

**1. Le test.** `tests/nommage_test.php` avec les règles du § 6 et une liste
d'exceptions aussi longue que l'existant — mais **aucun renommage**. Le test
passe au vert sur le code actuel. C'est le filet qu'on tend avant de marcher
dessus, et la liste d'exceptions est l'inventaire exact de ce qui reste à
faire.

**2. Les vues** (§ 4.9). Aucune URL ne change : seuls des noms de fichiers et
les 104 appels `render()`. Risque quasi nul, et la moitié de la confusion
disparaît (`facturation_voir.php` pour `?p=facture`…).

**3. Les fonctions `route_*()`** là où elles ne suivent pas leur route, et
suppression des six redirections du § 6. Interne : la table de `index.php` et
les définitions.

**4. Les routes, un module par livraison** — Paramètres, Salaires,
Comptabilité, Facturation, Événements, Booking. Pour chacun :
   - renommer la route, sa fonction, sa vue ;
   - passer les `?p=` et `redirect()` du module **et de tout ce qui pointe vers
     lui** (c'est là que le travail est : `?p=structures` est cité 120 fois) ;
   - rayer les exceptions correspondantes dans `tests/nommage_test.php` ;
   - relire les commentaires : 75 citations dans le CSS, 58 dans `docs/`.

   L'ordre va du moins cité au plus cité ; Booking en dernier, qui porte la
   famille « campagne » et ses 25 citations de `?p=booking_campagne*`. La livraison
   **Événements** porte en plus les six gestes du § 5 : elle doit l'annoncer en
   tête de son entrée de CHANGELOG.

**5. Les clés de session et de préférence.** `dashboard_cartes` (préférence par
utilisateur) et les préfixes de filtres (`campagnes_projet_id`, `ecr_annee`…)
portent des noms de routes. Les renommer **réinitialise** le réglage de
l'utilisateur — ordre des cartes du tableau de bord, filtres mémorisés. Donc :
soit on ne les touche pas (et on le note dans le code), soit une migration les
réécrit. **Recommandation : ne pas les toucher**, et poser un commentaire
disant pourquoi ces noms-là survivent au renommage.

---

## 8. Ce que ça coûte, et ce que ça rapporte

**Coût** : environ 1 100 citations à passer, dont ~740 `?p=` et ~290
`redirect()`. L'essentiel est mécanique (une route à la fois), mais trois
pièges le rendent non trivial :

- un nom de route est aussi un **préfixe** (`fiche` est contenu dans `fiches`,
  `fiche_edit`, `fiche_print`…) : tout remplacement doit être ancré ;
- certains noms vivent dans des **chaînes construites**
  (`'?p=' . $route . '&id='`) que la recherche textuelle ne voit pas ;
- les **commentaires** mentent sans provoquer d'erreur — c'est la part du
  travail que rien ne vérifie, sauf une relecture.

**Gain** : une route se devine au lieu de se chercher ; `comptes` et
`compta_comptes` cessent de se confondre ; `?p=booking_campagne` ne laisse plus
deviner de quelle sorte de campagne il s'agit, puisqu'il n'existe plus ; le
test de nommage empêche la dette de revenir. Et chaque fois qu'un écran est
ajouté, la question « il s'appelle comment, déjà ? » a une réponse écrite.

---

## 9. Ce qui a été tranché

| | décision |
|---|---|
| 1 | **Aucune route n'est gelée.** Un seul utilisateur, aucun envoi groupé : c'est maintenant ou jamais. Les six routes exposées sont renommées, avec la liste de gestes du § 5. |
| 2 | `resumes` devient **`tableau_bord`**, pas `accueil`. |
| 3 | La page de son propre compte devient **`mon_compte`** — `compte` tout court était ambigu avec les comptes utilisateurs et les comptes bancaires. |
| 4 | **Le préfixe `parametres_` est retiré partout** : une page de réglages porte le nom de ce qu'elle règle. Suffixe `_reglages` quand ce nom est déjà pris (`evenements_reglages`). |
| 5 | **Forme longue pour les exports** : `certificat_exporter_xml`, pour que le nom dise le geste et pas seulement le format. |
| 6 | `fiche_cout` règle l'affichage du **coût employeur** sur une fiche : `fiche_cout_employeur`. |
| 7 | **Aucune redirection depuis les anciens noms.** Même raison qu'au § 1 : un seul utilisateur, des signets qui se refont en un après-midi. Une table d'alias serait un second jeu de noms à entretenir — et à retirer un jour, ce qu'on ne ferait jamais. Les six redirections déjà écrites à la main disparaissent avec. |
| 8 | **Aucune route ne s'appelle `campagne` tout court.** Trois modules emploient le mot : `booking_campagne`, `fonds_campagne`, `mailing_campagne`. Le démarchage perd le mot nu qu'il avait pris le premier — l'ancienneté n'est pas une raison. |
| 9 | `?p=fonds_campagnes` devient **`fonds_campagnes`** : c'est une liste (N2), et un nom de module ne fait pas un nom d'écran. |
| 10 | **« spectacle » devient « projet »** dans les routes, les vues et les identifiants PHP — le mot visible est réglable, mais le code doit en figer un, et autant figer celui que l'application propose par défaut. Le repli en dur de `evenements_terme_spectacle()` passe de `'Spectacles'` à `'Projets'` dans la même livraison, sans quoi l'écran dirait « Spectacles » sous une URL `?p=projets`. |
| 11 | **Le schéma suit aussi.** L'hébergeur est en SQLite **3.34**, au-dessus du seuil de 3.25 où `RENAME TO` répare les clés étrangères des autres tables et où `RENAME COLUMN` existe. On renomme, on ne duplique pas : dupliquer obligerait à reconstruire six tables, dont `evenements`. Procédure essayée et vérifiée, § 4.6. |
| 12 | **Le terme réglable devient DEUX paramètres**, `evenements_terme_projet` (pluriel) et `evenements_terme_projet_singulier` : le français ne forme pas toujours son pluriel en ajoutant un « s », et la dérivation automatique disparaît. `evenements_terme_spectacle()` devient `evenements_terme_projet()`, même signature. La clé bouge en base — cette livraison écrit déjà une migration, reporter une ligne de `parametres` y tient en une instruction. |
| 13 | **La clé `spectacle` de l'export JSON est renommée `projet`** comme le reste ; le site qui la consomme sera adapté ensuite. L'en-tête `Spectacle` de l'export CSV suit le terme réglé. |
| 14 | **Plus un seul libellé en dur** : les dix-sept endroits qui écrivaient « Spectacle » passent par `evenements_terme_projet()`, chacun choisissant son nombre. Y compris les sorties de fichier — en-tête CSV, ligne par défaut d'une feuille de route, `SUMMARY` iCal. |
