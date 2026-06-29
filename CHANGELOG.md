# Journal des versions

Toutes les modifications notables de Lasso. Format inspiré de
[Keep a Changelog](https://keepachangelog.com/fr/) ; versionnage
[sémantique](https://semver.org/lang/fr/).

Les nouveautés arrivent d'abord sur le canal **test** (section « Non publié »),
puis sont promues sur le canal **stable** en figeant une version.

## [Non publié] — canal test

### Ajouté
- **Mise à jour en un clic** depuis Paramètres → Mises à jour : téléchargement de
  l'archive du canal et remplacement des fichiers en PHP pur (pour les hébergements
  sans `exec()`/`git`), avec sauvegarde de la base et journal `data/maj.log`.
- **Diagnostic du serveur** (téléchargement / décompression / écriture) déterminant
  la méthode de mise à jour possible.
- Détection de version compatible **dépôt privé** (jeton `MAJ_TOKEN` optionnel).

### Modifié
- Paramètres réorganisés : « Unités de temps » fusionnées dans **Salaires horaires** ;
  onglet « Taux des déductions » renommé **Taux**.

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
