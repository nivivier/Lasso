<?php
// Tests du module comptabilité. Lancement : php tests/compta_test.php
// N'utilise pas la base de données (fonctions pures de lib/compta.php).

require_once __DIR__ . '/../lib/compta.php';

$tests = 0;
$fails = 0;
function check(string $label, $attendu, $obtenu): void
{
    global $tests, $fails;
    $tests++;
    $ok = is_float($attendu)
        ? abs($attendu - (float) $obtenu) < 0.005
        : $attendu === $obtenu;
    if (!$ok) {
        $fails++;
        printf("  FAIL  %-46s attendu %s, obtenu %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
    } else {
        printf("  ok    %s\n", $label);
    }
}

// Fixture au format export PostFinance (2 crédits, 2 débits, 1 solde vide,
// 1 doublon strict de la 1re ligne de crédit).
$csv = <<<CSV
Date de début:;="01.01.2025"
Date de fin:;="31.12.2025"
Genre de comptabilisation:;="Tous"
Compte:;="CH9300762011623852957"
Monnaie:;="CHF"

Date;Texte de notification;Crédit en CHF;Débit en CHF;Valeur;Solde en CHF

31.12.2025;"PRIX POUR LA GESTION DU COMPTE";;-5;31.12.2025;4474.67
31.12.2025;"CRÉDIT MARTIN COMMUNICATIONS: LOCAL";120;;31.12.2025;4479.67
31.12.2025;"CRÉDIT MARTIN COMMUNICATIONS: LOCAL";120;;31.12.2025;4479.67
05.12.2025;"DÉBIT ORDRE PERMANENT DUPONT REFERENCE: LOYER";;-470;05.12.2025;
03.12.2025;"DON PRIVÉ DURAND";100;;03.12.2025;3500.00

Disclaimer:
Bla bla.
CSV;

echo "1) parse_postfinance_csv()\n";
$p = parse_postfinance_csv($csv);
check('IBAN', 'CH9300762011623852957', $p['iban']);
check('monnaie', 'CHF', $p['monnaie']);
check('date_debut ISO', '2025-01-01', $p['date_debut']);
check('date_fin ISO', '2025-12-31', $p['date_fin']);
check('nb lignes', 5, count($p['lignes']));
check('1re ligne date ISO', '2025-12-31', $p['lignes'][0]['date_op']);
check('débit négatif', -5.0, $p['lignes'][0]['montant']);
check('crédit positif', 120.0, $p['lignes'][1]['montant']);
check('solde vide → null', true, $p['lignes'][3]['solde'] === null);
check('solde renseigné', 3500.0, $p['lignes'][4]['solde']);

echo "2) Dédoublonnage (hash_lignes)\n";
$h1 = hash_lignes(1, $p['lignes']);
check('5 hash', 5, count($h1));
// Les lignes 1 et 2 (crédit le doublon) sont des doublons stricts → hash distincts
// (occurrences 0 et 1), mais un ré-import du même fichier reproduit les mêmes hash.
check('doublon strict → hash différents (occ)', true, $h1[1] !== $h1[2]);
$h2 = hash_lignes(1, $p['lignes']);
check('ré-import → hash identiques', true, $h1 === $h2);
check('compte différent → hash différent', true, hash_lignes(2, $p['lignes'])[0] !== $h1[0]);

echo "3) Lettrage par règles (appliquer_regles)\n";
$plan = [
    10 => ['libelle' => 'Local - Frais bancaires', 'sens' => 'charge', 'groupe' => 'Charges de local'],
    11 => ['libelle' => 'Local - Contribution des membres', 'sens' => 'produit', 'groupe' => 'Recettes'],
    12 => ['libelle' => 'Dons privés', 'sens' => 'produit', 'groupe' => 'Recettes'],
    99 => ['libelle' => 'Frais bancaires (général)', 'sens' => 'charge', 'groupe' => 'Dépenses'],
];
$ecr = [
    ['id' => 1, 'compte_bancaire_id' => 1, 'texte' => 'PRIX POUR LA GESTION DU COMPTE', 'montant' => -5.0],
    ['id' => 2, 'compte_bancaire_id' => 1, 'texte' => 'CRÉDIT MARTIN COMMUNICATIONS: LOCAL', 'montant' => 120.0],
    ['id' => 3, 'compte_bancaire_id' => 2, 'texte' => 'PRIX POUR LA GESTION DU COMPTE', 'montant' => -5.0],
    ['id' => 4, 'compte_bancaire_id' => 1, 'texte' => 'DON PRIVÉ DURAND', 'montant' => 100.0],
];
$regles = [
    // Règle compte-1 spécifique (priorité 0) vs règle globale (priorité 0) sur le même motif.
    ['id' => 1, 'compte_bancaire_id' => 1, 'motif' => 'prix pour la gestion', 'type_match' => 'contient', 'sens_filtre' => '', 'plan_compte_id' => 10, 'priorite' => 0, 'actif' => 1],
    ['id' => 2, 'compte_bancaire_id' => null, 'motif' => 'prix pour la gestion', 'type_match' => 'contient', 'sens_filtre' => '', 'plan_compte_id' => 99, 'priorite' => 0, 'actif' => 1],
    ['id' => 3, 'compte_bancaire_id' => null, 'motif' => 'local', 'type_match' => 'contient', 'sens_filtre' => 'credit', 'plan_compte_id' => 11, 'priorite' => 5, 'actif' => 1],
    ['id' => 4, 'compte_bancaire_id' => null, 'motif' => 'don prive', 'type_match' => 'contient', 'sens_filtre' => '', 'plan_compte_id' => 12, 'priorite' => 0, 'actif' => 1],
];
$res = appliquer_regles($regles, $ecr);
check('compte-spécifique avant globale (écr.1 → 10)', 10, $res[1] ?? null);
check('globale s\'applique à compte 2 (écr.3 → 99)', 99, $res[3] ?? null);
check('insensible casse/accents (écr.4 « DON PRIVÉ » → 12)', 12, $res[4] ?? null);
check('sens_filtre credit + accents (écr.2 → 11)', 11, $res[2] ?? null);

// sens_filtre : une règle « credit » ne doit pas matcher un débit.
$regleDebit = [['id' => 9, 'compte_bancaire_id' => null, 'motif' => 'gestion', 'type_match' => 'contient', 'sens_filtre' => 'credit', 'plan_compte_id' => 11, 'priorite' => 0, 'actif' => 1]];
$resD = appliquer_regles($regleDebit, [$ecr[0]]);
check('règle credit n\'attrape pas un débit', true, !isset($resD[1]));

// Condition de montant (valeur absolue).
$rMontant = ['motif' => 'local', 'type_match' => 'contient', 'sens_filtre' => '', 'montant_min' => 100, 'montant_max' => 150];
check('montant dans la plage', true, regle_match($rMontant, ['texte' => 'CRÉDIT LOCAL', 'montant' => 120.0]));
check('montant sous la borne min', false, regle_match($rMontant, ['texte' => 'CRÉDIT LOCAL', 'montant' => 50.0]));
check('montant au-dessus de la borne max', false, regle_match($rMontant, ['texte' => 'CRÉDIT LOCAL', 'montant' => 470.0]));
check('borne min seule sur un débit (|−470| ≥ 100)', true, regle_match(['motif' => 'local', 'montant_min' => 100, 'montant_max' => null], ['texte' => 'DÉBIT LOCAL', 'montant' => -470.0]));

echo "3b) Extraction du tiers / communication (extraire_tiers)\n";
$ex = fn($t) => extraire_tiers($t);
$e1 = $ex("CRÉDIT CH5604835012345678009 EXPÉDITEUR: MARTIN PIERRE RUE DES ACACIAS 12 1227 CAROUGE COMMUNICATIONS: LOCAL");
check('expéditeur → tiers', 'MARTIN PIERRE', $e1['tiers']);
check('communication', 'LOCAL', $e1['communication']);
$e2 = $ex("CRÉDIT DONNEUR D'ORDRE: JEAN BERNARD RUE DU TEST 7 CH 1200 GENEVE MONTANT DE FRAIS 0.00 CHF SHA REFERENCES: NOTPROVIDED TEST12345ABCDE 251231TESTTEST8");
check('donneur d\'ordre → tiers', 'JEAN BERNARD', $e2['tiers']);
$e3 = $ex("DÉBIT ORDRE PERMANENT: 12-345678-9 CH3908704016075473007 DUPONT ET CIE SA CHEMIN DU MOULIN 5 1225 CHENE-BOURG REFERENCE DE L'EXPEDITEUR: LOYER");
check('ordre permanent → tiers', 'DUPONT ET CIE SA', $e3['tiers']);
check('référence expéditeur → communication', 'LOYER', $e3['communication']);
$e4 = $ex("CRÉDIT DONNEUR D'ORDRE: SOPHIE BLANC RUE DU RHONE 10 1204 GENEVE COMMUNICATIONS: COTISATION ANNUELLE REFERENCES: NOTPROVIDED 00000000001.0001");
check('tiers tronqué avant adresse', 'SOPHIE BLANC', $e4['tiers']);
check('communication longue conservée', true, str_contains($e4['communication'], 'COTISATION'));
$e5 = $ex("PF PAY ACHAT/SHOPPING EN LIGNE DU 08.11.2025 EXEMPLE SA HTTPS://EXEMPLE.CH ID PAIEMENT ABCD12345678");
check('achat en ligne → marchand', 'EXEMPLE SA', $e5['tiers']);
$e6 = $ex("PRIX POUR LA GESTION DU COMPTE NUMÉRO DE COMPTE D'ORIGINE: CH9300762011623852957");
check('libellé système → tiers vide', '', $e6['tiers']);

echo "4) Plan comptable hiérarchique (arbre)\n";
// Recettes(1) › [Cotisations(2), Dons(3)] ; Charges(10) › Loyer(11) ; Frais(12) racine-feuille
$arbre = [
    1  => ['id' => 1,  'libelle' => 'Recettes',        'sens' => 'produit', 'parent_id' => null, 'ordre' => 0],
    2  => ['id' => 2,  'libelle' => 'Cotisations',     'sens' => 'produit', 'parent_id' => 1,    'ordre' => 0],
    3  => ['id' => 3,  'libelle' => 'Dons',            'sens' => 'produit', 'parent_id' => 1,    'ordre' => 1],
    10 => ['id' => 10, 'libelle' => 'Charges',         'sens' => 'charge',  'parent_id' => null, 'ordre' => 2],
    11 => ['id' => 11, 'libelle' => 'Loyer',           'sens' => 'charge',  'parent_id' => 10,   'ordre' => 0],
    12 => ['id' => 12, 'libelle' => 'Frais bancaires', 'sens' => 'charge',  'parent_id' => null, 'ordre' => 3],
];
$byParent = plan_enfants($arbre);
check('3 racines', 3, count($byParent[0]));
check('Recettes a 2 enfants', 2, count($byParent[1]));
check('Cotisations est une feuille', true, plan_est_feuille(2, $arbre));
check('Recettes n\'est pas une feuille', false, plan_est_feuille(1, $arbre));
check('chemin Recettes › Cotisations', 'Recettes › Cotisations', plan_chemin(2, $arbre));
$feuilles = plan_feuilles($arbre);
check('4 feuilles assignables', 4, count($feuilles));
check('1re feuille (ordre arbre)', 'Recettes › Cotisations', $feuilles[0]['chemin']);

echo "5) Agrégation du compte de résultat (agreger_resultat + sous-totaux)\n";
$ecr2 = [
    ['plan_compte_id' => 2,  'montant' => 120.0],
    ['plan_compte_id' => 2,  'montant' => 120.0],
    ['plan_compte_id' => 3,  'montant' => 100.0],
    ['plan_compte_id' => 11, 'montant' => -470.0],
    ['plan_compte_id' => 12, 'montant' => -5.0],
    ['plan_compte_id' => null, 'montant' => -9.0], // non lettrée
    ['plan_compte_id' => null, 'montant' => -50.0, 'origine_lettrage' => 'ignore'], // « Ne pas lettrer » : exclue
];
$agg = agreger_resultat($ecr2, $arbre);
check('total produits', 340.0, $agg['total_produits']);
check('total charges', -475.0, $agg['total_charges']);
check('résultat', -135.0, $agg['resultat']);
check('somme feuille Cotisations (240)', 240.0, $agg['sommes'][2]);
check('sous-total groupe Recettes (340)', 340.0, plan_sous_total(1, $byParent, $agg['sommes']));
check('sous-total groupe Charges (−470)', -470.0, plan_sous_total(10, $byParent, $agg['sommes']));
check('non lettrées : nb (ignore exclu)', 1, $agg['non_lettrees']['nb']);
check('non lettrées : montant (ignore exclu)', -9.0, $agg['non_lettrees']['montant']);

// ---------------------------------------------------------------------------
// parse_camt053() — relevé ISO 20022. Aucune couverture jusqu'ici, ce qui a
// laissé passer une régression silencieuse : à partir de camt.053.001.06, le
// nom de la contre-partie descend d'un cran (Dbtr/Pty/Nm au lieu de Dbtr/Nm),
// et « tiers » ressortait vide sur TOUT fichier récent sans la moindre erreur.
// La fixture couvre les deux emplacements, un lot de deux transactions, la
// référence QR, les marqueurs techniques PostFinance et les frais bancaires.
$camt = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08"><BkToCstmrStmt><Stmt>
<Id>T1</Id><Acct><Id><IBAN>CH8609000000158716885</IBAN></Id><Ccy>CHF</Ccy></Acct>
<Bal><Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp><Amt Ccy="CHF">1000.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-08-01</Dt></Dt></Bal>
<Ntry><Amt Ccy="CHF">470.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><BookgDt><Dt>2026-08-05</Dt></BookgDt>
  <AcctSvcrRef>REF-DEBIT</AcctSvcrRef>
  <BkTxCd><Domn><Cd>PMNT</Cd><Fmly><Cd>ICDT</Cd><SubFmlyCd>BOOK</SubFmlyCd></Fmly></Domn></BkTxCd>
  <NtryDtls><TxDtls><Amt Ccy="CHF">470.00</Amt><CdtDbtInd>DBIT</CdtDbtInd>
    <RltdPties><Cdtr><Pty><Nm>DAUDIN et Cie SA</Nm></Pty></Cdtr><CdtrAcct><Id><IBAN>CH8330000002120006136</IBAN></Id></CdtrAcct></RltdPties>
    <RmtInf><Strd><CdtrRefInf><Ref>000000000000601000052100001</Ref></CdtrRefInf><AddtlRmtInf>Av. des Morgines 35</AddtlRmtInf></Strd></RmtInf>
  </TxDtls></NtryDtls><AddtlNtryInf>DEBIT ORDRE PERMANENT</AddtlNtryInf></Ntry>
<Ntry><Amt Ccy="CHF">120.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>2026-08-10</Dt></BookgDt>
  <BkTxCd><Domn><Cd>PMNT</Cd><Fmly><Cd>RCDT</Cd><SubFmlyCd>ATXN</SubFmlyCd></Fmly></Domn></BkTxCd>
  <NtryDtls><TxDtls><Refs><AcctSvcrRef>REF-CREDIT</AcctSvcrRef></Refs><Amt Ccy="CHF">120.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>
    <RltdPties><Dbtr><Pty><Nm>Germain Umdenstock</Nm></Pty></Dbtr><DbtrAcct><Id><IBAN>CH750024024072584329P</IBAN></Id></DbtrAcct></RltdPties>
    <RmtInf><Ustrd>2026-10</Ustrd><Strd><AddtlRmtInf>HoR Frais de booking Coquette</AddtlRmtInf><AddtlRmtInf>?REJECT?0</AddtlRmtInf><AddtlRmtInf>?ERROR?000</AddtlRmtInf></Strd></RmtInf>
  </TxDtls></NtryDtls><AddtlNtryInf>CREDIT DONNEUR D ORDRE</AddtlNtryInf></Ntry>
<Ntry><Amt Ccy="CHF">200.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>2026-08-12</Dt></BookgDt>
  <AcctSvcrRef>REF-LOT</AcctSvcrRef>
  <NtryDtls><Btch><NbOfTxs>2</NbOfTxs></Btch>
    <TxDtls><Refs><AcctSvcrRef>TX-A</AcctSvcrRef></Refs><Amt Ccy="CHF">120.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>
      <RltdPties><Dbtr><Pty><Nm>Theatre Alpha</Nm></Pty></Dbtr></RltdPties>
      <RmtInf><Strd><CdtrRefInf><Ref>210000000003139471430009017</Ref></CdtrRefInf></Strd></RmtInf></TxDtls>
    <TxDtls><Refs><AcctSvcrRef>TX-B</AcctSvcrRef></Refs><Amt Ccy="CHF">80.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>
      <RltdPties><Dbtr><Nm>Ancien Schema SA</Nm></Dbtr></RltdPties>
      <RmtInf><Ustrd>Facture 2026-014</Ustrd></RmtInf></TxDtls>
  </NtryDtls><AddtlNtryInf>CREDIT GROUPE</AddtlNtryInf></Ntry>
<Ntry><Amt Ccy="CHF">5.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><BookgDt><Dt>2026-08-31</Dt></BookgDt>
  <AcctSvcrRef>REF-FRAIS</AcctSvcrRef>
  <BkTxCd><Domn><Cd>ACMT</Cd><Fmly><Cd>ADOP</Cd><SubFmlyCd>CHRG</SubFmlyCd></Fmly></Domn></BkTxCd>
  <NtryDtls><TxDtls><Amt Ccy="CHF">5.00</Amt><CdtDbtInd>DBIT</CdtDbtInd></TxDtls></NtryDtls>
  <AddtlNtryInf>PRIX POUR LA GESTION DU COMPTE</AddtlNtryInf></Ntry>
</Stmt></BkToCstmrStmt></Document>
XML;

echo "\nparse_camt053() — relevé ISO 20022\n";
$r = parse_camt053($camt);
$l = $r['lignes'];
check('IBAN du relevé', 'CH8609000000158716885', $r['iban']);
check('un lot de 2 transactions donne 2 lignes (4 écritures -> 5 lignes)', 5, count($l));

check('contre-partie, schéma récent (Cdtr/Pty/Nm)', 'DAUDIN et Cie SA', $l[0]['tiers']);
check('contre-partie, schéma récent (Dbtr/Pty/Nm)', 'Germain Umdenstock', $l[1]['tiers']);
check('contre-partie, ancien schéma (Dbtr/Nm) toujours lue', 'Ancien Schema SA', $l[3]['tiers']);
// Le parseur reste un miroir du fichier : il ne déclare « camt » que sur ce
// qu'il a effectivement lu, jamais sur une valeur déduite (celle-ci naît à
// l'insertion et s'y déclare « texte »).
check('provenance déclarée quand le champ structuré existe', 'camt', $l[0]['tiers_source']);
check('provenance vide quand le relevé ne dit rien', '', $l[4]['tiers_source']);
check('débit -> montant négatif', -470.0, $l[0]['montant']);
check('crédit -> montant positif', 120.0, $l[1]['montant']);

check('référence QR lue', '000000000000601000052100001', $l[0]['reference']);
check('IBAN de la contre-partie lu', 'CH8330000002120006136', $l[0]['iban_tiers']);
check('nature (BkTxCd) aplatie', 'PMNT/ICDT/BOOK', $l[0]['nature']);
check('frais bancaires reconnaissables à leur nature', 'ACMT/ADOP/CHRG', $l[4]['nature']);
check('communication structurée lue', 'Av. des Morgines 35', $l[0]['communication']);
// Ustrd et Strd/AddtlRmtInf sont complémentaires : ne lire le second qu'à
// défaut du premier faisait perdre la moitié de la communication.
check('communication : Ustrd ET AddtlRmtInf réunis, marqueurs techniques écartés',
    '2026-10 — HoR Frais de booking Coquette', $l[1]['communication']);
check('texte inchangé malgré la communication enrichie (hash stable)', '2026-10', $l[1]['texte']);

check('référence bancaire de la transaction', 'REF-CREDIT', $l[1]['ref_bancaire']);
check('référence bancaire au niveau écriture quand la transaction n\'en a pas', 'REF-DEBIT', $l[0]['ref_bancaire']);
check('dans un lot, chaque ligne garde SA référence', ['TX-A', 'TX-B'], [$l[2]['ref_bancaire'], $l[3]['ref_bancaire']]);
// Régression : la recherche « .// » depuis l'écriture traversait toutes ses
// transactions et recopiait la valeur d'une voisine sur celles qui n'en ont pas.
check('dans un lot, pas de fuite de la référence QR du voisin', '', $l[3]['reference']);
check('dans un lot, pas de fuite de la communication du voisin', '', $l[2]['communication']);
check('dans un lot, les montants somment à celui de l\'écriture', 200.0, $l[2]['montant'] + $l[3]['montant']);

// « texte » entre dans le hash de dédoublonnage : sa règle (Ustrd, sinon
// AddtlNtryInf) ne doit pas bouger, sans quoi tout l'historique déjà importé
// reviendrait en doublon au prochain import.
check('texte : repli sur AddtlNtryInf quand pas de Ustrd', 'DEBIT ORDRE PERMANENT', $l[0]['texte']);
check('texte : Ustrd prioritaire', 'Facture 2026-014', $l[3]['texte']);

check('solde recalculé depuis le solde d\'ouverture', 845.0, $l[4]['solde']);

echo "\nresumer_texte_postfinance() — résumé affiché dans la colonne Texte\n";
// Aucune couverture jusqu'ici. La règle « achat en ligne » prenait toute la fin
// de ligne pour le marchand : sur un relevé camt.053 la banque y intercale le
// change, les frais et le numéro de carte, et la colonne affichait « Montant
// Dans La Monnaie Du Compte 6.18 1.5% Frais De Traitement… » au lieu du marchand.
$achatCamt = 'ACHAT/SHOPPING EN LIGNE DU 30.08.2026 MONTANT DANS LA MONNAIE DU COMPTE 6.18 1.5% FRAIS DE TRAITEMENT CHF 0.09 CARTE N° XXXX7174 PAYPAL *FACEBOOK 4029357733';
$achatCsv  = 'ACHAT/SHOPPING EN LIGNE DU 12.03.2025 MIGROS GENEVE';
// Le commerçant a sa propre colonne : le résumé décrit l'opération, pas lui.
check('achat par carte : le résumé décrit l\'opération', 'Achat en ligne du 30.08.2026', resumer_texte_postfinance($achatCamt));
check('achat par carte CSV : même traitement', 'Achat en ligne du 12.03.2025', resumer_texte_postfinance($achatCsv));
check(
    'frais bancaires : le n° du compte débité, déjà en colonne « Compte », est retiré',
    'PRIX POUR LA GESTION DU COMPTE',
    resumer_texte_postfinance("PRIX POUR LA GESTION DU COMPTE NUMÉRO DE COMPTE D'ORIGINE: CH8609000000158716885")
);

echo "\nmarchand_carte() — contre-partie d'un achat par carte\n";
// Une transaction carte n'a pas de RltdPties dans un relevé camt.053 : le
// commerçant n'existe que dans le libellé, derrière le change, les frais et le
// numéro de carte.
check('camt : change, frais et n° de carte écartés', 'PAYPAL *FACEBOOK', marchand_carte($achatCamt));
check('CSV : commerçant en clair', 'MIGROS GENEVE', marchand_carte($achatCsv));
check('n\'est pas un achat par carte -> rien', '', marchand_carte('CRÉDIT DONNEUR D ORDRE: JEAN MARTIN COMMUNICATIONS: DON'));
check('frais bancaires -> rien', '', marchand_carte("PRIX POUR LA GESTION DU COMPTE NUMÉRO DE COMPTE D'ORIGINE: CH86"));
check(
    'donneur d\'ordre + communication',
    'Jean Martin — DON ANNUEL',
    resumer_texte_postfinance('CRÉDIT DONNEUR D ORDRE: JEAN MARTIN RUE DU LAC 4 1200 GENEVE COMMUNICATIONS: DON ANNUEL')
);
check(
    'nom après IBAN + référence de l\'expéditeur',
    'Daudin Et Cie Sa — LOYER',
    resumer_texte_postfinance('DÉBIT ORDRE PERMANENT: 90-18511263 CH8330000002120006136 DAUDIN ET CIE SA ROUTE DE CHANCY 59 1213 PETIT-LANCY 1 REFERENCE DE L EXPEDITEUR: LOYER')
);
check('texte court : rendu tel quel', '2026-10', resumer_texte_postfinance('2026-10'));

echo "\ncontrepartie_ligne() — qui est en face, et à quel point s'y fier\n";
$cp = fn (array $ligne, bool $camt = true, string $releve = '', string $compte = '')
    => contrepartie_ligne($ligne, $camt, $releve, $compte);

check('champ structuré du relevé', ['Séverine Gonzalez', 'camt'],
    array_slice($cp(['tiers' => 'Séverine Gonzalez', 'tiers_source' => 'camt', 'texte' => '2026-10']), 0, 2));
check('achat par carte : commerçant déduit du libellé', ['PAYPAL *FACEBOOK', 'texte'],
    array_slice($cp(['tiers' => '', 'texte' => $achatCamt, 'nature' => 'PMNT/CCRD/POSD']), 0, 2));
check('export CSV : reconnaissance PostFinance', ['MIGROS GENEVE', 'csv'],
    array_slice($cp(['texte' => $achatCsv], false), 0, 2));

$frais = ['tiers' => '', 'texte' => 'PRIX POUR LA GESTION DU COMPTE', 'nature' => 'ACMT/ADOP/CHRG'];
check('frais bancaires : banque du compte, faute de mieux', ['PostFinance', 'compte'],
    array_slice($cp($frais, true, '', 'PostFinance'), 0, 2));
check('frais bancaires : le relevé prime sur la saisie', ['PostFinance AG', 'camt'],
    array_slice($cp($frais, true, 'PostFinance AG', 'PostFinance'), 0, 2));
check('frais bancaires sans banque connue : rien d\'inventé', ['', ''],
    array_slice($cp($frais), 0, 2));
check('une écriture ordinaire ne reçoit pas la banque',
    ['', ''],
    array_slice($cp(['tiers' => '', 'texte' => 'VERSEMENT AU GUICHET', 'nature' => 'PMNT/CCRD/CDPT'], true, '', 'PostFinance'), 0, 2));

echo "\n";
if ($fails === 0) {
    echo "✅ TOUS LES TESTS PASSENT ($tests assertions)\n";
    exit(0);
}
echo "❌ $fails / $tests assertions en échec\n";
exit(1);
