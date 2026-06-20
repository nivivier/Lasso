<?php
// Tests unitaires du calcul de salaire. Lancement : php tests/calc_test.php
// N'utilise pas la base de données (taux passés en argument).

require_once __DIR__ . '/../lib/calc.php';

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
        printf("  FAIL  %-42s attendu %s, obtenu %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
    } else {
        printf("  ok    %s\n", $label);
    }
}

// Grille de taux de référence (Genève 2026, sans part employeur sauf indiqué)
$taux = [
    'avs' => 0.053, 'ac' => 0.0106, 'amat' => 0.00029, 'laa' => 0.0106, 'lpp' => 0.07,
    'emp_avs' => 0.053, 'emp_ac' => 0.011, 'emp_amat' => 0.00029, 'emp_af' => 0.0234,
    'emp_laa' => 0, 'emp_frais' => 0, 'emp_lpp' => 0.07,
];

echo "1) Cas de référence (Genève, Ordinaire, supplément 8.33 %)\n";
$emp = ['supplement_vacances' => 0.0833, 'procedure' => 'Ordinaire', 'canton' => 'Genève', 'impot_source_taux' => 0];
$c = calculer_fiche($emp, 1920.0, $taux);
check('salaire_travail', 1920.0, $c['salaire_travail']);
check('supplement_montant', 159.94, $c['supplement_montant']);
check('salaire_brut', 2079.94, $c['salaire_brut']);
check('ded_avs (5.3%)', 110.24, $c['ded_avs']);
check('ded_ac (1.06%)', 22.05, $c['ded_ac']);
check('ded_amat', 0.60, $c['ded_amat']);
check('ded_laa', 22.05, $c['ded_laa']);
check('ded_lpp (7%)', 145.60, $c['ded_lpp']);
check('ded_impot_source (pas source)', 0.0, $c['ded_impot_source']);
check('ded_caf (supprimée)', 0.0, $c['ded_caf']);
check('total_deductions', 300.54, $c['total_deductions']);
check('salaire_net', 1779.40, $c['salaire_net']);

echo "2) Charges patronales + coût total\n";
check('emp_avs', 110.24, $c['emp_avs']);
check('emp_af (2.34%)', 48.67, $c['emp_af']);
check('emp_lpp', 145.60, $c['emp_lpp']);
check('total_charges_emp', 327.99, $c['total_charges_emp']);
check('cout_total_emp (brut+charges)', 2407.93, $c['cout_total_emp']);

echo "3) Impôt à la source (10 %)\n";
$empS = ['supplement_vacances' => 0, 'procedure' => 'Ordinaire avec impôt à la source', 'canton' => 'Genève', 'impot_source_taux' => 0.10];
$cs = calculer_fiche($empS, 1000.0, $taux);
check('brut sans supplément', 1000.0, $cs['salaire_brut']);
check('impôt source 10%', 100.0, $cs['ded_impot_source']);

echo "4) Sans supplément vacances\n";
$emp0 = ['supplement_vacances' => 0, 'procedure' => 'Ordinaire', 'canton' => 'Genève', 'impot_source_taux' => 0];
$c0 = calculer_fiche($emp0, 500.0, $taux);
check('supplement_montant = 0', 0.0, $c0['supplement_montant']);
check('brut = travail', 500.0, $c0['salaire_brut']);

echo "5) CAF jamais prélevée même en Valais\n";
$empVS = ['supplement_vacances' => 0.0833, 'procedure' => 'Ordinaire', 'canton' => 'Valais', 'impot_source_taux' => 0];
$cvs = calculer_fiche($empVS, 1920.0, $taux);
check('ded_caf Valais = 0', 0.0, $cvs['ded_caf']);

echo "6) CPE + LFP (charges patronales)\n";
$tauxC = $taux + ['emp_cpe' => 0.0007, 'emp_lfp' => 0.00082];
$cc = calculer_fiche($emp0 + ['supplement_vacances' => 0], 1000.0, $tauxC);
check('emp_cpe (0.07%)', 0.70, $cc['emp_cpe']);
check('emp_lfp (0.082%)', 0.82, $cc['emp_lfp']);
// total charges inclut CPE+LFP : avs 53 + ac 11 + amat 0.29 + af 23.4 + cpe 0.70 + lfp 0.82 + lpp 70
check('total_charges_emp inclut CPE+LFP', 159.21, $cc['total_charges_emp']);

echo "7) Seuil mensuel d'heures (jours ÷ 7 × 8)\n";
check('janvier 2026 (31 j) = 35.43 h', 35.43, round(seuil_heures(2026, 1), 2));
check('février 2026 (28 j) = 32 h', 32.0, round(seuil_heures(2026, 2), 2));

echo "8) Choix LAA réduit / plein selon les heures\n";
$tauxL = ['laa_reduit' => 0.0053, 'laa_plein' => 0.0096, 'emp_laa_reduit' => 0.0053, 'emp_laa_plein' => 0.0096];
$e30 = laa_effectif($tauxL, 30.0, 2026, 1);   // 30 ≤ 35.43 → réduit
check('30 h janvier → LAA employé réduit', 0.0053, $e30['laa']);
check('30 h janvier → LAA employeur réduit', 0.0053, $e30['emp_laa']);
$e40 = laa_effectif($tauxL, 40.0, 2026, 1);   // 40 > 35.43 → plein
check('40 h janvier → LAA employé plein', 0.0096, $e40['laa']);
$eSeuil = laa_effectif($tauxL, 35.43, 2026, 1); // ≤ seuil → réduit
check('pile au seuil → réduit', 0.0053, $eSeuil['laa']);

echo "9) LPP : taux unique (7 % employé)\n";
$cl = calculer_fiche($emp0 + ['supplement_vacances' => 0], 1000.0, $taux);
check('ded_lpp 7 % sur 1000', 70.0, $cl['ded_lpp']);

echo "\n";
if ($fails === 0) {
    echo "✅ TOUS LES TESTS PASSENT ($tests assertions)\n";
    exit(0);
}
echo "❌ $fails / $tests assertions en échec\n";
exit(1);
