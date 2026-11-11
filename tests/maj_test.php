<?php
// Tests de l'installation d'une mise à jour par archive (lib/maj.php).
// Lancement : php tests/maj_test.php
//
// maj_installer_arbre() est vérifiée sur des arborescences temporaires, sans
// réseau ni base : ce qui compte, c'est qu'une mise à jour retire les fichiers
// disparus du dépôt, ne touche jamais aux données, et remette le code dans son
// état d'avant quand elle échoue en route.

require_once __DIR__ . '/../lib/maj.php';

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

function arbre(string $racine, array $fichiers): void
{
    foreach ($fichiers as $rel => $contenu) {
        @mkdir(dirname("$racine/$rel"), 0775, true);
        file_put_contents("$racine/$rel", $contenu);
    }
}

function rm_r(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                 RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        @chmod($f->getPathname(), 0775);
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

$tmp = sys_get_temp_dir() . '/lasso_maj_test_' . bin2hex(random_bytes(4));

echo "1) Chemins protégés\n";
check('data/ protégé', true, maj_chemin_protege('data/database.sqlite'));
check('uploads/ protégé', true, maj_chemin_protege('uploads/logo.png'));
check('config locale protégée', true, maj_chemin_protege('lib/config.local.php'));
check('.git/ protégé', true, maj_chemin_protege('.git/HEAD'));
check('remontée « .. » refusée', true, maj_chemin_protege('lib/../../etc/passwd'));
check('chemin absolu refusé', true, maj_chemin_protege('/etc/passwd'));
check('code ordinaire non protégé', false, maj_chemin_protege('lib/routes.php'));

echo "\n2) Installation normale avec manifeste\n";
$app = "$tmp/app";
$src = "$tmp/src";
arbre($app, [
    'index.php'             => 'ancien index',
    'lib/routes.php'        => 'anciennes routes',
    'lib/retiree.php'       => 'route supprimée en amont',
    'lib/vieux/seul.php'    => 'dossier qui disparaît',
    'lib/config.local.php'  => 'secrets',
    'data/database.sqlite'  => 'base',
    'uploads/logo.png'      => 'logo',
    'perso.txt'             => 'posé à la main',
]);
arbre($src, [
    'index.php'      => 'nouvel index',
    'lib/routes.php' => 'nouvelles routes',
    'lib/neuve.php'  => 'route ajoutée',
    'VERSION'        => '9.9.9',
]);
// Manifeste de l'installation précédente — y compris, pour l'exercice, des
// chemins protégés qui ne doivent jamais être supprimés même s'ils y figurent.
$ancien = ['index.php', 'lib/routes.php', 'lib/retiree.php', 'lib/vieux/seul.php',
           'lib/config.local.php', 'data/database.sqlite', 'uploads/logo.png'];
$r = maj_installer_arbre($src, $app, $ancien, "$tmp/secours1");
check('fichier mis à jour', 'nouvel index', file_get_contents("$app/index.php"));
check('fichier ajouté', 'route ajoutée', @file_get_contents("$app/lib/neuve.php"));
check('fichier retiré en amont supprimé', false, is_file("$app/lib/retiree.php"));
check('dossier devenu vide retiré', false, is_dir("$app/lib/vieux"));
check('config locale intacte', 'secrets', @file_get_contents("$app/lib/config.local.php"));
check('base intacte', 'base', @file_get_contents("$app/data/database.sqlite"));
check('uploads intacts', 'logo', @file_get_contents("$app/uploads/logo.png"));
check('fichier hors manifeste intact', 'posé à la main', @file_get_contents("$app/perso.txt"));
check('deux suppressions comptées', 2, $r['supprimes']);
check('nouveau manifeste = contenu de l\'archive', ['VERSION', 'index.php', 'lib/neuve.php', 'lib/routes.php'], $r['manifeste']);

echo "\n3) Sans manifeste : rien n'est supprimé\n";
arbre($app, ['lib/orpheline.php' => 'inconnue']);
$r = maj_installer_arbre($src, $app, null, "$tmp/secours2");
check('fichier inconnu conservé', true, is_file("$app/lib/orpheline.php"));
check('aucune suppression', 0, $r['supprimes']);

echo "\n4) Échec en route : retour arrière complet\n";
rm_r($app);
rm_r($src);
arbre($app, [
    'a.php'      => 'A ancien',
    'b.php'      => 'B ancien',
    'retire.php' => 'à retirer',
    'z/garde.txt' => 'z',
]);
arbre($src, [
    'a.php'     => 'A nouveau',
    'b.php'     => 'B nouveau',
    'c.php'     => 'C nouveau',
    'z/neuf.txt' => 'impossible à écrire',
]);
chmod("$app/z", 0555); // le dernier fichier de l'archive ne pourra pas être créé
if (is_writable("$app/z")) {
    echo "  (ignoré : processus root, les droits ne bloquent pas l'écriture)\n";
} else {
    $message = '';
    try {
        maj_installer_arbre($src, $app, ['a.php', 'b.php', 'retire.php', 'z/garde.txt'], "$tmp/secours3");
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }
    check('l\'échec remonte', true, str_contains($message, 'Copie impossible : z/neuf.txt'));
    check('le message annonce le retour arrière', true, str_contains($message, 'remis dans son état d\'avant'));
    check('a.php restauré', 'A ancien', file_get_contents("$app/a.php"));
    check('b.php restauré', 'B ancien', file_get_contents("$app/b.php"));
    check('c.php (nouveau) retiré', false, is_file("$app/c.php"));
    check('fichier à retirer toujours là', 'à retirer', @file_get_contents("$app/retire.php"));
}
chmod("$app/z", 0775);

rm_r($tmp);

echo "\n5) Canal par défaut\n";
check('stable par défaut', 'stable', MAJ_CANAL_DEFAUT);

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
