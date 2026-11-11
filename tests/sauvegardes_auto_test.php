<?php
// Tests de la rotation des sauvegardes automatiques et du rappel de
// sauvegarde du tableau de bord. Lancement : php tests/sauvegardes_auto_test.php
//
// Base fictive dans un dossier temporaire : la purge ne doit toucher QUE les
// copies automatiques de cette base-là, jamais la base elle-même ni les
// fichiers d'une autre base rangée dans le même dossier.

$dossier = sys_get_temp_dir() . '/lasso_sauv_auto_' . bin2hex(random_bytes(4));
mkdir($dossier, 0775, true);
define('APP_DB_PATH', $dossier . '/database.sqlite');
define('SAUVEGARDES_AUTO_MAX', 3);
define('SAUVEGARDE_RAPPEL_JOURS', 30);
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/sauvegarde.php';

$tests = 0;
$fails = 0;
function check(string $label, $attendu, $obtenu): void
{
    global $tests, $fails;
    $tests++;
    if ($attendu !== $obtenu) {
        $fails++;
        printf("  FAIL  %-56s attendu %s, obtenu %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
    } else {
        printf("  ok    %s\n", $label);
    }
}

echo "1) Rotation des copies automatiques\n";
file_put_contents(APP_DB_PATH, 'base');
file_put_contents($dossier . '/autre_import_20200101_000000.sqlite.bak', 'autre base');
file_put_contents($dossier . '/notes.txt', 'à garder');
$noms = [];
for ($i = 1; $i <= 6; $i++) {
    $f = sprintf('%s/database_etape%d_2026010%d_120000.sqlite.bak', $dossier, $i, $i);
    file_put_contents($f, "copie $i");
    touch($f, 1_700_000_000 + $i * 3600); // de la plus ancienne (1) à la plus récente (6)
    $noms[$i] = $f;
}
// La plus ancienne est celle qu'on vient de créer : elle doit survivre.
check('trois supprimées', 3, sauvegardes_auto_purger($noms[1]));
check('la plus récente gardée', true, is_file($noms[6]));
check('la deuxième plus récente gardée', true, is_file($noms[5]));
check('la troisième plus récente supprimée…', false, is_file($noms[4]));
check('…car la copie à garder prend sa place', true, is_file($noms[1]));
check('les anciennes supprimées', [false, false], [is_file($noms[2]), is_file($noms[3])]);
check('la base elle-même intacte', 'base', file_get_contents(APP_DB_PATH));
check('copie d\'une autre base intacte', true, is_file($dossier . '/autre_import_20200101_000000.sqlite.bak'));
check('fichier quelconque intact', true, is_file($dossier . '/notes.txt'));
check('sous le plafond : rien à supprimer', 0, sauvegardes_auto_purger());

echo "\n2) Rappel de sauvegarde\n";
$maintenant = strtotime('2026-11-11 10:00:00');
check('jamais téléchargée → rappel', ['jours' => null], sauvegarde_rappel('', $maintenant));
check('date illisible → rappel', ['jours' => null], sauvegarde_rappel('n\'importe quoi', $maintenant));
check('hier → pas de rappel', null, sauvegarde_rappel('2026-11-10 09:00:00', $maintenant));
check('pile 30 jours → pas encore', null, sauvegarde_rappel('2026-10-12 10:00:00', $maintenant));
check('45 jours → rappel', ['jours' => 45], sauvegarde_rappel('2026-09-27 10:00:00', $maintenant));

foreach (glob($dossier . '/*') as $f) {
    unlink($f);
}
rmdir($dossier);

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
