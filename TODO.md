# Module comptabilité — feuille de route

Module de comptabilité de caisse (petite association suisse). Contraintes à garder :
**PHP 8 + SQLite, sans dépendance**, mono-devise CHF, modèle recettes/dépenses
(pas de double entrée). Code : `lib/compta.php`, `lib/routes_compta.php`,
`views/compta_*.php`, schéma dans `lib/db.php`.

## Bugs à corriger

- Plan comptable : Aligner les icônes « Modifier » à droite avec les autres icônes
- Comptes bancaires : Comme pour le plan comptable, les données de « Comptes bancaires » ne doivent pas être modifiables par défaut, il faut utiliser une icône « modifier », alignée à droite, pour transformer les champs de lecture seul en modifiable
- Plan comptable & Comptes bancaires & Etat du patrimoine : Le bouton « Nouveau » doit être aligné à droite du titre. Quand on clique dessus une nouvelle ligne modifiable s’ajoute.


## ✅ Lot 1 — terminé (juin 2026)

> Implémenté et vérifié (tests `compta_test.php` + navigateur). Migrations 8 & 9.

### 1. Lettrage : suggestion automatique + extraction du tiers
- [x] **Extraction du donneur d'ordre / tiers** à l'import : nom de la contre-partie
  (« DONNEUR D'ORDRE: … » / « EXPÉDITEUR: … » / nom après DÉBIT/CRÉDIT) et la
  communication, stockés en colonnes dédiées de `ecritures`. Backfill des écritures
  existantes.
- [x] **Suggestion de catégorie** sur les écritures non lettrées : (a) règle qui
  *aurait* matché, sinon (b) catégorie la plus fréquente déjà utilisée pour le même
  tiers. Affichée en grisé, **avec validation** (bouton ✓ par ligne + « valider toutes
  les suggestions »).

### 2. Plan comptable : drag-and-drop, archivage, suppression intelligente
- [x] **Glisser-déposer** pour réordonner et re-rattacher (décalage horizontal = niveau,
  vertical = position), renommage via crayon, persistance par POST de l'arbre.
- [x] **Archiver / réactiver** une catégorie (colonne `actif`) : masquée du lettrage et
  des règles, conservée dans l'historique, affichée grisée dans l'éditeur.
- [x] **Suppression d'une catégorie avec écritures** : boîte de dialogue « que faire des
  écritures déjà classées ? » → *supprimer le lettrage* ou *réaffecter à une autre
  catégorie*. (Remplace l'ancienne fonction « fusionner ».)

### 3. Règles : aperçu d'impact, activer/désactiver, condition de montant
- [x] **Aperçu « toucherait N écritures »** par règle (et au moment de la création),
  calculé sur les écritures non lettrées.
- [x] **Activer / désactiver** une règle (colonne `actif` déjà présente, déjà filtrée par
  `appliquer_regles`).
- [x] **Condition de montant** : champs `montant_min` / `montant_max` (plage) honorés par
  `regle_match`.

### 4. Import : annulation
- [x] **Annuler un import** (supprime l'import et ses écritures — base déjà en place) :
  fiabiliser avec une confirmation qui indique le nombre d'écritures et **avertit si
  certaines sont lettrées**.

### 5. Bilan : comparatif multi-années
- [x] **Compte de résultat sur plusieurs années** (colonnes N / N-1 / … comme le fichier
  Souka d'origine), en plus du patrimoine déjà multi-années.

### 6. Patrimoine : report à nouveau automatique
- [x] **Solde d'ouverture = solde de clôture de l'année précédente** : ligne « au 1er
  janvier », et **contrôle de continuité** (alerte si le 1er solde importé de l'année N
  ne correspond pas à la clôture N-1 → export manquant / trou).

### 8. Sauvegarde depuis Paramètres → Exporter
- [x] **Bouton de sauvegarde** de la base (route `backup` existante, `VACUUM INTO` =
  inclut déjà les tables compta) ajouté dans **Paramètres → Exporter les données**
  (`views/export.php`), avec une note expliquant ce que contient le fichier.

---

## 📋 Plan de développement — Lot 1

Ordre conseillé (chaque étape se termine par `php -l` + `php tests/compta_test.php` +
vérification navigateur via les outils preview ; aucun commit sans demande).

### Étape A — Fondations & quick wins
1. **Migration `migration_8`** : ajoute `ecritures.tiers`, `ecritures.communication`
   (+ éventuellement `tiers_iban`). Idempotente (ALTER après vérif de colonne).
2. **`extraire_tiers(string $texte): array`** dans `lib/compta.php` (fonctions pures,
   regex sur les motifs PostFinance) + **tests** dédiés.
3. **Population** : appeler l'extraction dans `compta_inserer_ecritures()` ; **backfill**
   des écritures existantes dans la migration (UPDATE ligne à ligne).
4. **Sauvegarde (8)** : lien/bouton dans `views/export.php` → `?p=backup`. Trivial.
5. **Annulation d'import (4)** : confirmation enrichie (compte + alerte si lettrées) sur
   la section `del` existante de `route_compta_import`.

→ Livrable : tiers extrait et visible, sauvegarde et annulation propres. Fondation pour B.

### Étape B — Règles & plan comptable (CRUD/UI)
6. **Migration `migration_9`** : `regles_lettrage.montant_min` / `montant_max` (REAL,
   nullable). Étendre `regle_match()` (plage de montant) + **tests**.
7. **Règles (3)** : champs min/max + bascule actif/inactif dans `views/compta_regles.php`
   et `route_compta_regles` ; **compteur d'impact** via une fonction
   `compter_impact_regle()` (écritures non lettrées correspondantes).
8. **Plan comptable (2)** :
   - **Archivage** : section `archive`/`unarchive` (toggle `actif`) + affichage grisé.
   - **Fusion** : section `merge` (re-lettrage A→B, réaffectation des règles, suppression
     de A) avec garde « feuilles uniquement ».
   - **Glisser-déposer** : DnD HTML5 vanilla → POST `reorder` de la liste
     `(id, parent_id, ordre)` ; conserver les flèches en repli accessible.

→ Livrable : gestion du plan et des règles complète.

### Étape C — Suggestion de catégorie
9. **`suggerer_categories(array $ecritures): array`** dans `lib/compta.php` : d'abord les
   règles (`appliquer_regles` en lecture seule), sinon l'historique par `tiers`
   (catégorie la plus fréquente). Renvoie `id => [plan_compte_id, raison]`. **Tests**.
10. **UI lettrage** : afficher la suggestion grisée par ligne + bouton ✓, et une action
    groupée « Valider toutes les suggestions » (un POST réutilisant la section `lettrer`).

→ Livrable : lettrage assisté (dépend de l'étape A pour la partie « par tiers »).

### Étape D — Reporting
11. **Comparatif multi-années (5)** : étendre `compta_bilan_data()` pour renvoyer les
    sommes par (année, catégorie) ; rendre une colonne par année dans le compte de
    résultat (réutiliser `plan_sous_total` par année). Adapter l'impression.
12. **Report à nouveau (6)** : ligne « solde au 1.1 » dans le patrimoine + contrôle de
    continuité N-1 → N (alerte si écart).

→ Livrable : bilan & résultat comparatifs avec contrôle de cohérence.

### Risques / points d'attention
- **Backfill** des tiers sur écritures existantes : faire dans la migration, testée sur
  les CSV d'exemple.
- **Fusion / archivage** : ne jamais casser une écriture lettrée — re-lettrer avant de
  supprimer ; transactions SQLite.
- **DnD** : garder une solution sans build et une **alternative clavier/flèches**
  (accessibilité + repli si JS off).
- **Idempotence** des migrations (gate `PRAGMA user_version`).

---

## 🗂️ Backlog (plus tard)

Repère : **P2** utile · **P3** confort.

### Lettrage
- [ ] P2 — Raccourcis clavier (navigation + validation rapide).
- [ ] P2 — Mémoriser le dernier filtre (compte/année/statut).
- [ ] P3 — Annuler le dernier lettrage de masse.
- [ ] P3 — Note libre / n° de pièce par écriture.

### Plan comptable
- [ ] P2 — Numéros de compte optionnels (champ `code`, tri par code).
- [ ] P3 — Modèles de plan importables (culturelle / sportive…).

### Règles
- [ ] P2 — Condition sur le compte de contre-partie (IBAN expéditeur).
- [ ] P3 — Réordonner les règles par glisser-déposer.
- [ ] P3 — Détection de règles redondantes / jamais déclenchées.

### Import
- [ ] P2 — Aperçu des lignes (et doublons) avant écriture en base.
- [ ] P2 — Formats CAMT.053 (ISO 20022) et autres banques (BCGE, Raiffeisen…).
- [ ] P2 — Contrôle de chaînage des soldes (détecter une écriture manquante).
- [ ] P3 — Import par glisser-déposer du fichier.
- [ ] P3 — Écritures manuelles (caisse espèces, régularisations).

### Bilan & reporting
- [ ] P2 — Filtre par période (trimestre / semestre).
- [ ] P2 — Budget vs réalisé (budget par catégorie + écart).
- [ ] P2 — Graphiques SVG inline (répartition charges/produits).
- [ ] P3 — Ventilation analytique (axe projet : label / tour / stages / local).
- [ ] P3 — Export du résultat en CSV/Excel.

### Clôture d'exercice
- [ ] P2 — Verrouiller un exercice clôturé (écritures non modifiables).
- [ ] P3 — Postes de patrimoine calculés (créances/dettes).

### Intégration Lasso
- [ ] P2 — Rapprocher « Tour - Salaires » des fiches de salaire (alertes de cohérence).
- [ ] P3 — Pré-remplir les charges sociales depuis le module paie.

### Robustesse / sécurité
- [ ] P2 — Journal d'audit léger des lettrages de masse.
- [ ] P2 — Tests des routes `compta_*` (anti-cycle, normalisation du sens, cas limites CSV).
- [ ] P3 — Validation IBAN (clé mod-97) à la saisie d'un compte.

### Confort / UX
- [ ] P2 — Tableau de bord compta (soldes, « à lettrer », résultat de l'année).
- [ ] P2 — Recherche globale des écritures (toutes années / comptes) + export.
- [ ] P3 — Impression de l'extrait lettré d'un compte (équivalent G25/L25).
- [ ] P3 — Vérifier les écrans compta sur mobile.
