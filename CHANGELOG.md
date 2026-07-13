# Journal des versions

Toutes les modifications notables de Lasso. Format inspiré de
[Keep a Changelog](https://keepachangelog.com/fr/) ; versionnage
[sémantique](https://semver.org/lang/fr/).

Les nouveautés arrivent d'abord sur le canal **test** (section « Non publié »),
puis sont promues sur le canal **stable** en figeant une version.

## [1.3.0] — 2026-07-13

### Ajouté
- Pagination sur les listes potentiellement longues (fiches, employés,
  écritures, factures, débiteurs, événements) : 25/50/100/200 lignes par
  page (100 par défaut), taille mémorisée par page, navigation
  précédent/suivant préservant les filtres actifs.
- Recherche instantanée (insensible casse/accents) sur Employés, Analyse
  par axe, Débiteurs et Spectacles — même mécanisme que sur les listes qui
  l'avaient déjà (Écritures, Factures, Événements).
- Filtre « année » par défaut sur « Toutes les années » (Salaires,
  Écritures, Factures, Événements), plutôt que l'année courante.

### Corrigé
- Événements — statut SUISA « À faire » : excluait mal un événement dont le
  décompte avait été reçu sans date d'envoi enregistrée (saisie manuelle
  incomplète), qui apparaissait alors dans les deux filtres à la fois.
- Événements — statut SUISA « Envoyé » : n'incluait pas les événements
  « Manquant » (décompte en retard), alors qu'un décompte en retard reste
  avant tout un événement envoyé.
- Événements — liste : le badge SUISA affiche désormais la date du
  décompte plutôt que le texte générique « Décompte reçu ».
- Badge employé inactif : texte invisible sur fond clair.
- Tableaux sans défilement horizontal sur petit écran (plusieurs pages de
  comptabilité, facturation et salaires horaires).
- Lien de retour contextuel du formulaire de fiche de salaire : perdu en
  cas d'erreur de validation réaffichant la page.
- `alt=""` des logos employeur en aperçu (paramètres) : manquait le nom de
  l'employeur.
- Largeur de colonne `.col-petit` sur les en-têtes de tableau manquante
  dans la page « Cotisations ».

### Modifié
- Accessibilité : `aria-label` ajouté aux champs d'édition en ligne et aux
  boutons icône-seule restants.
- Confirmations de suppression/annulation (`confirm()`) harmonisées sur
  tous les formulaires concernés.
- Factorisation : validation d'upload centralisée dans un helper commun
  (`lire_fichier_importe()`), génération des badges de statut centralisée
  (`badge()`), réutilisées par les modules paie/comptabilité/facturation/
  événements.
- Nettoyage de règles CSS mortes ; généralisation de `.search-label` et du
  style des champs de recherche pour fonctionner hors du conteneur
  `.filters`.

## [1.2.5] — 2026-07-12

### Corrigé
- Export public JSON/iCal filtré par `spectacle_id` : un spectacle-groupe
  (artiste) n'étant jamais assigné directement à un événement, l'URL
  d'export d'un artiste était toujours vide. Inclut désormais les
  événements de ses feuilles (sous-spectacles).

### Modifié
- Page Spectacles : suppression de la card autour du tableau.

## [1.2.4] — 2026-07-12

### Ajouté
- Salaires horaires : renommage inline (crayon).
- Import de compte bancaire : IBAN inconnu → demande le nom du compte plutôt
  que de bloquer l'import.
- Import d'écritures : étape « Simuler » (dry-run) affichant un aperçu avant
  import définitif.
- Navigation : lien de retour contextuel (revient à la page d'origine, pas
  systématiquement à la liste).
- Fiche de salaire : coûts estimés recalculés en direct pendant la saisie.
- Modification groupée : annulation de la dernière action (bulk undo).
- Spectacles : hiérarchie artiste › spectacle, tri par artiste.
- Comptabilité : export et import CAMT.053 (relevé bancaire ISO 20022).

### Corrigé
- Import CAMT.053 : `registerXPathNamespace()` ne se propageant pas aux
  nœuds enfants retournés par `xpath()`, le préfixe devait être ré-enregistré
  sur chaque nœud avant une requête XPath relative.
- Import CAMT.053 : le solde de continuation (code `PRCD`) n'était pas
  reconnu comme solde d'ouverture sur un relevé qui n'est pas le premier.
- Plusieurs corrections identifiées lors d'une revue de code du diff depuis
  1.2.3 (dates, formats, cas limites) ainsi qu'un nettoyage de code mort.
- Modification groupée « Modifier l'axe » (Écritures) : n'affichait aucun
  message de confirmation ni possibilité d'annuler, contrairement aux autres
  actions groupées — annulation désormais prise en charge pour ce cas aussi.
- Ctrl+Z/Cmd+Z (annulation d'une modification groupée) cessait de fonctionner
  dès la disparition du bandeau de confirmation (10 s), alors que l'annulation
  reste possible côté serveur pendant 5 minutes.
- Lien de retour contextuel manquant sur deux parcours croisés : facture →
  débiteur et fiche de salaire → employé (revenaient toujours à la liste
  générique plutôt qu'à la page d'origine).

### Modifié
- Nettoyage interne : `dom_el()` (helpers.php) factorise la création
  d'éléments DOM namespacés, remplaçant les closures dupliquées des
  générateurs XML eLohnausweis et camt.053. `date_valide()` unifié entre
  `evenements.php` et `compta.php`. Motif JS d'affichage/masquage des lignes
  d'ajout (`data-show`/`data-hide`) factorisé dans `assets/app.js` (compte
  comptable, spectacles, salaires horaires), avec délégation d'événements
  sur `document` (le script étant chargé dans `<head>`, avant les boutons
  concernés).
- Message « Modification annulée » (après un Ctrl+Z) affiché dans un bandeau
  orange, distinct du bandeau vert de confirmation initiale.

## [1.2.3] — 2026-07-11

### Corrigé
- Comptabilité analytique par axe : le tableau des écritures ne défilait pas
  horizontalement sur petit écran.

### Modifié
- Facturation : colonne **Statut** renommée **Paiement**, le tag « Payée »
  affiche désormais la date de paiement plutôt qu'un texte fixe.
- Tableau de bord : graphique Évolution financière agrandi de 25%
  supplémentaires ; dates du widget « Prochains événements » plus petites en
  mode mobile.
- Nettoyage interne : nouveau `assets/app.js` (chargé une fois depuis
  `layout.php`) — `lassoNorm()` remplace 8 définitions dupliquées de la
  recherche insensible aux accents/casse ; `lassoInitCatSearch()` unifie 4
  des 5 widgets de dropdown catégorie/axe cherchable. Helper
  `valeur_autorisee()` centralisant la validation whitelist du dispatcher de
  modification groupée des événements (6 occurrences remplacées).

## [1.2.2] — 2026-07-10

### Modifié
- Tableau de bord : « Prochains événements » affiché en premier.
- Menu mobile : le tiroir s'ouvre depuis la **droite** (au même endroit que le
  bouton burger, la croix de fermeture reprend exactement sa position) ; le
  logo n'est plus répété en haut du tiroir (déjà visible dans la barre du haut).
- Dégradé de la page de connexion : la teinte centrale du dégradé venait d'une
  couleur fixe non dérivée de la marque, remplacée par une couleur calculée
  (cohérente avec n'importe quelle couleur principale choisie).
- Boutons d'en-tête (Comptes bancaires, Lettrage automatique, Analyse,
  Événements, Fiches, Employés…) : libellé masqué en mobile, icône seule,
  alignement harmonisé avec le standard `page-head`.
- Image de fond renommée `test.jpg` → `fond.jpg`.

## [1.2.1] — 2026-07-10

### Ajouté
- Modification groupée (bulk change) unifiée sur les listes Écritures et Événements :
  un seul sélecteur d'action (au lieu de plusieurs formulaires séparés) ; nouvelles
  actions Événements — suppression, région, pays, SUISA (applicable, envoi, décompte).
- Liste des événements : filtre « sans spectacle », colonne **Salariés** (nombre de
  salariés liés).
- Liste des fiches de salaire : colonnes **Charges sociales**, **Impôt à la source**,
  **Charges patronales** (même apparence que la page Cotisations).
- Comptabilité analytique par axe : ligne **Total des charges (salariales + patronales)**
  en fin de tableau des charges sociales prévues.
- Infobulles (icône **i**) remplaçant plusieurs textes d'aide statiques (fiche de
  salaire, employé, Cotisations, employeur, salaires horaires, e-mails, facture,
  spectacle, taux).
- Liste des fiches de salaire : ligne de totaux en fin de tableau.
- Liste des événements : filtres **Pays** et **Salariés** (oui/non), champ de
  recherche instantané (ville, salle, festival, spectacle) — les séparateurs de
  mois sans résultat se masquent automatiquement pendant la recherche.

### Modifié
- Dégradé du menu latéral et de la page de connexion plus vibrant.
- Tableau de bord : les widgets s'empilent en pleine largeur dès que la fenêtre ne
  permet plus deux colonnes confortables, pas seulement en mode mobile strict.
- Écritures : couleur de survol des lignes alignée sur celle utilisée ailleurs dans
  l'application (dérivée de la couleur de marque, plus une couleur fixe).
- Fiche de salaire : ligne de prestation resserrée (unité/quantité/taux/axe plus
  étroits, quantité doublée et fixe) ; select d'événement lié toujours visible pour
  permettre la liaison directement depuis la fiche, plus seulement son édition.
- Page « Résumé » renommée en **Cotisations**.
- Badge de paiement : n'affiche plus que la date (« Payé le » retiré, texte redondant
  avec le tag vert).
- Nettoyage interne (CSS/PHP) : suppression de règles CSS orphelines et de
  doublons (couleurs de colonnes, focus, media queries), réutilisation des
  helpers partagés (`options_axes()`, `preselectionner_option()`, `mois_nom()`)
  à la place de code dupliqué localement.

## [1.2.0] — 2026-07-09

### Ajouté
- **Nouveau module Événements** : dates, statut (option/confirmé/annulé), audience,
  région/pays, suivi SUISA (applicabilité, envoi, décompte), export public, import
  CSV, spectacles (photo, notes).
- Liens croisés événement ↔ prestation de fiche de salaire ↔ facture (association,
  détachement, affichage croisé dans les trois sens).
- Tableau de bord : graphique SVG « Évolution financière » (recettes, dépenses,
  résultat, patrimoine) et deux colonnes de widgets indépendantes.
- Page Résumé : filtres (regroupement / année / employé) alignés horizontalement.

### Modifié
- Facturation : indépendance vis-à-vis du module Comptabilité (activable seul) ;
  événement lié affiché en sidebar de la facture.
- Fiches de salaire : séparateurs de mois/année dans la liste, option « Toutes les
  années », boutons d'en-tête regroupés.
- Style des champs de formulaire harmonisé sur l'ensemble du site.

### Corrigé
- Requêtes N+1 sur le tableau de bord comptable et sur la fiche événement (regroupées
  en requêtes préparées).
- Garde de module manquante sur la liaison facture ↔ événement.
- Import CSV événements : validation du pays contre la liste autorisée.
- Suppression d'un événement lié à une facture n'empêche plus l'enregistrement de la
  facture (clé étrangère gérée proprement).

## [1.1.0] — 2026-07-02

### Ajouté
- **Module facturation** : débiteurs, factures **QR-facture suisse** (zone de paiement
  normée + code QR, `vendor/` committé), imports JSON/CSV, marquage manuel « payée »
  avec liaison à une écriture bancaire, PDF rapproché de la vue HTML.
- **Architecture modulaire** : modules activables/désactivables indépendamment
  (Fiches de salaire, Comptabilité, Comptabilité analytique, Facturation — dépendances
  gérées, ex. l'analytique nécessite la comptabilité), sans perte de données à la
  désactivation/réactivation.
- **Mise à jour en un clic** depuis Paramètres → Mises à jour : téléchargement de
  l'archive du canal et remplacement des fichiers en PHP pur (pour les hébergements
  sans `exec()`/`git`), avec sauvegarde de la base et journal `data/maj.log`.
- **Diagnostic du serveur** (téléchargement / décompression / écriture) déterminant
  la méthode de mise à jour possible ; détection de version compatible **dépôt privé**
  (jeton `MAJ_TOKEN` optionnel) et détection du recul de version au niveau commit.
- Nouvelle page **Résumé** (résumé complet + charges totales) sur le tableau de bord.

### Modifié
- **En-têtes** des pages Fiches de salaire, Écritures, Factures et Paramètres :
  bandeau sticky pleine largeur sur fond blanc (non sticky en mobile) ; titres,
  boutons et filtres/onglets regroupés dans l'en-tête.
- Paramètres réorganisés : « Unités de temps » fusionnées dans **Salaires horaires** ;
  onglet « Taux des déductions » renommé **Taux** ; titres de section déplacés à
  l'intérieur des cartes.
- Écritures : recherche sur le texte complet (pas seulement le résumé), tableau
  scrollable horizontalement sur petit écran.
- Comptabilité analytique : masque les axes inactifs sur la page principale.
- Icônes de navigation actualisées (Tableau de bord, Débiteurs).

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
