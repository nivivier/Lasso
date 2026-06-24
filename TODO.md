# Module comptabilité 

## Bugs à corriger en priorité

_(aucun)_

## Améliorations prioritaires

_(aucune)_

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
