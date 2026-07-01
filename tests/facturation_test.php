<?php
// Tests du module facturation. Lancement : php tests/facturation_test.php
// N'utilise pas la base de l'application (fonctions pures de lib/facturation.php
// + une base SQLite en mémoire pour la numérotation).

require_once __DIR__ . '/../lib/calc.php';
require_once __DIR__ . '/../lib/facturation.php';

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

echo "1) Calcul des montants de ligne / total\n";
check('ligne 3 × 150.5', 451.5, facturation_calc_ligne(3, 150.5));
check('ligne arrondie (2 décimales)', 33.33, facturation_calc_ligne(3, 11.11));
check('total de plusieurs lignes', 581.5, facturation_calc_total([
    ['montant' => 451.5], ['montant' => 130.0],
]));
check('total sans ligne', 0.0, facturation_calc_total([]));

echo "2) Date d'échéance\n";
check('+30 jours', '2026-08-15', facturation_date_echeance('2026-07-16', 30));
check('+1 jour', '2026-01-01', facturation_date_echeance('2025-12-31', 1));

echo "3) Statut effectif (« en retard » dérivé)\n";
check('émise, échéance future → emise', 'emise', facturation_statut_effectif([
    'statut' => 'emise', 'date_echeance' => date('Y-m-d', strtotime('+10 days')),
]));
check('émise, échéance passée → en_retard', 'en_retard', facturation_statut_effectif([
    'statut' => 'emise', 'date_echeance' => date('Y-m-d', strtotime('-1 day')),
]));
check('payée, échéance passée → payee (jamais en retard)', 'payee', facturation_statut_effectif([
    'statut' => 'payee', 'date_echeance' => date('Y-m-d', strtotime('-30 days')),
]));
check('brouillon (pas d\'échéance) → brouillon', 'brouillon', facturation_statut_effectif([
    'statut' => 'brouillon', 'date_echeance' => '',
]));

echo "4) Code pays ISO 3166-1 alpha-2\n";
check('Suisse → CH', 'CH', facturation_pays_iso2('Suisse'));
check('France → FR', 'FR', facturation_pays_iso2('France'));
check('déjà un code à 2 lettres → inchangé (maj)', 'CH', facturation_pays_iso2('ch'));
check('pays inconnu → repli CH', 'CH', facturation_pays_iso2('Ruritanie'));

echo "5) Référence structurée SCOR (ISO 11649)\n";
$ref1 = facturation_generer_reference('2026-001');
check('préfixe RF', true, str_starts_with($ref1, 'RF'));
check('référence stable (même numéro → même référence)', $ref1, facturation_generer_reference('2026-001'));
check('numéros différents → références différentes', true, $ref1 !== facturation_generer_reference('2026-002'));

echo "6) Numérotation annuelle (AAAA-NNN), base en mémoire\n";
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE factures (id INTEGER PRIMARY KEY, numero TEXT)');
check('première facture de l\'année', '2026-001', facturation_prochain_numero($pdo, 2026));
$pdo->exec("INSERT INTO factures (numero) VALUES ('2026-001')");
check('deuxième facture de l\'année', '2026-002', facturation_prochain_numero($pdo, 2026));
$pdo->exec("INSERT INTO factures (numero) VALUES ('2026-002')");
check('nouvelle année → séquence repart à 1', '2027-001', facturation_prochain_numero($pdo, 2027));

// Passage au-delà de 999 : MAX() numérique (CAST), pas un tri texte sur le
// numéro complet (qui classerait à tort "2026-999" après "2026-1000").
$pdo->exec("INSERT INTO factures (numero) VALUES ('2026-999')");
check('999e facture → 1000', '2026-1000', facturation_prochain_numero($pdo, 2026));
$pdo->exec("INSERT INTO factures (numero) VALUES ('2026-1000')");
check('1000e facture → 1001 (pas 2026-1000 en boucle)', '2026-1001', facturation_prochain_numero($pdo, 2026));

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
