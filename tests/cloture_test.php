<?php
// Clôture d'exercice comptable. Lancement : php tests/cloture_test.php
//
// Le verrou est tenu par des déclencheurs SQLite (migration_104), pas par les
// routes : ce fichier vérifie donc LA BASE, en écrivant directement — comme le
// ferait une route oubliée, une cascade ou un script. Puis il vérifie que les
// deux chemins qui parcourent large (import, lettrage par règles) écartent
// d'eux-mêmes les années closes au lieu de buter sur le verrou.

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/lasso_cloture_' . bin2hex(random_bytes(6)) . '.sqlite';
define('APP_DB_PATH', $tmp);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/modules.php';
require_once __DIR__ . '/../lib/compta.php';
require_once __DIR__ . '/../lib/routes_compta.php';

$tests = 0;
$fails = 0;
function check(string $label, $attendu, $obtenu): void
{
    global $tests, $fails;
    $tests++;
    if ($attendu === $obtenu) {
        printf("  ok    %s\n", $label);
        return;
    }
    $fails++;
    printf("  FAIL  %s\n        attendu %s\n        obtenu  %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
}

// Vrai si l'instruction est refusée PAR LE VERROU (et non pour une autre raison).
function refuse(callable $f): bool
{
    try {
        $f();
        return false;
    } catch (PDOException $e) {
        return compta_est_erreur_cloture($e);
    }
}

$pdo = db();
$pdo->exec("INSERT INTO comptes_bancaires (id, libelle, iban) VALUES (1, 'Compte test', 'CH00TEST')");
$pdo->exec("INSERT INTO plan_comptes (id, libelle) VALUES (901, 'Cat A'), (902, 'Cat B')");
$ins = $pdo->prepare("INSERT INTO ecritures (id, compte_bancaire_id, date_op, texte, montant, plan_compte_id, hash) VALUES (?, 1, ?, ?, ?, 901, ?)");
$ins->execute([1, '2024-06-30', 'Loyer juin', -1000, 'h1']);
$ins->execute([2, '2025-03-01', 'Loyer mars', -1000, 'h2']);
$clore = fn (string $v) => $pdo->prepare("INSERT OR REPLACE INTO parametres (cle, valeur) VALUES ('compta_cloture_annee', ?)")->execute([$v]);

echo "1) Sans clôture, rien n'est verrouillé\n";
check('les cinq déclencheurs sont posés', 5, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name LIKE '%cloture%'")->fetchColumn());
check('modifier 2024 passe', false, refuse(fn () => $pdo->exec("UPDATE ecritures SET montant = -900 WHERE id = 1")));
$pdo->exec("UPDATE ecritures SET montant = -1000 WHERE id = 1");

echo "\n2) 2024 clôturé : la base refuse tout ce qui change les comptes\n";
$clore('2024');
check('modifier le montant', true, refuse(fn () => $pdo->exec("UPDATE ecritures SET montant = -900 WHERE id = 1")));
check('changer la catégorie', true, refuse(fn () => $pdo->exec("UPDATE ecritures SET plan_compte_id = 902 WHERE id = 1")));
check('déplacer la date vers 2025', true, refuse(fn () => $pdo->exec("UPDATE ecritures SET date_op = '2025-01-01' WHERE id = 1")));
check('déplacer une date 2025 vers 2024', true, refuse(fn () => $pdo->exec("UPDATE ecritures SET date_op = '2024-12-31' WHERE id = 2")));
check('supprimer', true, refuse(fn () => $pdo->exec("DELETE FROM ecritures WHERE id = 1")));
check('ajouter une écriture datée 2024', true, refuse(fn () => $pdo->exec("INSERT INTO ecritures (compte_bancaire_id, date_op, texte, montant, hash) VALUES (1, '2024-12-01', 'x', 1, 'h9')")));
check('supprimer la catégorie (cascade SET NULL)', true, refuse(fn () => $pdo->exec("DELETE FROM plan_comptes WHERE id = 901")));
check('supprimer le compte bancaire (cascade)', true, refuse(fn () => $pdo->exec("DELETE FROM comptes_bancaires WHERE id = 1")));
check('l\'écriture 2024 est intacte', -1000.0, (float) $pdo->query('SELECT montant FROM ecritures WHERE id = 1')->fetchColumn());

echo "\n3) Ce qui ne change aucun montant reste libre\n";
check('la contre-partie', false, refuse(fn () => $pdo->exec("UPDATE ecritures SET tiers = 'Régie' WHERE id = 1")));
check('la dispense de justificatif', false, refuse(fn () => $pdo->exec("UPDATE ecritures SET piece_dispense_motif = 'abonnement' WHERE id = 1")));
check('un « UPDATE » qui réécrit la même valeur', false, refuse(fn () => $pdo->exec("UPDATE ecritures SET montant = -1000 WHERE id = 1")));
check('2025 reste modifiable', false, refuse(fn () => $pdo->exec("UPDATE ecritures SET montant = -1100 WHERE id = 2")));

echo "\n4) Les chemins larges écartent les années closes d'eux-mêmes\n";
check('compta_annee_close(2024)', true, compta_annee_close(2024));
check('compta_annee_close(2025)', false, compta_annee_close(2025));
$pdo->exec("INSERT INTO regles_lettrage (motif, type_match, plan_compte_id, actif) VALUES ('Loyer', 'contient', 902, 1)");
$pdo->exec("UPDATE ecritures SET origine_lettrage = '' ");
check('le lettrage par règles ne lève pas', false, refuse(fn () => compta_lettrer_par_regles(null, null)));
// La règle s'applique bien : sans quoi le contrôle suivant passerait pour rien.
check('… mais recatégorise 2025', 902, (int) $pdo->query('SELECT plan_compte_id FROM ecritures WHERE id = 2')->fetchColumn());
check('… et ne recatégorise pas 2024', 901, (int) $pdo->query('SELECT plan_compte_id FROM ecritures WHERE id = 1')->fetchColumn());
$parse = ['lignes' => [
    ['date_op' => '2024-12-15', 'texte' => 'Décembre clos', 'montant' => -50.0, 'solde' => null],
    ['date_op' => '2025-04-02', 'texte' => 'Avril ouvert', 'montant' => -60.0, 'solde' => null],
], 'date_debut' => '2024-12-15', 'date_fin' => '2025-04-02', 'source' => 'csv'];
[$nIns, $nDup] = compta_inserer_ecritures(['id' => 1, 'banque' => ''], $parse, 'releve.csv');
check('l\'import n\'insère que la ligne de 2025', 1, $nIns);
check('… et compte l\'autre comme déjà là', 1, $nDup);

echo "\n5) Rouvrir lève le verrou\n";
$clore('2023');
check('2024 se modifie de nouveau', false, refuse(fn () => $pdo->exec("UPDATE ecritures SET montant = -900 WHERE id = 1")));

@unlink($tmp);
foreach (glob($tmp . '-*') ?: [] as $f) {
    @unlink($f);
}
echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
