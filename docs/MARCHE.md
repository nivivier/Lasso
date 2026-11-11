# Lasso face au marché

Comparatif établi le **11 novembre 2026**, pour servir de point de départ
quand on se demande quoi développer ensuite.

⚠️ Les colonnes des concurrents viennent de **sources publiques** (pages des
éditeurs, souvent des comparateurs comme Capterra ou GetApp) : aucun de ces
logiciels n'a été essayé. Les « ? » et les tarifs sont à vérifier auprès de
chaque éditeur avant d'en tirer une décision. Le comparatif vieillit : le
dater à nouveau quand on le reprend.

## Les familles de logiciels

- **Gestion pour PME suisses** — Crésus, Office Maker, bexio : solides en
  paie (Swissdec), comptabilité et TVA, mais rien de propre au spectacle.
- **Gestion d'associations à membres** — ClubDesk, Webling : membres,
  cotisations, site web, comptabilité ; pas de salaires, pas de booking.
- **Spectacle vivant (France)** — Heeds (heeds.eu, ex-Intrazik, surtout les
  salles et festivals), Bob Booking (CRM de diffusion), Orfeo (compagnies,
  tourneurs, orchestres) : production, tournées, CRM de diffusion ; pas de
  paie, de comptabilité ni de QR-facture suisses.
- **Lasso** est à cheval sur les trois : aucun concurrent identifié ne réunit
  la paie suisse et la diffusion d'un projet.

## Tableau comparatif

Légende : ✅ couvert · ◐ partiel, ou seulement par un export ou un
partenaire · — absent · ? non confirmé dans les sources

| Fonction | **Lasso** | Heeds | Bob Booking | Orfeo | Crésus | Office Maker | ClubDesk | Webling | bexio |
|---|---|---|---|---|---|---|---|---|---|
| Fiches de salaire (LPP, LAA, impôt à la source) | ✅ | ◐ partenaire français | — | ? | ✅ | ✅ | — | — | ✅ |
| Certificat de salaire / Swissdec | ◐ formulaire 11 + XML, sans envoi Swissdec | — | — | — | ✅ | ✅ Swissdec 5.3 | — | — | ✅ Swissdec 5.3 |
| Comptabilité en partie double | ✅ | ◐ budgets | ◐ budgets, export | ◐ budgets, suivi comptable | ✅ | ✅ | ✅ | ✅ | ✅ |
| Import bancaire | ◐ CSV PostFinance | — | — | — | ✅ | ✅ | ? | ? | ✅ e-banking |
| Comptabilité analytique | ✅ | ? | — | ◐ budget par production | ✅ | ? | ? | ? | ◐ |
| Justificatifs rapprochés des écritures | ✅ | — | ◐ archivage | ? | ? | ? | ? | ? | ✅ |
| QR-facture + relances | ✅ | ? | ◐ factures | ◐ devis, factures (France) | ✅ | ✅ | ✅ | ✅ | ✅ |
| TVA | — (association exonérée) | ? | ? | ? | ✅ | ✅ | ? | ? | ✅ |
| Carnet d'adresses / CRM | ✅ | ✅ | ✅ | ✅ | ◐ | ✅ module Business | ◐ membres | ◐ membres | ✅ |
| Prospection de salles et festivals (tags, lieux, carte) | ✅ | ◐ côté lieux | ✅ | ✅ | — | — | — | — | — |
| Dates, tournées, export iCal/JSON | ✅ | ✅ | ✅ | ✅ | — | — | ◐ agenda | ◐ agenda | — |
| Feuilles de route / logistique | ◐ fichiers joints | ✅ | ? | ✅ (technique, transport) | — | — | — | — | — |
| Planning du personnel | ◐ employés liés aux dates | ? | — | ✅ | — | — | — | — | — |
| Déclarations SUISA | ✅ | — (France) | — | — (France) | — | — | — | — | — |
| Envois groupés / newsletter | ✅ | ◐ | ✅ | ✅ | — | ? | ✅ | ✅ | — |
| Recherche de fonds / subventions | ✅ | ? | — | ? | — | — | — | — | — |
| Membres et cotisations | — | — | — | — | ◐ | ◐ | ✅ | ✅ | ◐ |
| Site web / portail membres | ◐ flux pour le site | — | ◐ publication des dates | — | — | — | ✅ | ✅ | — |
| Billetterie | — | ◐ partenaire | — | — | — | — | — | — | — |
| Hébergement | le nôtre (mutualisé) | cloud, France | cloud, France | cloud, France | Mac/PC ou cloud | Mac/PC ou cloud, Suisse | cloud, Suisse | cloud, Suisse | cloud, Suisse |
| Coût | aucune licence | sur devis | dès ~19 €/mois | sur devis | licence | dès 990 CHF (Finance) | gratuit jusqu'à 50 membres, puis ~156 CHF/an | abonnement | abonnement |

## Ce que seul Lasso fait

- Les **déclarations SUISA** (les outils du spectacle sont français et ne
  les connaissent pas).
- La **recherche de fonds** : un dossier par bailleur, délais, versement
  rapproché d'une écriture.
- La **paie suisse et le booking dans la même base** : une date de concert
  rattachée aux employés, aux fiches de salaire et à la facture.

## Ce qui manque à Lasso face au marché

Pistes à examiner quand on choisit le prochain chantier — pas une feuille de
route : chacune ne vaut que si l'association en a réellement besoin.

- **Envoi des salaires par Swissdec (ELM).** Lasso produit le certificat
  (formulaire 11 + XML pour l'application eCertificat de salaire CSI) mais ne
  transmet rien aux caisses. Pas obligatoire pour une dizaine d'employés,
  mais c'est le standard des logiciels de paie (Crésus, Office Maker, bexio).
  Chantier lourd : certification Swissdec.
- **Membres et cotisations.** Le point fort de ClubDesk et Webling. Utile
  seulement si l'association a des membres cotisants ; la facturation ne
  prévoit aujourd'hui qu'un bouton « dupliquer » (`docs/SPEC_FACTURATION.md`).
- **Site web ou portail membres.** Lasso fournit les flux JSON/iCal des
  événements à un site existant, mais n'en héberge pas.
- **Billetterie.** Couverte par personne d'autre dans ce comparatif, sinon
  par un partenaire (Heeds) : à rapprocher d'un service externe plutôt qu'à
  développer.
- **Connexion bancaire directe.** Aujourd'hui, import CSV de PostFinance
  uniquement ; bexio, Crésus et Office Maker lisent les relevés bancaires
  standard ou se connectent à l'e-banking.
- **Logistique de tournée.** Heeds et Orfeo gèrent la technique, le
  transport et le planning du personnel ; Lasso se limite à joindre une
  feuille de route.

## Sources

- Heeds : [le 360° de vos projets](https://www.heeds.eu/heeds/),
  [production artistique](https://www.heeds.eu/fonctionnalites/production-artistique/),
  [partenaires](https://www.heeds.eu/communaute/partenaires/)
- Bob Booking : [Capterra](https://www.capterra.fr/software/1028463/bob-booking),
  [GetApp](https://www.getapp.fr/software/2056006/bob-booking),
  [GetApp Canada](https://www.getapp.ca/software/2056006/bob-booking),
  [Spendbase](https://www.spendbase.co/fr/?p=28151)
- Orfeo : [compagnies](https://orfeo.pro/produits/compagnies),
  [Appvizer](https://www.appvizer.fr/marketing/evenementiel/orfeo),
  [logiciels.pro](https://www.logiciels.pro/orfeo/),
  [Capterra](https://www.capterra.fr/software/219903/orfeo)
- Crésus : [Crésus Salaires (PDF)](https://v2.cresus.ch/fr/Cresus_Salaires.pdf),
  [Crésus Gestion PE (PDF)](https://support.cresus.ch/wp-content/documentation/5/cresus-gestion-pe-5.pdf)
- Office Maker : [accueil](https://www.officemaker.ch/),
  [Staff](https://www.officemaker.ch/staff),
  [Business](https://www.officemaker.ch/business),
  [Finance Standard](https://www.officemaker.ch/finance-standard)
- ClubDesk : [PostFinance – logiciels pour associations](https://www.postfinance.ch/fr/entreprises/type-d-entreprise/associations/logiciels-pour-associations.html),
  [GetApp](https://www.getapp.com/recreation-wellness-software/a/clubdesk/features/),
  [SoftGuide](https://www.softguide.com/program/clubdesk)
- Webling : [Swiss Made Software](https://www.swissmadesoftware.org/en/companies/usystems-gmbh/home.html),
  [TrustRadius](https://www.trustradius.com/products/webling/details),
  [Eventfrog](https://eventfrog.ch/en/blog/articles/webling-eventfrog.html)
- bexio : [associations](https://www.bexio.com/fr-CH/logiciel-association),
  [salaires](https://www.bexio.com/fr-CH/comptabilite-salaire)
