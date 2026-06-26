# Module comptabilité 

## Bugs à corriger en priorité

- Dans la liste des écritures sur la page d’un axe, il doit être possible de cliquer sur le texte résumé pour afficher le contenu complet du texte (même comportement que dans écritures). Penser à factoriser ce code si possible.

## Améliorations prioritaires

- Ventilation multi-axe sur une écriture :
On permet d'affecter une écriture à plusieurs axes (ex. OCAS 1 200 CHF → Tour 480, Label 420, Local 300). L'écriture reste une mais génère plusieurs lignes analytiques.
Préparer la base de données pour gérer cela, et prévoir la transition depuis une base existante.
Interface : Dans la page Ecritures, s’il y a plusieurs Axes, ils apparaissent dans le champ séparés par des virgules. Quand on passe la souris sur le champ Axe, un petit crayon apparaît déjà. On rajoute de la même manière un petit plus (+) qui fait apparaître dans ce champ à chaque fois un couple Axe - Montant à remplir.

- Axe sur fiche de salaire :
Dans la base ajouter la possibilité de donner un axe à une ligne de fiche de salaire. Dans la partie « Prestations » ajouter dans l’ajout/modification d’une fiche de salaire une colonne « Axe » avec un menu déroulant qui permet de choisir un axe par ligne. Dans la vue d’une fiche de salaire, ajouter la colonne axe entre la colonne Salaire et la colonne Détails. Dans la liste des fiches de salaire ajouter une colonne Axe, qui affiche les Axes de cette fiche de salaire.

- Charges prévues dans l’affichage d’un axe :
Dans la page d’un axe, ajouter un tableau entre le compte de résultat et les écritures : « Charges sociales prévues ». Avec dans la colonne de gauche OCAS	LAA	LPP	Impôt à la source, sur la première ligne de titre les années.

- Mises à jour de l’application
Permettre de mettre à jour l’application depuis l’interface web depuis la section paramètres
Il faut pour cela repenser la manière dont est tagué l’app sur git, noter quand quelque chose peut passer en mode « stable », et avoir une numérotation des versions.
Depuis les paramètres on pourra choisir entre deux versions, stable et test. Toutes les modifications que l’on fera seront par défaut sur le canal « test ». L’application pourra vérifier si elle est à jour et se mettre à jour toute seule.


## Idées (à ne pas implémenter pour le moment)

### Lettrage
- [ ] P2 — Mémoriser le dernier filtre (compte/année/statut).
- [ ] P3 — Annuler le dernier lettrage de masse.

### Plan comptable
- [ ] P2 — Numéros de compte optionnels (champ `code`, tri par code).
- [ ] P3 — Modèles de plan importables (culturelle / sportive…).

### Règles

### Import
- [ ] P2 — Aperçu des lignes (et doublons) avant écriture en base.
- [ ] P2 — Formats CAMT.053 (ISO 20022) et autres banques (BCGE, Raiffeisen…).
- [ ] P2 — Contrôle de chaînage des soldes (détecter une écriture manquante).
- [ ] P3 — Écritures manuelles (caisse espèces, régularisations).
- Quand on importe un compte et que l’IBAN n’existe pas encore, demander quel nom lui donner.

### Bilan & reporting
- [ ] P2 — Dans le tableau de bord, graphiques SVG inline (répartition charges/produits par année) de la comptabilité
- [ ] P3 — Ventilation analytique (axe projet : label / tour / stages / local).

### Clôture d'exercice

### Intégration Lasso
- [ ] P2 — Rapprocher « Tour - Salaires » des fiches de salaire (alertes de cohérence).
- [ ] P3 — Pré-remplir les charges sociales depuis le module paie.

### Robustesse / sécurité
- P3 Faire en sorte que l’app fonctionne de manière modulaire (coeur/salaires/comptabilité, et à venir facturation), avec dans les paramètres la possibilité d’activer/désactiver un module

### Confort / UX
- [ ] P2 — Tableau de bord : ajouter une partie compta (soldes à la date du dernier import, « à lettrer », résultat de l'année précédente).
- [ ] P3 — Impression de l'extrait lettré d'un compte (équivalent G25/L25).
- [ ] P3 — Vérifier les écrans compta sur mobile.
