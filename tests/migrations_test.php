<?php
// Tests du schéma et de la chaîne de migrations. Lancement :
//   php tests/migrations_test.php
//
// Travaille sur une base TEMPORAIRE créée pour l'occasion et supprimée à la
// fin : ne touche jamais data/database.sqlite. C'est APP_DB_PATH qui est
// redéfinie avant de charger lib/config.php — la constante n'est posée par la
// configuration que si elle ne l'est pas déjà.
//
// Ce que ces tests protègent :
//  1. la chaîne complète des migrations se déroule sur une base vide ;
//  2. aucune clé étrangère cassée à l'arrivée. lib/db.php documente un piège
//     SQLite vérifié empiriquement : « PRAGMA foreign_keys = OFF » n'empêche
//     PAS SQLite de réécrire les clauses REFERENCES des autres tables lors
//     d'un ALTER TABLE ... RENAME, ce qui laisse des FK pointant vers une
//     table temporaire une fois celle-ci supprimée. Ce savoir ne vivait que
//     dans un commentaire ; il est désormais vérifié à chaque exécution ;
//  3. les migrations sont idempotentes — les rejouer sur une base déjà à jour
//     ne doit rien casser. C'est la promesse explicite du mécanisme, et la
//     condition pour pouvoir réorganiser lib/db.php sans risque.

declare(strict_types=1);

// Seule APP_DB_PATH est imposée : elle suffit à détourner toute l'application
// vers la base temporaire. APP_ENV est délibérément laissée à la configuration
// (lib/config.local.php en local, sinon PHP_SAPI « cli » → dev) — la définir
// ici déclencherait un « Constant already defined » sur les installations dont
// le config.local.php la pose sans garde.
$tmp = sys_get_temp_dir() . '/lasso_migrations_' . bin2hex(random_bytes(6)) . '.sqlite';
define('APP_DB_PATH', $tmp);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/calc.php';
require_once __DIR__ . '/../lib/db.php';

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
    printf("  FAIL  %-52s attendu %s, obtenu %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
}

// Nettoyage systématique, y compris si une exception interrompt le fichier —
// sans quoi chaque exécution ratée laisserait une base derrière elle.
register_shutdown_function(function () use ($tmp) {
    foreach ([$tmp, $tmp . '-wal', $tmp . '-shm'] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
    @unlink(dirname($tmp) . '/.htaccess');
});

echo "1) Création du schéma sur une base vierge\n";
$pdo = db(); // crée le fichier, joue init_schema() puis run_migrations()
check('base temporaire créée', true, is_file($tmp));

$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
    ->fetchAll(PDO::FETCH_COLUMN);
check('un nombre réaliste de tables (> 40)', true, count($tables) > 40);
foreach (['utilisateurs', 'employes', 'fiches', 'parametres', 'login_attempts'] as $t) {
    check("table « $t » présente", true, in_array($t, $tables, true));
}

echo "2) Version de schéma atteinte\n";
$version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
check('user_version > 0 après migrations', true, $version > 0);
// La version atteinte doit être exactement le plus grand migration_N() défini.
// Déduite des fonctions réellement chargées plutôt que du tableau $steps lu
// dans le source : la vérification survit ainsi à un déplacement de fichier
// (elle a d'ailleurs cassé au premier essai, quand les migrations ont quitté
// lib/db.php), et elle est plus exigeante — une migration écrite mais oubliée
// dans $steps ne serait jamais jouée, ce que ce contrôle révèle.
$numeros = [];
foreach (get_defined_functions()['user'] as $fn) {
    if (preg_match('/^migration_(\d+)$/', $fn, $mm)) {
        $numeros[] = (int) $mm[1];
    }
}
$dernier = $numeros ? max($numeros) : 0;
check('au moins une migration définie', true, $dernier > 0);
check('toutes les étapes jouées (user_version == dernier migration_N défini)', $dernier, $version);

echo "3) Intégrité des clés étrangères\n";
$fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
if ($fk) {
    foreach (array_slice($fk, 0, 10) as $x) {
        printf("        FK cassée : table %s -> %s\n", $x[0] ?? '?', $x[2] ?? '?');
    }
}
check('aucune clé étrangère cassée', 0, count($fk));

$integrite = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
check('integrity_check', 'ok', $integrite);

echo "4) Rejeu sur une base à jour — le chemin emprunté à CHAQUE requête\n";
// db() appelle run_migrations() sur toutes les requêtes : le cas de loin le
// plus fréquent est une base déjà au maximum, sur laquelle l'appel doit être
// un non-événement (aucune étape rejouée, aucune écriture, aucune exception).
//
// Note sur ce qui n'est PAS testé ici : remettre user_version à 0 sur une base
// complète pour tout rejouer échouerait — et à juste titre. Une étape ancienne
// (17) s'exécuterait alors contre un schéma en version 68, où les colonnes
// qu'elle manipule ont été supprimées ou renommées par des étapes ULTÉRIEURES
// (debiteur_id, devenu structure_id). Cet état n'existe pas : une base est
// toujours à une version donnée et n'avance que vers l'avant. L'idempotence
// que promet lib/db.php est celle d'une étape rejouée sur une base à SA
// version, pas celle d'une étape rejouée hors de son époque.
$erreur = null;
try {
    run_migrations($pdo);
} catch (\Throwable $e) {
    $erreur = $e->getMessage();
}
check('rejeu sur base à jour, sans exception', null, $erreur);
check('user_version inchangée', $dernier, (int) $pdo->query('PRAGMA user_version')->fetchColumn());

// Deuxième ouverture complète : init_schema() + run_migrations() rejoués depuis
// zéro sur le MÊME fichier, comme à chaque démarrage de l'application. Toutes
// les créations sont en CREATE TABLE IF NOT EXISTS, rien ne doit bouger.
$erreur2 = null;
try {
    init_schema($pdo);
    run_migrations($pdo);
} catch (\Throwable $e) {
    $erreur2 = $e->getMessage();
}
check('init_schema() rejoué sur une base existante, sans exception', null, $erreur2);

$fk2 = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
check('toujours aucune clé étrangère cassée après rejeu', 0, count($fk2));

$tables2 = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
    ->fetchAll(PDO::FETCH_COLUMN);
sort($tables);
sort($tables2);
check('aucune table perdue ni créée en double par le rejeu', $tables, $tables2);

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
