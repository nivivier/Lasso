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
Compte:;="CH8609000000158716885"
Monnaie:;="CHF"

Date;Texte de notification;Crédit en CHF;Débit en CHF;Valeur;Solde en CHF

31.12.2025;"PRIX POUR LA GESTION DU COMPTE";;-5;31.12.2025;4474.67
31.12.2025;"CRÉDIT ROMAGNOLI COMMUNICATIONS: LOCAL";120;;31.12.2025;4479.67
31.12.2025;"CRÉDIT ROMAGNOLI COMMUNICATIONS: LOCAL";120;;31.12.2025;4479.67
05.12.2025;"DÉBIT ORDRE PERMANENT DAUDIN REFERENCE: LOYER";;-470;05.12.2025;
03.12.2025;"DON PRIVÉ MAGHRAOUI";100;;03.12.2025;3500.00

Disclaimer:
Bla bla.
CSV;

echo "1) parse_postfinance_csv()\n";
$p = parse_postfinance_csv($csv);
check('IBAN', 'CH8609000000158716885', $p['iban']);
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
// Les lignes 1 et 2 (crédit Romagnoli) sont des doublons stricts → hash distincts
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
    ['id' => 2, 'compte_bancaire_id' => 1, 'texte' => 'CRÉDIT ROMAGNOLI COMMUNICATIONS: LOCAL', 'montant' => 120.0],
    ['id' => 3, 'compte_bancaire_id' => 2, 'texte' => 'PRIX POUR LA GESTION DU COMPTE', 'montant' => -5.0],
    ['id' => 4, 'compte_bancaire_id' => 1, 'texte' => 'DON PRIVÉ MAGHRAOUI', 'montant' => 100.0],
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
$e1 = $ex("CRÉDIT CH7609000000120538738 EXPÉDITEUR: ROMAGNOLI LUCIANO CHEMIN DE LA CHARROYETTE 9 1232 CONFIGNON COMMUNICATIONS: LOCAL");
check('expéditeur → tiers', 'ROMAGNOLI LUCIANO', $e1['tiers']);
check('communication', 'LOCAL', $e1['communication']);
$e2 = $ex("CRÉDIT DONNEUR D'ORDRE: GERMAIN UMDENSTOCK RAMPE DU PONT ROUGE 5 CH 1213 PETIT LANCY MONTANT DE FRAIS 0.00 CHF SHA REFERENCES: NOTPROVIDED 9940865LK0399805 251231CH0E6ME3G8");
check('donneur d\'ordre → tiers', 'GERMAIN UMDENSTOCK', $e2['tiers']);
$e3 = $ex("DÉBIT ORDRE PERMANENT: 90-18511263 CH8330000002120006136 DAUDIN ET CIE SA ROUTE DE CHANCY 59 1213 PETIT-LANCY 1 REFERENCE DE L'EXPEDITEUR: LOYER");
check('ordre permanent → tiers', 'DAUDIN ET CIE SA', $e3['tiers']);
check('référence expéditeur → communication', 'LOYER', $e3['communication']);
$e4 = $ex("CRÉDIT DONNEUR D'ORDRE: MATHIEU QUENTIN RUE DAUBIN 31 1203 GENEVE COMMUNICATIONS: CONTRIBUTION A LA CULTURE IMMERGEE  DU LE CLUB DES 5 AUX MORGINES REFERENCES: NOTPROVIDED 51231184491.0002");
check('tiers tronqué avant adresse', 'MATHIEU QUENTIN', $e4['tiers']);
check('communication longue conservée', true, str_contains($e4['communication'], 'CULTURE IMMERGEE'));
$e5 = $ex("PF PAY ACHAT/SHOPPING EN LIGNE DU 08.11.2025 INFOMANIAK HTTPS://INFOMANIAK.COM ID PAIEMENT MHWS74CSSLSRCBKR");
check('achat en ligne → marchand', 'INFOMANIAK', $e5['tiers']);
$e6 = $ex("PRIX POUR LA GESTION DU COMPTE NUMÉRO DE COMPTE D'ORIGINE: CH8609000000158716885");
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
];
$agg = agreger_resultat($ecr2, $arbre);
check('total produits', 340.0, $agg['total_produits']);
check('total charges', -475.0, $agg['total_charges']);
check('résultat', -135.0, $agg['resultat']);
check('somme feuille Cotisations (240)', 240.0, $agg['sommes'][2]);
check('sous-total groupe Recettes (340)', 340.0, plan_sous_total(1, $byParent, $agg['sommes']));
check('sous-total groupe Charges (−470)', -470.0, plan_sous_total(10, $byParent, $agg['sommes']));
check('non lettrées : nb', 1, $agg['non_lettrees']['nb']);
check('non lettrées : montant', -9.0, $agg['non_lettrees']['montant']);

echo "\n";
if ($fails === 0) {
    echo "✅ TOUS LES TESTS PASSENT ($tests assertions)\n";
    exit(0);
}
echo "❌ $fails / $tests assertions en échec\n";
exit(1);
