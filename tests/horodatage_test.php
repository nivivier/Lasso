<?php
// Horodatages : UTC en base, heure locale à l'affichage (lib/horodatage.php,
// docs/DECISIONS.md § Horodatages). Lancement :
// php tests/horodatage_test.php
//
// Deux horloges cohabitaient : SQLite écrit en UTC (datetime('now'), défaut de
// toutes les colonnes horodatées), PHP en heure locale (date()). Relue avec
// strtotime(), une valeur écrite par SQLite s'affichait avec une heure de
// retard — deux en été. Ce test fixe la convention, puis vérifie dans le code
// qu'on ne la contourne plus : aucune écriture par date(), aucune lecture
// d'une colonne horodatée par strtotime() seul.

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/lasso_horodatage_' . bin2hex(random_bytes(6)) . '.sqlite';
define('APP_DB_PATH', $tmp);
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

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

echo "1) Le fuseau de l'application\n";
check('fixé par lib/config.php', APP_FUSEAU, date_default_timezone_get());

echo "\n2) Lire une valeur de la base\n";
check('UTC sans fuseau → heure de Genève (hiver, +1)', '15.01.2026 13:30', horodatage('2026-01-15 12:30:00'));
check('… (été, +2)', '15.07.2026 14:30', horodatage('2026-07-15 12:30:00'));
check('une valeur qui porte son décalage se lit telle quelle', '15.01.2026 12:30', horodatage('2026-01-15T12:30:00+01:00'));
check('… « Z » aussi', '15.01.2026 13:30', horodatage('2026-01-15T12:30:00Z'));
check('format demandé', '15.01.2026', horodatage('2026-01-15 12:30:00', 'd.m.Y'));
check('vide → vide', '', horodatage(''));
check('null → vide', '', horodatage(null));
check('illisible → vide, pas une erreur', '', horodatage('n\'importe quoi'));
check('horodatage_ts : null si vide', null, horodatage_ts(' '));

echo "\n3) SQLite et PHP disent la même heure\n";
$sqlite = (string) db()->query("SELECT datetime('now')")->fetchColumn();
check('datetime(\'now\') relu = maintenant (à 5 s près)', true, abs((int) horodatage_ts($sqlite) - time()) <= 5);
check('horodatage_maintenant() relu = maintenant', true, abs((int) horodatage_ts(horodatage_maintenant()) - time()) <= 5);
check('… et au format de SQLite', 1, preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', horodatage_maintenant()));

echo "\n4) Le code tient la convention\n";
$racine = dirname(__DIR__);
$sources = array_merge(
    glob($racine . '/lib/*.php') ?: [], glob($racine . '/lib/*/*.php') ?: [],
    glob($racine . '/views/*.php') ?: [], glob($racine . '/scripts/*.php') ?: [], [$racine . '/index.php']
);
$ecrituresLocales = [];
$lecturesNues = [];
// Colonnes DATES (un jour, sans heure) : strtotime() y est juste.
$datesSeules = ['dernier_contact_le', 'mise_a_jour_le', 'payee_le', 'suisa_envoye_le', 'suisa_decompte_le'];
foreach ($sources as $f) {
    if (str_contains($f, '/migrations/')) {
        continue; // l'histoire du schéma, rejouée telle quelle
    }
    $rel = substr($f, strlen($racine) + 1);
    foreach (file($f) ?: [] as $n => $ligne) {
        if (preg_match("/(?<!gm)date\\('Y-m-d H:i(:s)?'\\s*[,)]/", $ligne)) {
            $ecrituresLocales[] = $rel . ':' . ($n + 1);
        }
        if (preg_match_all("/strtotime\\([^;]*?\\['([a-z_]+_le)'\\]/", $ligne, $m)) {
            foreach ($m[1] as $col) {
                if (!in_array($col, $datesSeules, true)) {
                    $lecturesNues[] = $rel . ':' . ($n + 1) . ' (' . $col . ')';
                }
            }
        }
    }
}
check('aucun horodatage écrit avec date() — horodatage_maintenant()', [], $ecrituresLocales);
check('aucune colonne horodatée relue par strtotime() seul — horodatage()', [], $lecturesNues);

@unlink($tmp);
foreach (glob($tmp . '*') ?: [] as $f) {
    @unlink($f);
}
echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
