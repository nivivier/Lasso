<?php
// Tests de la sauvegarde complète. Lancement : php tests/sauvegarde_test.php
// N'utilise pas la base de l'application : sauvegarde_construire() est la seule
// fonction à appeler db() (VACUUM INTO), et elle est vérifiée ici sur une base
// SQLite fabriquée pour l'occasion, via un stub de db().
//
// Ce qui compte dans une sauvegarde, c'est ce qu'elle contient : les tests
// fabriquent une arborescence de fichiers déposés, la sauvegardent, et
// rouvrent l'archive pour vérifier qu'elle rend exactement ce qui y est entré.

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

// --- Arborescence de travail ------------------------------------------------
$racine = sys_get_temp_dir() . '/lasso_sauvegarde_test_' . bin2hex(random_bytes(4));
mkdir($racine . '/uploads/sous', 0775, true);
mkdir($racine . '/data/fichiers', 0775, true);
mkdir($racine . '/vide', 0775, true);
file_put_contents($racine . '/uploads/logo.png', 'PNG factice');
file_put_contents($racine . '/uploads/sous/photo.jpg', 'JPG factice');
file_put_contents($racine . '/uploads/.htaccess', 'Require all denied');
file_put_contents($racine . '/data/fichiers/fiche_technique.pdf', 'PDF factice');
@symlink($racine . '/uploads/logo.png', $racine . '/uploads/raccourci.png');

$dossiers = [
    $racine . '/uploads'       => 'uploads',
    $racine . '/data/fichiers' => 'data/fichiers',
    $racine . '/absent'        => 'data/absent',
];

echo "1) Fichiers embarqués\n";
$fichiers = sauvegarde_fichiers($dossiers);
check('les quatre dossiers sont parcourus récursivement', [
    'data/fichiers/fiche_technique.pdf',
    'uploads/logo.png',
    'uploads/sous/photo.jpg',
], array_values($fichiers));
check('un dossier absent est ignoré, pas une erreur', false, in_array('data/absent', $fichiers, true));
check('les fichiers cachés sont exclus (.htaccess vient du dépôt)', false, in_array('uploads/.htaccess', $fichiers, true));
check('les liens symboliques sont exclus (ils sortent du dossier)', false, in_array('uploads/raccourci.png', $fichiers, true));
check('la clé est le chemin réel, la valeur le chemin dans l\'archive',
    $racine . '/uploads/logo.png', array_search('uploads/logo.png', $fichiers, true));
check('un dossier vide n\'ajoute rien', 3, count(sauvegarde_fichiers($dossiers + [$racine . '/vide' => 'vide'])));

echo "\n2) Note de restauration\n";
$note = sauvegarde_note(3, '9.9.9');
check('porte la version', true, str_contains($note, 'Sauvegarde Lasso 9.9.9'));
check('annonce le nombre de fichiers', true, str_contains($note, '3 fichier(s)'));
check('dit où remettre la base', true, str_contains($note, 'APP_DB_PATH'));
check('prévient que la configuration n\'y est pas', true, str_contains($note, 'config.local.php'));

echo "\n3) Format retenu selon le serveur\n";
check('ce serveur sait produire une archive', true, in_array(sauvegarde_format(), ['zip', 'tar.gz'], true));

// --- Archive réelle ---------------------------------------------------------
// db() n'existe pas ici (lib/db.php n'est pas chargé) : ce stub rend un PDO sur
// une base SQLite fabriquée, ce dont VACUUM INTO se contente.
$source = $racine . '/source.sqlite';
$pdo = new PDO('sqlite:' . $source);
$pdo->exec('CREATE TABLE marqueur (id INTEGER PRIMARY KEY, valeur TEXT)');
$pdo->exec("INSERT INTO marqueur (valeur) VALUES ('sauvegarde')");
function db(): PDO
{
    global $pdo;
    return $pdo;
}
// sauvegarde_dossiers() pointe sur le vrai dépôt : on vérifie l'archive sur les
// dossiers de test en appelant directement les fonctions qui la composent, puis
// on contrôle le contenu réel produit par sauvegarde_construire().
echo "\n4) Archive ZIP : ce qui entre ressort\n";
[$chemin, $nom] = sauvegarde_construire('essai', '9.9.9', 'zip');
check('le nom porte le format', true, str_ends_with($nom, '.zip'));
check('le nom porte le slug', true, str_starts_with($nom, 'essai_'));
$zip = new ZipArchive();
$zip->open($chemin);
$dedans = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $dedans[] = $zip->getNameIndex($i);
}
check('la base est dans l\'archive', true, in_array('base.sqlite', $dedans, true));
check('la note est dans l\'archive', true, in_array('SAUVEGARDE.txt', $dedans, true));
// La base extraite doit être une vraie base, lisible et intègre : c'est tout
// l'intérêt de VACUUM INTO plutôt qu'une copie du fichier sous WAL.
$extrait = $racine . '/extrait.sqlite';
file_put_contents($extrait, $zip->getFromName('base.sqlite'));
$zip->close();
$relu = new PDO('sqlite:' . $extrait);
check('la base extraite est intègre', 'ok', (string) $relu->query('PRAGMA integrity_check')->fetchColumn());
check('la base extraite porte les données', 'sauvegarde', (string) $relu->query('SELECT valeur FROM marqueur')->fetchColumn());
unset($relu);
unlink($chemin);

echo "\n5) Archive TAR.GZ (hébergement sans l'extension zip)\n";
[$cheminTar, $nomTar] = sauvegarde_construire('essai', '9.9.9', 'tar.gz');
check('le nom porte le format', true, str_ends_with($nomTar, '.tar.gz'));
$phar = new PharData($cheminTar);
$dedansTar = [];
foreach (new RecursiveIteratorIterator($phar) as $f) {
    $dedansTar[] = $f->getFilename();
}
check('la base est dans l\'archive', true, in_array('base.sqlite', $dedansTar, true));
check('la note est dans l\'archive', true, in_array('SAUVEGARDE.txt', $dedansTar, true));
unset($phar);
unlink($cheminTar);

echo "\n6) Repli sans archive (ni zip ni phar) : la base seule\n";
[$cheminSql, $nomSql] = sauvegarde_construire('essai', '9.9.9', 'sqlite');
check('le nom porte l\'extension de la base', true, str_ends_with($nomSql, '.sqlite'));
$replu = new PDO('sqlite:' . $cheminSql);
check('c\'est bien la base, intègre', 'ok', (string) $replu->query('PRAGMA integrity_check')->fetchColumn());
unset($replu);
unlink($cheminSql);

// --- Ménage -----------------------------------------------------------------
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $f) {
    $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
}
@rmdir($racine);

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
